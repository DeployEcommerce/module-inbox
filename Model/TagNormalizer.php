<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Model;

use Psr\Log\LoggerInterface;

/**
 * Normalises caller-supplied tags into safe, storable slugs.
 *
 * Tags reach the admin grid as filter option labels rendered by core templates, so they
 * are validated here rather than escaped at the point of display.
 */
class TagNormalizer
{
    public const MAX_TAGS = 10;
    public const MAX_LENGTH = 32;

    /**
     * Values that look like an attempt to express the never-delete flag as a tag.
     *
     * The originally sketched call signature put the flag inside the tags array. That
     * shape is not supported: retention is a column, not taxonomy. Callers who copy the
     * old shape get a loud warning rather than a silently dropped retention guarantee.
     */
    private const RESERVED = ['never_delete', 'neverdelete', 'never-delete'];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<mixed> $tags
     * @return string[]
     */
    public function normalize(array $tags, string $source = 'unknown'): array
    {
        $normalised = [];

        foreach ($tags as $tag) {
            if (is_bool($tag)) {
                $this->logger->warning(
                    'Inbox: boolean passed inside the tags array. The never-delete flag is a '
                    . 'separate argument: pass neverDelete: true instead.',
                    ['source' => $source]
                );
                continue;
            }

            if (!is_string($tag) && !is_numeric($tag)) {
                $this->logger->warning(
                    'Inbox: non-string value dropped from the tags array.',
                    ['source' => $source, 'type' => get_debug_type($tag)]
                );
                continue;
            }

            $slug = strtolower(trim((string)$tag));
            $slug = (string)preg_replace('/[^a-z0-9_-]/', '', $slug);
            $slug = mb_substr($slug, 0, self::MAX_LENGTH);

            if ($slug === '') {
                continue;
            }

            if (in_array($slug, self::RESERVED, true)) {
                $this->logger->warning(
                    sprintf(
                        'Inbox: reserved tag "%s" ignored. Retention is controlled by the '
                        . 'neverDelete argument, not by a tag.',
                        $slug
                    ),
                    ['source' => $source]
                );
                continue;
            }

            $normalised[$slug] = $slug;
        }

        if (count($normalised) > self::MAX_TAGS) {
            $this->logger->warning(
                sprintf('Inbox: more than %d tags supplied, extras dropped.', self::MAX_TAGS),
                ['source' => $source]
            );
            $normalised = array_slice($normalised, 0, self::MAX_TAGS, true);
        }

        return array_values($normalised);
    }
}
