<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Api\Data\MessageInterface;
use DeployEcommerce\Inbox\Model\Severity;

it('maps every case to the Monolog level value', function (Severity $case, int $expected) {
    expect($case->value)->toBe($expected);
})->with([
    [Severity::Debug, 100],
    [Severity::Info, 200],
    [Severity::Notice, 250],
    [Severity::Warning, 300],
    [Severity::Error, 400],
    [Severity::Critical, 500],
    [Severity::Alert, 550],
    [Severity::Emergency, 600],
]);

it('keeps the interface constants in step with the enum', function () {
    expect(MessageInterface::SEVERITY_DEBUG)->toBe(Severity::Debug->value)
        ->and(MessageInterface::SEVERITY_INFO)->toBe(Severity::Info->value)
        ->and(MessageInterface::SEVERITY_NOTICE)->toBe(Severity::Notice->value)
        ->and(MessageInterface::SEVERITY_WARNING)->toBe(Severity::Warning->value)
        ->and(MessageInterface::SEVERITY_ERROR)->toBe(Severity::Error->value)
        ->and(MessageInterface::SEVERITY_CRITICAL)->toBe(Severity::Critical->value)
        ->and(MessageInterface::SEVERITY_ALERT)->toBe(Severity::Alert->value)
        ->and(MessageInterface::SEVERITY_EMERGENCY)->toBe(Severity::Emergency->value);
});

it('accepts the forms a caller might reasonably pass', function ($input, Severity $expected) {
    expect(Severity::normalize($input))->toBe($expected);
})->with([
    ['critical', Severity::Critical],
    ['CRITICAL', Severity::Critical],
    ['  Critical  ', Severity::Critical],
    [500, Severity::Critical],
    ['500', Severity::Critical],
    [Severity::Critical, Severity::Critical],
]);

it('falls back to Error rather than throwing on nonsense', function ($input) {
    expect(Severity::normalize($input))->toBe(Severity::Error)
        ->and(Severity::isRecognised($input))->toBeFalse();
})->with([['banana'], [''], [999], ['-1']]);
