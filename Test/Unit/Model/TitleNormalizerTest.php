<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\TitleNormalizer;

/**
 * Deduplication is only useful if titles that differ by a varying id or timestamp collapse
 * to the same hash. These cases exist because an earlier version lowercased the title
 * before applying a case-sensitive timestamp pattern, so the pattern never matched and
 * every timestamped title hashed uniquely. Deduplication appeared to work and did nothing.
 */
function normalizeTitle(string $title): string
{
    return (new TitleNormalizer())->normalize($title);
}

it('collapses titles that differ only by an order number', function () {
    expect(normalizeTitle('Sync failed for order 100000123'))
        ->toBe(normalizeTitle('Sync failed for order 100000124'));
});

it('collapses titles that differ only by an ISO-8601 timestamp', function () {
    expect(normalizeTitle('IntegrationErp timeout at 2026-09-03T04:15:22Z'))
        ->toBe(normalizeTitle('IntegrationErp timeout at 2026-09-04T05:16:23Z'));
});

it('collapses titles that differ only by a UUID', function () {
    expect(normalizeTitle('Job 0f6c1b9e-4d4a-4a1f-9b2e-6c0a2f1d7e33 failed'))
        ->toBe(normalizeTitle('Job 11112222-3333-4444-5555-666677778888 failed'));
});

it('collapses titles that differ only by a long hex id', function () {
    expect(normalizeTitle('Payload a1b2c3d4e5f60718 rejected'))
        ->toBe(normalizeTitle('Payload 99887766554433221 rejected'));
});

it('keeps genuinely different messages apart', function () {
    expect(normalizeTitle('Sync failed for order 100000123'))
        ->not->toBe(normalizeTitle('Stock feed rejected by IntegrationErp'));
});

it('is case insensitive', function () {
    expect(normalizeTitle('IntegrationErp Timeout'))->toBe(normalizeTitle('integrationerp timeout'));
});
