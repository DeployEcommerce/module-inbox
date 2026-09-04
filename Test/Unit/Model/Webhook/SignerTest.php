<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Model\Webhook\Signer;

function signer(): Signer
{
    return new Signer();
}

it('produces a known answer', function () {
    // Fixed inputs, so a change to the signing scheme fails here rather than silently
    // breaking every receiver.
    $expected = 'v1=' . hash_hmac('sha256', '1700000000.{"a":1}', 'secret');

    expect(signer()->sign('{"a":1}', 'secret', '1700000000'))->toBe($expected);
});

it('binds the timestamp into the signature', function () {
    expect(signer()->sign('{}', 'secret', '1700000000'))
        ->not->toBe(signer()->sign('{}', 'secret', '1700000001'));
});

it('verifies a well-formed signature', function () {
    $ts = (string)time();

    expect(signer()->verify('{"a":1}', 'secret', $ts, signer()->sign('{"a":1}', 'secret', $ts)))
        ->toBeTrue();
});

it('rejects a tampered body', function () {
    $ts = (string)time();
    $sig = signer()->sign('{"a":1}', 'secret', $ts);

    expect(signer()->verify('{"a":2}', 'secret', $ts, $sig))->toBeFalse();
});

it('rejects the wrong secret', function () {
    $ts = (string)time();
    $sig = signer()->sign('{"a":1}', 'secret', $ts);

    expect(signer()->verify('{"a":1}', 'other', $ts, $sig))->toBeFalse();
});

it('rejects a replayed request outside the tolerance window', function () {
    $ts = (string)(time() - 7200);
    $sig = signer()->sign('{"a":1}', 'secret', $ts);

    expect(signer()->verify('{"a":1}', 'secret', $ts, $sig))->toBeFalse();
});

it('rejects a non-numeric timestamp', function () {
    expect(signer()->verify('{}', 'secret', 'not-a-time', 'v1=whatever'))->toBeFalse();
});

it('sends the headers a receiver needs', function () {
    $headers = signer()->headers('{}', 'secret', 'uuid-1', 3);

    expect($headers)->toHaveKeys([
        Signer::HEADER_TIMESTAMP,
        Signer::HEADER_SIGNATURE,
        Signer::HEADER_DELIVERY,
        Signer::HEADER_ATTEMPT,
    ])->and($headers[Signer::HEADER_ATTEMPT])->toBe('3')
      ->and($headers[Signer::HEADER_DELIVERY])->toBe('uuid-1')
      ->and($headers[Signer::HEADER_SIGNATURE])->toStartWith('v1=');
});
