<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

/**
 * Masks credentials and obvious personal data before a body is persisted.
 *
 * Integration error payloads routinely echo back the request that produced them, which
 * means bearer tokens, OAuth signatures and basic-auth headers end up in the body. Those
 * values are stored encrypted in core configuration; copying them into a plaintext
 * MEDIUMTEXT column would effectively decrypt them into the database, the backups and
 * every staging refresh taken from it.
 *
 * This runs before storage, and therefore before any outbound forwarding, so a redacted
 * value can never leave the site.
 *
 * Redaction is defence in depth, not a licence to log secrets. It is pattern-based and
 * will miss novel formats.
 */
class Redactor
{
    public const MASK = '***REDACTED***';

    /**
     * Ordered list of patterns. Each must keep the key visible and mask only the value,
     * so a redacted body still shows which credential was involved.
     */
    private const PATTERNS = [
        // Authorization: Bearer xxx / Basic xxx
        '/\b(Authorization\s*[:=]\s*)(?:Bearer|Basic|Token)\s+\S+/i',
        // Bare bearer tokens
        '/\b(Bearer\s+)[A-Za-z0-9._\-]{8,}/i',
        // OAuth 1.0a parameters, as used by ERP integrations
        '/\b(oauth_(?:signature|token|consumer_key|nonce|token_secret|consumer_secret)\s*[=:]\s*)"?[^"&,\s]+"?/i',
        // Generic key=value secrets in JSON, query strings and PHP arrays
        '/(["\']?\b(?:password|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key)\b["\']?\s*[=:]\s*)(?:")?[^"\',&\s}]+(?:")?/i',
        // AWS-style access key ids
        '/\bAKIA[0-9A-Z]{16}\b/',
        // Long hex/base64 blobs that follow a secret-ish label
        '/\b(X-[A-Za-z-]*(?:Key|Token|Secret)\s*:\s*)\S+/i',
    ];

    /**
     * @param string[] $additionalSecrets Literal values to mask, e.g. resolved config credentials.
     */
    public function redact(?string $body, array $additionalSecrets = []): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        foreach (self::PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, '$1' . self::MASK, $body);

            // preg_replace returns null on backtrack-limit failure. Keep the previous value
            // rather than losing the body entirely.
            if ($replaced !== null) {
                $body = $replaced;
            }
        }

        // Literal replacement of known live credential values catches formats the patterns
        // above do not anticipate. Short values are skipped: masking a two-character secret
        // would corrupt unrelated text.
        foreach ($additionalSecrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 8) {
                $body = str_replace($secret, self::MASK, $body);
            }
        }

        return $body;
    }

    /**
     * Titles are shown unescaped-looking in grids and are used to build the dedupe hash,
     * so strip control characters that would forge line breaks in any downstream consumer.
     */
    public function sanitizeTitle(string $title): string
    {
        $title = (string)preg_replace('/[\r\n\t\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $title);

        return trim((string)preg_replace('/ {2,}/', ' ', $title));
    }
}
