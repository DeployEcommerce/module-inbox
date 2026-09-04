<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Cron;

use DeployEcommerce\Inbox\Model\Config;
use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Emails a digest of recent high-severity messages.
 *
 * An inbox is pull-only: it works when somebody opens it. A serious integration failure
 * that nobody looks at until Monday is not meaningfully better than one that was never
 * recorded, so anything at or above the escalation severity is pushed out as well.
 *
 * A digest rather than one email per message, deliberately. A failure storm that generates
 * a thousand messages would otherwise generate a thousand emails, which gets the sender
 * blocked and the recipients filtering the alerts into a folder they never read.
 *
 * Bodies are never included: they carry third-party payloads that may contain personal
 * data, and email is not a controlled destination.
 */
class SendEscalationDigest
{
    public const XML_ENABLED = 'deployecommerce_inbox/escalation/enabled';
    public const XML_RECIPIENTS = 'deployecommerce_inbox/escalation/recipients';
    public const XML_SEVERITY = 'deployecommerce_inbox/escalation/severity_threshold';
    public const XML_PERIOD_HOURS = 'deployecommerce_inbox/escalation/period_hours';
    public const XML_SENDER = 'deployecommerce_inbox/escalation/sender';

    private const TEMPLATE = 'deployecommerce_inbox_escalation_template';
    private const MAX_ROWS = 50;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly BackendUrl $backendUrl,
        private readonly Escaper $escaper,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->scopeConfig->isSetFlag(self::XML_ENABLED)) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        try {
            $hours = max(1, (int)$this->scopeConfig->getValue(self::XML_PERIOD_HOURS));
            $threshold = (int)$this->scopeConfig->getValue(self::XML_SEVERITY)
                ?: Severity::Critical->value;

            $rows = $this->recentMessages($threshold, $hours);

            if ($rows === []) {
                return;
            }

            $this->send($recipients, $rows, $hours);
        } catch (\Throwable $e) {
            // Never rethrow: a failing digest must not mark the cron errored and start
            // retrying, and it must not be reported through the inbox either, since the
            // inbox is what it is reporting on.
            $this->logger->error('Inbox escalation digest: failed.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentMessages(int $threshold, int $hours): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(MessageResource::MAIN_TABLE),
                ['message_id', 'severity', 'source', 'title', 'occurrences', 'created_at']
            )
            ->where('severity >= ?', $threshold)
            ->where(
                sprintf('created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %d HOUR)', $hours)
            )
            ->order('created_at DESC')
            ->limit(self::MAX_ROWS);

        return $connection->fetchAll($select);
    }

    /**
     * @param string[] $recipients
     * @param array<int, array<string, mixed>> $rows
     */
    private function send(array $recipients, array $rows, int $hours): void
    {
        $this->transportBuilder
            ->setTemplateIdentifier(self::TEMPLATE)
            ->setTemplateOptions([
                'area' => Area::AREA_ADMINHTML,
                'store' => Store::DEFAULT_STORE_ID,
            ])
            ->setTemplateVars([
                'count' => count($rows),
                'period_hours' => $hours,
                'grid_url' => $this->backendUrl->getUrl('deployecommerce_inbox/message/index'),
                'rows' => $this->renderRows($rows),
            ])
            ->setFromByScope(
                (string)$this->scopeConfig->getValue(self::XML_SENDER) ?: 'general',
                Store::DEFAULT_STORE_ID
            );

        foreach ($recipients as $recipient) {
            $this->transportBuilder->addTo($recipient);
        }

        $this->transportBuilder->getTransport()->sendMessage();

        $this->logger->info(sprintf(
            'Inbox escalation digest: sent %d message summaries to %d recipient(s).',
            count($rows),
            count($recipients)
        ));
    }

    /**
     * Build the summary table.
     *
     * Every value is escaped here because the result is injected into the template with the
     * raw modifier. Titles and sources come from integrations and are not trusted.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderRows(array $rows): string
    {
        $html = '<table border="0" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
        $html .= '<tr style="text-align:left;background:#f4f4f4;">'
            . '<th>' . $this->escaper->escapeHtml(__('Severity')) . '</th>'
            . '<th>' . $this->escaper->escapeHtml(__('Source')) . '</th>'
            . '<th>' . $this->escaper->escapeHtml(__('Message')) . '</th>'
            . '<th>' . $this->escaper->escapeHtml(__('When')) . '</th>'
            . '</tr>';

        foreach ($rows as $row) {
            $title = (string)$row['title'];

            if ((int)$row['occurrences'] > 1) {
                $title .= sprintf(' (x%d)', (int)$row['occurrences']);
            }

            $html .= '<tr style="border-bottom:1px solid #e5e5e5;">'
                . '<td>' . $this->escaper->escapeHtml(
                    Severity::normalize((int)$row['severity'])->label()
                ) . '</td>'
                . '<td>' . $this->escaper->escapeHtml((string)$row['source']) . '</td>'
                . '<td>' . $this->escaper->escapeHtml($title) . '</td>'
                . '<td>' . $this->escaper->escapeHtml((string)$row['created_at']) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * @return string[]
     */
    private function recipients(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_RECIPIENTS);

        if (trim($raw) === '') {
            return [];
        }

        $values = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $values = array_map('trim', $values);
        $values = array_filter(
            $values,
            static fn ($v): bool => $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false
        );

        return array_values(array_unique($values));
    }
}
