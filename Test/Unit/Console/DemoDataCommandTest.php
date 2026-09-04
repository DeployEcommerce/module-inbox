<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Console\Command\DemoDataCommand;
use DeployEcommerce\Inbox\Model\Severity;

/**
 * The demo command writes rows an administrator will read as real operational alerts, so
 * what it produces is worth constraining.
 */
function demoTemplates(): array
{
    return (new ReflectionClass(DemoDataCommand::class))->getConstant('TEMPLATES');
}

it('uses only real severity values', function () {
    foreach (demoTemplates() as $template) {
        expect(Severity::tryFrom($template[0]))->not->toBeNull();
    }
});

it('gives every template a source, a title, tags and a body', function () {
    foreach (demoTemplates() as [$severity, $source, $title, $tags, $body]) {
        expect($source)->not->toBe('')
            ->and($source)->not->toBe('unknown')
            ->and($title)->not->toBe('')
            ->and($tags)->not->toBeEmpty()
            ->and($body)->not->toBe('');
    }
});

it('keeps every title inside the column limit', function () {
    foreach (demoTemplates() as $template) {
        expect(mb_strlen($template[2]))->toBeLessThanOrEqual(500);
    }
});

it('keeps demo titles stable so they model deduplication honestly', function () {
    // A demo title carrying an order number would hash uniquely, quietly misrepresenting
    // how deduplication behaves. Varying detail belongs in the body.
    foreach (demoTemplates() as $template) {
        expect($template[2])->not->toMatch('/\d{3,}/');
    }
});

it('produces tags that survive normalisation unchanged', function () {
    foreach (demoTemplates() as $template) {
        foreach ($template[3] as $tag) {
            expect($tag)->toMatch('/^[a-z0-9_-]{1,32}$/');
        }
    }
});

it('covers a useful spread of severities', function () {
    expect(array_unique(array_column(demoTemplates(), 0)))->toHaveCount(6);
});

it('never ships a credential that looks live', function () {
    foreach (demoTemplates() as $template) {
        if (str_contains($template[4], 'Authorization:')) {
            // Written as already-redacted, so the demo never suggests pasting a live token
            // into a message body is normal.
            expect($template[4])->toContain('***REDACTED***');
        }
    }
});
