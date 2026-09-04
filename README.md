# DeployEcommerce_Inbox

An admin inbox for messages posted by integrations and other modules. It gives
administrators one place to see that an IntegrationErp sync failed, an import finished with
errors, or an API rejected a request — without reading log files over SSH.

## Installation

```bash
composer require deployecommerce/module-inbox
bin/magento module:enable DeployEcommerce_Inbox
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
```

`setup:upgrade` is required, not optional. It creates the module's tables and registers the
outbound queue; without it, queued messages are written and then silently orphaned.

Requires PHP 8.3 or later and Magento 2.4.8. Tested against Magento Commerce 2.4.8-p5 on
MariaDB 10.6.


## What this is not

**This mechanism runs entirely alongside Magento's logging. It is not a log handler.**

- Nothing written to `var/log/*.log` appears in the inbox.
- No Monolog handler forwards PSR-3 records into the inbox.
- Calling `$this->logger->error()` does **not** create an inbox message.

Messages appear in the inbox only because code explicitly called the inbox. That is
deliberate: the inbox is a curated, admin-facing channel, and piping a general log into
it would bury the messages that need a human. Keep using the logger for diagnostics, and
use the inbox for things an administrator must actually see.

The two are complementary — a typical integration writes both:

```php
$this->logger->error('IntegrationErp price fetch failed', ['exception' => $e]);   // diagnostics
$this->inbox->critical('IntegrationErp price fetch failed', $e->getMessage(), 'integration_erp'); // admin-facing
```

## Logging a message

Two call styles are supported. They share a single implementation, so behaviour is
identical either way.

### Dependency injection (preferred)

Inject `InboxWriterInterface` wherever a constructor is available. This is the style to
use in all new code: dependencies stay explicit and the class remains unit-testable.

```php
<?php
declare(strict_types=1);

namespace Vendor\IntegrationErp\Cron;

use DeployEcommerce\Inbox\Api\InboxWriterInterface;
use DeployEcommerce\Inbox\Model\Severity;

class SyncAll
{
    public function __construct(
        private readonly InboxWriterInterface $inbox
    ) {
    }

    public function execute(): void
    {
        try {
            $this->doSync();
        } catch (\Throwable $e) {
            $this->inbox->critical(
                'IntegrationErp price sync aborted',
                $e->getMessage() . "\n\n" . $e->getTraceAsString(),
                'integration_erp_pricing',
                ['integration_erp', 'api', 'integration']
            );
        }
    }
}
```

The generic form, when severity is decided at runtime:

```php
$this->inbox->log(
    title: 'Product import finished with errors',
    severity: Severity::Warning,   // or 'warning', or 300
    body: $report,
    source: 'product_import',
    tags: ['import', 'catalog'],
);
```

### Global helper

For call sites where constructor injection is impractical — static utilities, exception
and shutdown handlers, setup scripts, and legacy classes that cannot take a new
constructor argument — use the static facade:

```php
use DeployEcommerce\Inbox\Inbox;

Inbox::critical(
    'IntegrationErp order push failed',
    $exception->getMessage(),
    'integration_erp',
    ['integration_erp', 'orders', 'integration']
);
```

Matching the generic form:

```php
Inbox::log('Short message', 'critical', $longBody, 'source name', ['integration_erp', 'api']);
```

Prefer DI where you can. The facade is a deliberate service locator: convenient, but it
hides the dependency and makes the *calling* class harder to test.

## Signature

Both styles take the same arguments:

```php
log(
    string $title,                            // required, truncated to 500 chars
    Severity|string|int $severity = Severity::Info,
    ?string $body = null,
    string $source = 'unknown',
    array $tags = [],
    bool $neverDelete = false
)
```

Severity shorthands exist for all eight levels and read better than `log()`:
`emergency()`, `alert()`, `critical()`, `error()`, `warning()`, `notice()`, `info()`,
`debug()`.

| Argument | Notes |
|---|---|
| `$title` | Keep it **stable across occurrences**. Deduplication, when enabled, hashes the title, so put order numbers, ids and timestamps in `$body` instead. Over-long titles are truncated, never rejected; the full text is preserved at the top of the body. |
| `$severity` | Accepts `Severity::Critical`, `'critical'` (any case), or the Monolog integer `500`. Unrecognised values fall back to `Error` and log a warning — a write is never rejected over a bad severity. |
| `$body` | Optional. Redacted and size-capped before storage. |
| `$source` | **Name your integration.** Leaving it as `'unknown'` defeats grid filtering, per-source retention and deduplication all at once. |
| `$tags` | Lowercased, stripped to `[a-z0-9_-]`, capped at 32 chars each and 10 per message. |
| `$neverDelete` | Exempts the message from every retention rule, permanently. Use the named form: `neverDelete: true`. |

### Retaining a message permanently

```php
Inbox::critical(
    'Order push failed and was never retried',
    $payload,
    'integration_erp',
    ['integration_erp', 'orders'],
    neverDelete: true
);
```

`neverDelete` is a **separate argument, not a tag.** Passing it inside the `$tags` array
does not work — retention is a column, and a boolean in a string array would be stored as
an empty tag. The tag normaliser detects this mistake and logs a warning.

Reserve it for genuinely irreversible events, not for "this seems important". Pinned
messages are never purged, so they accumulate forever; administrators can see the pinned
count in the configuration section and unpin from the grid.

## Deduplication (optional, off by default)

When enabled, repeats of the same message — same source, same severity, same normalised
title — increment an occurrence counter on the existing row instead of inserting a new
one, collapsing a failure storm into a single row.

It ships **disabled**, so by default every call inserts its own row. Turn it on under
**Admin Inbox > Deduplication** if an integration starts flooding the grid. Note that
while it is off, retention settings are the only thing bounding table growth: an
integration failing every minute for three days writes ~4,300 rows.

Deduplication only works if titles are stable — see the `$title` note above.

## Guarantees

- **A write never throws.** Callers are usually reporting a failure they already caught.
  If the reporting threw, an observable error would become an unobservable crash. Every
  failure is swallowed and recorded in the module's own log file instead.
- **A write never blocks on the network.** Outbound forwarding is queued, never sent
  during the call.
- **Writes survive a caller's rollback.** If the write happens inside an open transaction
  that later rolls back, the message is buffered and flushed afterwards, so the record of
  the failure is not lost along with the failure.
- Every message is also mirrored to the module's log file, so the inbox is never the only
  copy.

## Forwarding messages to an endpoint

The inbox can POST qualifying messages to an HTTP endpoint, for routing into Slack,
PagerDuty or a monitoring system. Configure it under **Admin Inbox > Outbound Forwarding**.

Delivery is queued and happens in the background. Recording a message never waits on the
network, and a slow or dead endpoint cannot slow down the integration that reported the
problem.

**Message bodies are not sent by default.** Bodies carry third-party API payloads that
often contain personal data, so forwarding them makes the endpoint operator a data
processor and puts the content under a retention policy this site does not control.
Metadata — severity, source, title, tags, counts, timestamps — is enough to route an alert.
Turn bodies on deliberately, or not at all.

### Endpoint requirements

- `https` on port 443, to a hostname that resolves to a public address. Plain http,
  IP-literal hosts, credentials in the URL, other ports and private, loopback or
  cloud-metadata addresses are all refused, at save time and again before every request.
- Redirects are not followed. A `3xx` is treated as a failed delivery, so publish a stable
  URL.

### Verifying a delivery

Every request carries an HMAC-SHA256 signature over the timestamp and the exact body:

```
X-DE-Inbox-Timestamp: 1756876800
X-DE-Inbox-Signature: v1=<hmac_sha256(secret, timestamp . "." . rawBody)>
X-DE-Inbox-Delivery:  <uuid, stable across retries of the same message>
X-DE-Inbox-Attempt:   3
```

Receivers should reject a timestamp more than five minutes old, compare with a
constant-time function, and use `X-DE-Inbox-Delivery` to deduplicate, since a retry
repeats it.

### Payload

```json
{
  "schema_version": 1,
  "delivery_id": "0f6c1b9e-...",
  "attempt": 1,
  "sent_at": "2026-09-03T14:22:31+00:00",
  "site": {"base_url": "https://www.example.com", "environment": "production"},
  "message": {
    "id": 48213,
    "severity": 500,
    "severity_label": "Critical",
    "source": "integration_erp_pricing_sync",
    "title": "IntegrationErp query request failed",
    "tags": ["erp", "pricing"],
    "occurrences": 4,
    "never_delete": true,
    "created_at": "2026-09-03T14:20:02+00:00",
    "body": null,
    "body_truncated": false
  }
}
```

Key on the `severity` integer rather than the label. **Ignore keys you do not recognise:**
new optional keys may be added at any time without changing `schema_version`, which is
bumped only for a renamed or removed key, a changed type, or a changed meaning.

### Retries

Failures that could plausibly succeed later — timeouts, DNS and TLS errors, `5xx`, `408`,
`429` — are retried with exponential backoff and jitter, six attempts by default, spanning
roughly six hours. A `4xx` other than those means the receiver understood and rejected the
request, so it is not retried.

### Operational requirements

Forwarding depends on the queue consumer `DeployEcommerceInboxWebhookDispatch` running.
Two things will stop it silently:

- `cron_consumers_runner/cron_run` set to `false` in `env.php`.
- A non-empty `cron_consumers_runner/consumers` allowlist in `env.php` that omits it.

A health check runs every fifteen minutes and raises an admin notification if either is
true, if messages have been waiting more than fifteen minutes, or if a delivery has failed
permanently in the last day.

Run `bin/magento setup:upgrade` after installing. Without it the queue tables have no row
for this queue and published messages are silently orphaned.

## Email escalation

An inbox only works when somebody opens it. **Admin Inbox > Email Escalation** sends an
hourly digest of messages at or above a chosen severity, so an overnight failure does not
wait for someone to log in.

It is a digest rather than one email per message, because a failure storm would otherwise
send thousands of emails and get the sender blocked. Bodies are never included; the email
links back to the grid.

Set the threshold high. Escalating everything teaches people to filter the emails away,
which is the same as having no escalation at all.

## Retention

Messages are deleted by a nightly job, on tiers, highest precedence first:

| Tier | Kept for |
|---|---|
| Marked never-delete | Forever |
| Unread | 365 days (configurable, deliberately finite) |
| Error and above | 90 days |
| Everything else | 7 days |

One flat window would be wrong at both ends: seven days is generous for routine notices and
far too short for the failure someone investigates a fortnight later.

The job runs at 03:00 by default, deletes in batches, and stops at both a runtime limit and
a wall-clock time so it cannot run into business hours.

## Tests

```
vendor/bin/pest --testsuite="DeployEcommerce Inbox Unit"
```

## Demonstration data

To show the grid in use — severity badges, source and tag filters, read and unread rows,
occurrence counts and a pinned message:

```bash
bin/magento deployecommerce:inbox:demo-data --count=40
bin/magento deployecommerce:inbox:demo-data --clean
```

Messages are spread over roughly 100 days so the date filter and each retention tier are
represented, and the content is modelled on real integration failures rather than
placeholder text.

Two safeguards, because these rows read as genuine operational alerts:

- It refuses to run in production mode unless `--force` is given.
- Every row is tagged `demo`, and `--clean` removes only those. Real messages in the same
  table are never touched.


## Where it appears

**Inbox > Messages**, from the top-level Inbox item in the admin menu.

Configuration lives at **Stores > Configuration > Deploy Ecommerce > Admin Inbox**, and
covers retention, deduplication and outbound forwarding.

## Reading messages programmatically

Use `MessageRepositoryInterface`. The database tables are private implementation detail
and may change; do not query them directly.
