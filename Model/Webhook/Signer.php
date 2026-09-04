<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

/**
 * Signs outbound payloads with HMAC-SHA256.
 *
 * A static bearer token proves only that the sender knew a string, and that string is
 * replayable forever and leaks wholesale through any receiver-side log or proxy. Signing
 * binds the timestamp and the exact body to a shared secret that never crosses the wire,
 * giving authentication, integrity and replay resistance together. It is also the scheme
 * widely deployed webhook implementations use, so receiving
 * implementers already know the shape.
 */
class Signer
{
    public const HEADER_TIMESTAMP = 'X-DE-Inbox-Timestamp';
    public const HEADER_SIGNATURE = 'X-DE-Inbox-Signature';
    public const HEADER_DELIVERY = 'X-DE-Inbox-Delivery';
    public const HEADER_ATTEMPT = 'X-DE-Inbox-Attempt';

    /**
     * Version prefix on the signature, so a future algorithm can be introduced without
     * breaking receivers that only understand this one.
     */
    public const SIGNATURE_VERSION = 'v1';

    /**
     * Build the signature headers for a payload.
     *
     * $rawBody must be the exact bytes that will be transmitted. Signing a re-encoded copy
     * produces a signature the receiver cannot reproduce, because JSON encoding is not
     * canonical.
     *
     * @return array<string, string>
     */
    public function headers(string $rawBody, string $secret, string $deliveryUuid, int $attempt): array
    {
        $timestamp = (string)time();

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $this->sign($rawBody, $secret, $timestamp),
            self::HEADER_DELIVERY => $deliveryUuid,
            self::HEADER_ATTEMPT => (string)$attempt,
        ];
    }

    /**
     * The timestamp is inside the signed string, not merely sent alongside it. Otherwise an
     * attacker could replay a captured body with a fresh timestamp and the signature would
     * still verify.
     */
    public function sign(string $rawBody, string $secret, string $timestamp): string
    {
        return self::SIGNATURE_VERSION . '=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Verification helper, provided so receivers written in PHP have a reference and so the
     * signing logic is testable against known answers.
     *
     * Uses hash_equals: a plain === comparison on a MAC is timing-attackable.
     */
    public function verify(
        string $rawBody,
        string $secret,
        string $timestamp,
        string $signature,
        int $toleranceSeconds = 300
    ): bool {
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($rawBody, $secret, $timestamp), $signature);
    }
}
