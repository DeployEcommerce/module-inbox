<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model\Webhook;

use Magento\Framework\App\CacheInterface;

/**
 * Stores the current webhook health findings for the admin system message to render.
 *
 * Cache rather than a table: this is derived state that the health cron recomputes every
 * fifteen minutes, so losing it on a cache flush costs nothing and a table would need its
 * own schema, retention and cleanup.
 */
class HealthState
{
    private const CACHE_KEY = 'deployecommerce_inbox_webhook_health';
    private const LIFETIME = 3600;

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    /**
     * @param string[] $problems
     */
    public function set(array $problems): void
    {
        $this->cache->save(
            json_encode(array_values($problems)) ?: '[]',
            self::CACHE_KEY,
            [],
            self::LIFETIME
        );
    }

    public function clear(): void
    {
        $this->cache->remove(self::CACHE_KEY);
    }

    /**
     * @return string[]
     */
    public function get(): array
    {
        $raw = $this->cache->load(self::CACHE_KEY);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
