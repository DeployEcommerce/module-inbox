<?php
declare(strict_types=1);

namespace DeployEcommerce\Inbox\Console\Command;

use DeployEcommerce\Inbox\Model\ResourceModel\Message as MessageResource;
use DeployEcommerce\Inbox\Model\Severity;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Fills the inbox with representative messages so the admin UI can be demonstrated.
 *
 * Rows are written directly rather than through InboxWriterInterface, because the writer
 * always stamps the current time and a useful demonstration needs messages spread across
 * days: the grid's date filters, the severity retention tiers and the "kept indefinitely"
 * exemption are only visible when the data has a spread of ages.
 *
 * Every row is tagged "demo", which is what --clean removes. Nothing else is touched, so
 * running this against a database holding real messages cannot delete them.
 */
class DemoDataCommand extends Command
{
    private const NAME = 'deployecommerce:inbox:demo-data';
    private const DEMO_TAG = 'demo';

    /**
     * Realistic content: the point of the demonstration is to show what the grid looks like
     * in use, and lorem ipsum shows nothing about severity triage or source filtering.
     *
     * @var array<int, array{0:int,1:string,2:string,3:string[],4:string}>
     */
    private const TEMPLATES = [
        [
            Severity::Critical->value,
            'integration_erp_pricing',
            'IntegrationErp price sync aborted',
            ['integration_erp', 'api', 'integration', 'pricing'],
            "GuzzleHttp\\Exception\\ConnectException: cURL error 28: Operation timed out after 30001 milliseconds\n"
            . "  at Vendor\\IntegrationErp\\Model\\Client->request()\n"
            . "  at Vendor\\IntegrationErp\\Model\\PriceSync->setPrices()\n\n"
            . "Request: POST /services/rest/query/v1/records\nAuthorization: Bearer ***REDACTED***\n"
            . "Products affected: 4,182\nLast successful run: 19 hours ago",
        ],
        [
            Severity::Critical->value,
            'integration_erp_orders',
            'Order push failed and was not retried',
            ['integration_erp', 'orders', 'integration'],
            "Order 100084412 was accepted by the storefront but rejected by IntegrationErp.\n\n"
            . "{\"error\":{\"code\":\"INVALID_KEY_OR_REF\",\"message\":\"Invalid customer reference key 88213\"}}\n\n"
            . "The order is paid. It will not be retried automatically and needs to be raised by hand.",
        ],
        [
            Severity::Error->value,
            'integration_erp_stock',
            'Stock feed rejected by IntegrationErp',
            ['integration_erp', 'stock', 'integration'],
            "HTTP 400 from the ERP API.\n{\"error\":{\"code\":\"USER_ERROR\",\"message\":\"Invalid location id 0\"}}\n\n"
            . "Skipped 312 of 8,904 SKUs.",
        ],
        [
            Severity::Error->value,
            'product_import',
            'Product import finished with errors',
            ['pim', 'import', 'catalog'],
            "Processed 12,455 rows in 00:14:22.\n\n"
            . "  4,102 updated\n  8,201 unchanged\n    152 skipped\n\n"
            . "Skipped rows had a missing or unmapped attribute set. First ten SKUs:\n"
            . "  HM-4410, HM-4411, HM-4498, HM-5012, HM-5013, HM-5199, HM-6001, HM-6002, HM-6110, HM-6111",
        ],
        [
            Severity::Error->value,
            'search_indexing',
            'Search indexing batch failed',
            ['search', 'indexing', 'integration'],
            "Batch 47 of 121 returned HTTP 502 from the search provider.\nRetried three times, then abandoned.\n"
            . "Search results for the affected products will be stale until the next full run.",
        ],
        [
            Severity::Warning->value,
            'address_lookup',
            'Address lookup quota at 90%',
            ['address', 'api', 'integration'],
            "37,412 of 41,000 monthly lookups used with 9 days remaining.\n"
            . "Address autocomplete stops working when the quota is exhausted.",
        ],
        [
            Severity::Warning->value,
            'payment_gateway',
            'Webhook signature verification failed',
            ['payments', 'gateway', 'api'],
            "Received a webhook whose signature did not verify.\n"
            . "Event: payment_intent.succeeded\nSource IP: 54.187.174.169\n\n"
            . "This is expected briefly after a signing secret rotation. If it persists, the endpoint "
            . "secret in configuration no longer matches the gateway.",
        ],
        [
            Severity::Warning->value,
            'inbox_purge',
            'Cleanup stopped at the end of its window',
            ['housekeeping'],
            "Deleted 41,220 messages in 25 batches before reaching the configured stop time.\n"
            . "Around 6,000 messages remain and will be removed on the next run.",
        ],
        [
            Severity::Notice->value,
            'product_feed',
            'Google Shopping feed regenerated',
            ['feeds', 'marketing'],
            "18,204 products written in 00:03:11.\n412 excluded by the feed's own filters.",
        ],
        [
            Severity::Info->value,
            'product_import',
            'Nightly catalogue import completed',
            ['pim', 'import', 'catalog'],
            "Processed 12,455 rows in 00:11:04 with no errors.",
        ],
        [
            Severity::Info->value,
            'reviews_sync',
            'Review import completed',
            ['reviews', 'integration'],
            "Imported 84 new reviews. Average rating 4.6 across 1,204 total.",
        ],
        [
            Severity::Alert->value,
            'integration_erp_pricing',
            'Contract pricing stale for over 24 hours',
            ['integration_erp', 'pricing', 'b2b'],
            "The last successful contract price sync was 27 hours ago.\n"
            . "B2B customers are currently seeing list prices rather than their agreed rates.",
        ],
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Fill the Inbox with demonstration messages.')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many messages to create', '40')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Remove demonstration messages instead of creating them')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mode = $this->appState->getMode();
        } catch (\Throwable) {
            $mode = State::MODE_DEFAULT;
        }

        // This writes rows an administrator will read as real operational alerts. Getting
        // that onto a production inbox would be worse than a nuisance, so it has to be
        // deliberate.
        if ($mode === State::MODE_PRODUCTION && !$input->getOption('force')) {
            $output->writeln('<error>Refusing to run in production mode. Re-run with --force if you are certain.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption('clean')) {
            return $this->clean($output);
        }

        $count = max(1, (int)$input->getOption('count'));

        if (!$input->getOption('force') && $input->isInteractive()) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('Create %d demonstration message(s) in the inbox? [y/N] ', $count),
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');

                return Command::SUCCESS;
            }
        }

        return $this->generate($count, $output);
    }

    private function generate(int $count, OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $messageTable = $this->resource->getTableName(MessageResource::MAIN_TABLE);
        $tagTable = $this->resource->getTableName(MessageResource::TAG_TABLE);

        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            [$severity, $source, $title, $tags, $body] = self::TEMPLATES[$i % count(self::TEMPLATES)];

            // Spread ages across 100 days so the date filter, the seven-day routine tier and
            // the ninety-day extended tier are all represented in the grid.
            $ageMinutes = (int)round((($i / max(1, $count - 1)) ** 2) * 100 * 24 * 60);
            $createdAt = gmdate('Y-m-d H:i:s', time() - ($ageMinutes * 60));

            $occurrences = $severity >= Severity::Error->value && $i % 4 === 0
                ? random_int(2, 380)
                : 1;

            // Older messages are mostly triaged; recent ones mostly are not. A grid where
            // everything is unread, or everything is read, demonstrates nothing.
            $isRead = $ageMinutes > (14 * 24 * 60) ? 1 : (int)($i % 3 === 0);

            // Only the genuinely irreversible event is pinned, which is what the flag is
            // actually for: a paid order that never reached the ERP and will not be retried.
            // Kept to one template so the pinned set stays small, as it should be in reality.
            $neverDelete = (int)str_contains($title, 'not retried');

            $connection->insert($messageTable, [
                'severity' => $severity,
                'is_read' => $isRead,
                'never_delete' => $neverDelete,
                'source' => $source,
                'title' => $title,
                'body' => $body,
                'occurrences' => $occurrences,
                'read_at' => $isRead ? $createdAt : null,
                'created_at' => $createdAt,
                'last_seen_at' => $createdAt,
            ]);

            $messageId = (int)$connection->lastInsertId($messageTable);

            $rows = [];

            foreach (array_unique([...$tags, self::DEMO_TAG]) as $tag) {
                $rows[] = ['message_id' => $messageId, 'tag' => $tag];
            }

            $connection->insertOnDuplicate($tagTable, $rows, ['tag']);
            $created++;
        }

        $output->writeln(sprintf('<info>Created %d demonstration message(s).</info>', $created));
        $output->writeln('View them under Inbox > Messages in the admin menu.');
        $output->writeln(sprintf(
            'Remove them again with: <comment>bin/magento %s --clean</comment>',
            self::NAME
        ));

        return Command::SUCCESS;
    }

    private function clean(OutputInterface $output): int
    {
        $connection = $this->resource->getConnection();
        $tagTable = $this->resource->getTableName(MessageResource::TAG_TABLE);

        // Only rows carrying the demo tag. Real messages are never touched, even when this
        // is run against a database that holds both.
        $select = $connection->select()
            ->from($tagTable, 'message_id')
            ->where('tag = ?', self::DEMO_TAG);

        $ids = $connection->fetchCol($select);

        if ($ids === []) {
            $output->writeln('No demonstration messages found.');

            return Command::SUCCESS;
        }

        $deleted = $connection->delete(
            $this->resource->getTableName(MessageResource::MAIN_TABLE),
            ['message_id IN (?)' => $ids]
        );

        $output->writeln(sprintf('<info>Removed %d demonstration message(s).</info>', $deleted));

        return Command::SUCCESS;
    }
}
