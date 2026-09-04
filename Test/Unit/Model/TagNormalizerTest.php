<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\TagNormalizer;
use Psr\Log\LoggerInterface;

function tagNormalizer(?LoggerInterface $logger = null): TagNormalizer
{
    return new TagNormalizer($logger ?? test()->createMock(LoggerInterface::class));
}

it('lowercases, trims and slugs tags', function () {
    // Mixed case, surrounding space, a stripped punctuation mark, and an internal space
    // removed: all three transforms in one case.
    expect(tagNormalizer()->normalize(['  IntegrationErp ', 'API!', 'in tegration']))
        ->toBe(['integrationerp', 'api', 'integration']);
});

it('removes duplicates', function () {
    // Underscores survive slugging, so these three differ only by case.
    expect(tagNormalizer()->normalize(['integration_erp', 'Integration_Erp', 'INTEGRATION_ERP']))
        ->toBe(['integration_erp']);
});

it('drops empty results rather than storing blank tags', function () {
    expect(tagNormalizer()->normalize(['!!!', '   ', 'ok']))->toBe(['ok']);
});

it('caps the number of tags', function () {
    $tags = array_map(static fn (int $i): string => 'tag' . $i, range(1, 25));

    expect(tagNormalizer()->normalize($tags))->toHaveCount(TagNormalizer::MAX_TAGS);
});

it('caps tag length', function () {
    expect(tagNormalizer()->normalize([str_repeat('a', 100)])[0])
        ->toHaveLength(TagNormalizer::MAX_LENGTH);
});

/**
 * The originally sketched call signature put the never-delete flag inside the tags array.
 * That shape is unsupported, and a boolean silently stored as an empty tag would drop the
 * retention guarantee without telling anyone. It must be dropped loudly instead.
 */
it('drops a boolean smuggled into the tags array and warns', function () {
    $logger = test()->createMock(LoggerInterface::class);
    $logger->expects(test()->atLeastOnce())->method('warning');

    expect(tagNormalizer($logger)->normalize(['integration_erp', true]))->toBe(['integration_erp']);
});

it('rejects the reserved never-delete tag and warns', function (string $reserved) {
    $logger = test()->createMock(LoggerInterface::class);
    $logger->expects(test()->atLeastOnce())->method('warning');

    expect(tagNormalizer($logger)->normalize(['api', $reserved]))->toBe(['api']);
})->with([['never_delete'], ['neverdelete'], ['never-delete'], ['NEVER_DELETE']]);

it('drops non-scalar values', function () {
    expect(tagNormalizer()->normalize(['ok', ['nested'], new stdClass()]))->toBe(['ok']);
});
