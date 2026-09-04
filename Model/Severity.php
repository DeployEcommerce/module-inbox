<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

/**
 * Severity levels, backed by Monolog's own integer values.
 *
 * The integer backing is the value stored in the severity column, which is what makes
 * "error and above" an indexable range rather than a set-membership scan. The eight
 * levels are fixed by RFC 5424 and have not changed since Monolog 1.0, so freezing them
 * in an enum carries no realistic forwards-compatibility cost.
 *
 * This enum deliberately lives outside Api/: Magento's service-contract reflection
 * cannot serialise PHP enums, so exposing one on a web API interface fails at runtime.
 *
 * @api
 */
enum Severity: int
{
    case Debug = 100;
    case Info = 200;
    case Notice = 250;
    case Warning = 300;
    case Error = 400;
    case Critical = 500;
    case Alert = 550;
    case Emergency = 600;

    /**
     * Accept whatever a caller reasonably passes: an enum case, 'critical', 'CRITICAL', or 500.
     *
     * Never throws. An unrecognised value falls back to Error rather than rejecting the
     * write, because a message that cannot be classified is still worth keeping.
     */
    public static function normalize(self|string|int $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::tryFrom($value) ?? self::Error;
        }

        $name = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if (strtolower($case->name) === $name) {
                return $case;
            }
        }

        return is_numeric($name) ? (self::tryFrom((int)$name) ?? self::Error) : self::Error;
    }

    /**
     * True when the given value was not recognised and normalize() had to fall back.
     */
    public static function isRecognised(self|string|int $value): bool
    {
        if ($value instanceof self) {
            return true;
        }

        if (is_int($value)) {
            return self::tryFrom($value) !== null;
        }

        $name = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if (strtolower($case->name) === $name) {
                return true;
            }
        }

        return is_numeric($name) && self::tryFrom((int)$name) !== null;
    }

    public function label(): string
    {
        return $this->name;
    }
}
