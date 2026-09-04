<?php
declare(strict_types=1);

/**
 * The webhook subsystem must never write to the inbox.
 *
 * A failed delivery that recorded an inbox message would qualify for forwarding, fail
 * again, and record another: an amplifying loop that fills the queue tables and hammers an
 * already-failing endpoint. Failures go to the outbox table, the module's own log channel
 * and an admin system message instead.
 *
 * Comments are stripped before matching, because the rule is about dependencies and the
 * classes legitimately discuss it in their docblocks.
 */
function sourceWithoutComments(string $file): string
{
    $out = '';

    foreach (token_get_all((string)file_get_contents($file)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

it('has no dependency on the inbox writer anywhere in the webhook subsystem', function () {
    $dir = dirname(__DIR__, 4) . '/Model/Webhook';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = sourceWithoutComments($file->getPathname());

        if (preg_match('/InboxWriterInterface|Inbox\\\\Inbox\b/', $code)) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
