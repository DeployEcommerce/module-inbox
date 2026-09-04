<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\Webhook\DeliveryResult;

it('treats 2xx as delivered', function (int $status) {
    $result = new DeliveryResult($status);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->isRetryable())->toBeFalse();
})->with([[200], [201], [202], [204]]);

it('retries failures that could plausibly succeed later', function (?int $status, bool $transport) {
    expect((new DeliveryResult($status, '', $transport))->isRetryable())->toBeTrue();
})->with([
    [500, false],
    [502, false],
    [503, false],
    [504, false],
    [408, false],
    [429, false],
    [null, true],
]);

it('does not retry a rejection the receiver will simply repeat', function (int $status) {
    $result = new DeliveryResult($status);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->isRetryable())->toBeFalse();
})->with([
    [400],
    [401],
    [403],
    [404],
    [422],
    // Redirects are refused by design: following one would leave the vetted address behind.
    [301],
    [302],
]);
