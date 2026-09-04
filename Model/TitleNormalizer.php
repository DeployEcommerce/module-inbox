<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

/**
 * Reduces a title to a stable form for deduplication hashing.
 *
 * This is the part that decides whether deduplication works at all. "Sync failed for
 * order 100000123" and "Sync failed for order 100000124" must hash identically, or every
 * occurrence inserts a new row and the feature is decorative.
 *
 * Callers should still keep varying detail out of the title. This is a safety net for
 * when they do not.
 */
class TitleNormalizer
{
    public function normalize(string $title): string
    {
        $normalised = strtolower(trim($title));

        // UUIDs
        $normalised = (string)preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '#',
            $normalised
        );
        // ISO-8601 timestamps and plain dates.
        // The i flag is load-bearing: normalisation lowercases first, so the T separator and
        // the Z suffix arrive as "t" and "z". Without it this pattern silently never matches
        // and every timestamped title hashes uniquely, which disables deduplication entirely.
        $normalised = (string)preg_replace(
            '/\b\d{4}-\d{2}-\d{2}([t ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(z|[+-]\d{2}:?\d{2})?)?\b/i',
            '#',
            $normalised
        );
        // Clock times
        $normalised = (string)preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/', '#', $normalised);
        // Hex blobs (ids, hashes)
        $normalised = (string)preg_replace('/\b[0-9a-f]{16,}\b/i', '#', $normalised);
        // Any run of 3 or more digits: order numbers, entity ids, SKUs, byte counts
        $normalised = (string)preg_replace('/\d{3,}/', '#', $normalised);
        // Collapse whitespace
        $normalised = (string)preg_replace('/\s+/', ' ', $normalised);

        return trim($normalised);
    }
}
