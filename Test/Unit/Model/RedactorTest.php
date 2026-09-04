<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\Redactor;

/**
 * Message bodies carry third-party API error payloads, which routinely echo back the
 * request that produced them. Those requests carry credentials, and the column is
 * plaintext, so anything missed here is a credential written to the database in clear.
 */
function redactor(): Redactor
{
    return new Redactor();
}

it('masks credentials in a body', function (string $body, string $leak) {
    $result = (string)redactor()->redact($body);

    expect($result)->toContain(Redactor::MASK)
        ->and($result)->not->toContain($leak);
})->with([
    ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abcdefghij', 'eyJhbGciOiJIUzI1NiJ9.abcdefghij'],
    ['Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA=='],
    ['{"password":"hunter2xyz"}', 'hunter2xyz'],
    ['{"client_secret":"s3cr3t-value-here"}', 's3cr3t-value-here'],
    ['oauth_signature="abc123def456"', 'abc123def456'],
    ['oauth_token=tok_9988776655', 'tok_9988776655'],
    ['X-Api-Key: 9f8e7d6c5b4a39281706', '9f8e7d6c5b4a39281706'],
    ['AKIAIOSFODNN7EXAMPLE', 'AKIAIOSFODNN7EXAMPLE'],
]);

it('masks literal credential values supplied by configuration', function () {
    $secret = 'MyLiveErpSecret123456';

    expect((string)redactor()->redact("token is {$secret} here", [$secret]))
        ->not->toContain($secret)
        ->toContain(Redactor::MASK);
});

it('ignores short values so unrelated text is not corrupted', function () {
    expect((string)redactor()->redact('the cat sat on the mat', ['cat']))
        ->toBe('the cat sat on the mat');
});

it('leaves innocent content alone', function () {
    $body = 'Stock sync completed. 412 products updated.';

    expect(redactor()->redact($body))->toBe($body);
});

it('strips control characters from titles so log lines cannot be forged', function () {
    expect(redactor()->sanitizeTitle("Sync failed\r\nFAKE: injected line"))
        ->toBe('Sync failed FAKE: injected line');
});

it('returns null untouched', function () {
    expect(redactor()->redact(null))->toBeNull();
});
