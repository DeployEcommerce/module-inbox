<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed reader for deployecommerce_inbox/* configuration.
 *
 * Every value is read at default scope. Messages carry no store_id and the purge is a
 * single global DELETE, so a website- or store-scoped value could never be honoured.
 */
class Config
{
    public const XML_ENABLED = 'deployecommerce_inbox/general/enabled';
    public const XML_BODY_MAX_BYTES = 'deployecommerce_inbox/general/body_max_bytes';
    public const XML_SECRET_CONFIG_PATHS = 'deployecommerce_inbox/general/secret_config_paths';

    public const XML_DEDUPE_ENABLED = 'deployecommerce_inbox/dedupe/enabled';
    public const XML_DEDUPE_WINDOW_HOURS = 'deployecommerce_inbox/dedupe/window_hours';

    public const XML_PURGE_ENABLED = 'deployecommerce_inbox/purge/enabled';
    public const XML_PURGE_SCHEDULE = 'deployecommerce_inbox/purge/schedule';
    public const XML_RETENTION_DAYS = 'deployecommerce_inbox/purge/retention_days';
    public const XML_BATCH_SIZE = 'deployecommerce_inbox/purge/batch_size';
    public const XML_MAX_RUNTIME = 'deployecommerce_inbox/purge/max_runtime';
    public const XML_WINDOW_END = 'deployecommerce_inbox/purge/window_end';
    public const XML_PINNED_WARN_THRESHOLD = 'deployecommerce_inbox/purge/pinned_warn_threshold';

    public const XML_EXTENDED_SEVERITY = 'deployecommerce_inbox/retention/extended_severity_threshold';
    public const XML_EXTENDED_DAYS = 'deployecommerce_inbox/retention/extended_retention_days';
    public const XML_KEEP_UNREAD = 'deployecommerce_inbox/retention/keep_unread';
    public const XML_UNREAD_MAX_DAYS = 'deployecommerce_inbox/retention/unread_max_days';

    public const XML_ALLOW_PIN_FROM_API = 'deployecommerce_inbox/writer/allow_never_delete_from_api';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED);
    }

    public function getBodyMaxBytes(): int
    {
        return max(1024, (int)$this->scopeConfig->getValue(self::XML_BODY_MAX_BYTES));
    }

    public function isDedupeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_DEDUPE_ENABLED);
    }

    public function getDedupeWindowHours(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_DEDUPE_WINDOW_HOURS));
    }

    public function isPurgeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PURGE_ENABLED);
    }

    public function getRetentionDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_RETENTION_DAYS));
    }

    public function getBatchSize(): int
    {
        return max(100, (int)$this->scopeConfig->getValue(self::XML_BATCH_SIZE));
    }

    public function getMaxRuntimeSeconds(): int
    {
        return max(60, (int)$this->scopeConfig->getValue(self::XML_MAX_RUNTIME) * 60);
    }

    /**
     * Wall-clock stop time, stored by the core "time" field type as "H,i,s".
     *
     * @return array{0:int,1:int}|null Hour and minute, or null when unset.
     */
    public function getWindowEnd(): ?array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_WINDOW_END);

        if ($raw === '') {
            return null;
        }

        $parts = explode(',', $raw);

        if (count($parts) < 2) {
            return null;
        }

        return [(int)$parts[0], (int)$parts[1]];
    }

    public function getPinnedWarnThreshold(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PINNED_WARN_THRESHOLD));
    }

    public function getExtendedSeverityThreshold(): int
    {
        $value = (int)$this->scopeConfig->getValue(self::XML_EXTENDED_SEVERITY);

        return $value > 0 ? $value : Severity::Error->value;
    }

    public function getExtendedRetentionDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_EXTENDED_DAYS));
    }

    public function shouldKeepUnreadLonger(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_KEEP_UNREAD);
    }

    public function getUnreadMaxDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_UNREAD_MAX_DAYS));
    }

    public function isPinFromApiAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ALLOW_PIN_FROM_API);
    }

    /**
     * Live values of configuration paths holding credentials, decrypted so the redactor can
     * mask any literal occurrence of them in a message body.
     *
     * Kept out of any log or exception message: these are the plaintext secrets.
     *
     * @return string[]
     */
    public function getSecretValues(): array
    {
        $paths = trim((string)$this->scopeConfig->getValue(self::XML_SECRET_CONFIG_PATHS));

        if ($paths === '') {
            return [];
        }

        $secrets = [];

        foreach (preg_split('/[\r\n,]+/', $paths) ?: [] as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $raw = (string)$this->scopeConfig->getValue($path);

            if ($raw === '') {
                continue;
            }

            try {
                $decrypted = $this->encryptor->decrypt($raw);
            } catch (\Throwable) {
                $decrypted = '';
            }

            foreach ([$decrypted, $raw] as $candidate) {
                if (is_string($candidate) && strlen($candidate) >= 8) {
                    $secrets[$candidate] = $candidate;
                }
            }
        }

        return array_values($secrets);
    }
}
