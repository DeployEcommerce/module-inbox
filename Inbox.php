<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox;

use DeployEcommerce\Inbox\Api\InboxWriterInterface;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Framework\App\ObjectManager;

/**
 * Global helper for writing to the admin inbox.
 *
 * Prefer injecting InboxWriterInterface. This facade exists for call sites where
 * constructor injection is impractical: static utilities, exception and shutdown
 * handlers, setup scripts, and legacy classes that cannot take a new constructor
 * argument. Refusing those call sites would simply mean the message is never recorded.
 *
 * It holds no logic. Every method delegates to the single InboxWriterInterface
 * implementation, which the object manager returns as a shared instance, so a DI-injected
 * writer and this facade are literally the same object. There is exactly one code path.
 *
 * This is the ONLY class in the module permitted to touch the object manager, and it does
 * nothing with it but resolve one interface. setWriter() is the supported injection point
 * for tests and for anyone who wants to prime it eagerly.
 *
 * @api
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class Inbox
{
    private static ?InboxWriterInterface $writer = null;

    private function __construct()
    {
    }

    /**
     * Override the resolved writer.
     *
     * Tests MUST call setWriter(null) in tearDown(). A leaked static instance is the
     * classic failure mode of this pattern and will poison unrelated suites.
     */
    public static function setWriter(?InboxWriterInterface $writer): void
    {
        self::$writer = $writer;
    }

    /**
     * @param string[] $tags
     */
    public static function log(
        string $title,
        Severity|string|int $severity = Severity::Info,
        ?string $body = null,
        string $source = 'unknown',
        array $tags = [],
        bool $neverDelete = false
    ): void {
        self::writer()?->log($title, $severity, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function emergency(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->emergency($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function alert(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->alert($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function critical(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->critical($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function error(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->error($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function warning(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->warning($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function notice(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->notice($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function info(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->info($title, $body, $source, $tags, $neverDelete);
    }

    /**
     * @param string[] $tags
     */
    public static function debug(string $title, ?string $body = null, string $source = 'unknown', array $tags = [], bool $neverDelete = false): void
    {
        self::writer()?->debug($title, $body, $source, $tags, $neverDelete);
    }

    private static function writer(): ?InboxWriterInterface
    {
        if (self::$writer === null) {
            try {
                // phpcs:ignore Magento2.Legacy.ObjectManager.ObjectManagerFound -- Single,
                // deliberate service-locator call, isolated to this facade. See the class
                // docblock: the facade exists precisely so that call sites which cannot use
                // constructor injection still have a route in.
                self::$writer = ObjectManager::getInstance()->get(InboxWriterInterface::class);
            } catch (\Throwable) {
                // Reached before the application is bootstrapped, e.g. from a standalone
                // script or during setup:di:compile. Degrade to a no-op rather than fatal:
                // a logging helper must never be the thing that breaks a deploy.
                return null;
            }
        }

        return self::$writer;
    }
}
