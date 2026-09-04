<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Cron;

use DeployEcommerce\Inbox\Model\Webhook\AttemptRepository;
use DeployEcommerce\Inbox\Model\Webhook\HealthState;
use DeployEcommerce\Inbox\Model\Webhook\WebhookConfig;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

/**
 * Detects the ways webhook forwarding can be silently broken.
 *
 * The dangerous failure is not an error, it is silence: if the queue consumer is never
 * run, messages accumulate in the outbox and nothing anywhere reports a problem. Two of
 * the three checks below exist purely to make that visible.
 *
 * Findings are written to this module's log and to an admin system message. They are never
 * written to the inbox, because a webhook problem that creates an inbox message would
 * trigger another webhook.
 */
class CheckWebhookHealth
{
    public const CONSUMER_NAME = 'DeployEcommerceInboxWebhookDispatch';

    private const STALE_MINUTES = 15;

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly WebhookConfig $config,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly HealthState $healthState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->healthState->clear();

            return;
        }

        $problems = [];

        try {
            if ($consumerProblem = $this->consumerProblem()) {
                $problems[] = $consumerProblem;
            }

            $stale = $this->attempts->countStalePending(self::STALE_MINUTES);

            if ($stale > 0) {
                $problems[] = sprintf(
                    '%d message(s) have been waiting to be forwarded for more than %d minutes. '
                    . 'The queue consumer may not be running.',
                    $stale,
                    self::STALE_MINUTES
                );
            }

            $failed = $this->attempts->countFailedSince(24);

            if ($failed > 0) {
                $problems[] = sprintf('%d webhook delivery/deliveries failed permanently in the last 24 hours.', $failed);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Inbox webhook health check: failed.', ['error' => $e->getMessage()]);

            return;
        }

        if ($problems === []) {
            $this->healthState->clear();

            return;
        }

        foreach ($problems as $problem) {
            $this->logger->warning('Inbox webhook health: ' . $problem);
        }

        $this->healthState->set($problems);
    }

    /**
     * A non-empty consumers allowlist that omits ours means our consumer is never started,
     * and nothing else reports that. cron_run defaults to true when absent, so only an
     * explicit false is a problem.
     */
    private function consumerProblem(): ?string
    {
        $cronRun = $this->deploymentConfig->get('cron_consumers_runner/cron_run', true);

        if ($cronRun === false) {
            return 'Queue consumers are disabled (cron_consumers_runner/cron_run is false in '
                . 'env.php), so no message will ever be forwarded.';
        }

        $allowed = $this->deploymentConfig->get('cron_consumers_runner/consumers', []);

        if (is_array($allowed) && $allowed !== [] && !in_array(self::CONSUMER_NAME, $allowed, true)) {
            return sprintf(
                'The queue consumer "%s" is not in the cron_consumers_runner/consumers '
                . 'allowlist in env.php, so it is never started and no message will be forwarded.',
                self::CONSUMER_NAME
            );
        }

        return null;
    }
}
