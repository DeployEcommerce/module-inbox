<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

/**
 * Outcome of a single delivery attempt, and the decision about whether to try again.
 */
class DeliveryResult
{
    public function __construct(
        private readonly ?int $httpStatus,
        private readonly string $error = '',
        private readonly bool $transportFailure = false
    ) {
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->httpStatus !== null && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Whether another attempt could plausibly succeed.
     *
     * A transport failure (timeout, DNS, TLS) is worth retrying. So is a 5xx, a 408 and a
     * 429. Everything else in the 4xx range means the receiver understood the request and
     * rejected it, so retrying just repeats the same rejection. A 3xx is refused outright
     * because redirects are disabled by design.
     */
    public function isRetryable(): bool
    {
        if ($this->isSuccess()) {
            return false;
        }

        if ($this->transportFailure && $this->httpStatus === null) {
            return true;
        }

        if ($this->httpStatus === null) {
            return true;
        }

        if ($this->httpStatus === 408 || $this->httpStatus === 429) {
            return true;
        }

        return $this->httpStatus >= 500;
    }
}
