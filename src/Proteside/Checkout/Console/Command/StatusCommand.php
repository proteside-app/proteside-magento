<?php
/**
 * bin/magento proteside:status [--refresh]
 *
 * Prints the cached entitlement state for every configured SDK key; --refresh calls the Proteside
 * API first (same as the admin "Check status" button).
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\StoreManagerInterface;
use Proteside\Checkout\Model\Bootstrapper;
use Proteside\Checkout\Model\Config;
use Proteside\Checkout\Model\Entitlement;
use Proteside\Checkout\Model\Revalidator;
use Proteside\Checkout\Model\StatusPresenter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public const NAME = 'proteside:status';
    public const OPTION_REFRESH = 'refresh';
    public const OPTION_JSON = 'json';

    private Revalidator $revalidator;
    private StoreManagerInterface $storeManager;
    private Config $config;
    private Entitlement $entitlement;
    private Bootstrapper $bootstrapper;
    private StatusPresenter $presenter;
    private AppState $appState;

    public function __construct(
        Revalidator $revalidator,
        StoreManagerInterface $storeManager,
        Config $config,
        Entitlement $entitlement,
        Bootstrapper $bootstrapper,
        StatusPresenter $presenter,
        AppState $appState,
        ?string $name = null
    ) {
        $this->revalidator = $revalidator;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->entitlement = $entitlement;
        $this->bootstrapper = $bootstrapper;
        $this->presenter = $presenter;
        $this->appState = $appState;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Shows the cached Proteside entitlement state per SDK key (use --refresh to query the API now).')
            ->addOption(self::OPTION_REFRESH, 'r', InputOption::VALUE_NONE, 'Call the Proteside API before printing (status + remote config).')
            ->addOption(self::OPTION_JSON, null, InputOption::VALUE_NONE, 'Print machine-readable JSON instead of text.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Area already set — fine.
        }

        $refreshed = false;
        if ($input->getOption(self::OPTION_REFRESH)) {
            $this->revalidator->revalidateAll();
            $refreshed = true;
        }

        $targets = $this->revalidator->collectTargets();
        $rows = [];
        foreach ($targets as $key => $target) {
            $state = $this->entitlement->load($key);
            $storeIds = [];
            foreach ($this->storeManager->getStores() as $store) {
                if ($this->config->getSdkKey((int)$store->getId()) === $key) {
                    $storeIds[] = (int)$store->getId();
                }
            }
            $firstStoreId = $storeIds[0] ?? null;
            $rows[] = [
                'key' => $key,
                'store_views' => $target['stores'],
                'site' => $target['site'],
                'enabled' => $firstStoreId !== null ? $this->config->isEnabled($firstStoreId) : false,
                'status' => (string)$state['status'],
                'last_definitive_status' => (string)$state['last_status'],
                'decision' => Entitlement::decide($state),
                'plan' => $state['plan'],
                'grace_until' => $state['grace_until'],
                'domain_mismatch' => (bool)$state['domain_mismatch'],
                'checked_at' => (int)$state['checked_at'],
                'last_success_at' => (int)$state['last_success_at'],
                'http_code' => (int)$state['http_code'],
                'message' => (string)$state['message'],
                'channel_configured' => $firstStoreId !== null ? $this->config->getChannel($firstStoreId) : Config::CHANNEL_AUTO,
                'channel_effective' => $this->entitlement->effectiveChannel($state, $firstStoreId !== null ? $this->config->getChannel($firstStoreId) : Config::CHANNEL_AUTO),
                'cdn_base' => $state['cdn_base'],
                'inline_rules' => count($state['inline_rules']),
                'inline_gtm' => count($state['inline_gtm']),
                'config_checked_at' => (int)$state['config_checked_at'],
                'config_http_code' => (int)$state['config_http_code'],
                'notices' => array_map(static function (array $n): string {
                    return (string)$n['message'];
                }, $this->presenter->notices($state)),
            ];
        }

        $meta = [
            'module_version' => Config::MODULE_VERSION,
            'bootstrapper_version' => $this->bootstrapper->getVersion(),
            'bootstrapper_sha256' => $this->bootstrapper->getHash(),
            'cron_expression' => $this->config->getCronExpression(),
            'refreshed' => $refreshed,
        ];

        if ($input->getOption(self::OPTION_JSON)) {
            $output->writeln((string)json_encode(['meta' => $meta, 'keys' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Cli::RETURN_SUCCESS;
        }

        $output->writeln(sprintf('<info>Proteside checkout protection</info> module %s · bootstrapper %s', $meta['module_version'], $meta['bootstrapper_version']));
        $output->writeln(sprintf('Bootstrapper sha256: %s', $meta['bootstrapper_sha256']));
        $output->writeln(sprintf('Cron expression: %s%s', $meta['cron_expression'], $refreshed ? ' · refreshed now' : ''));
        $output->writeln('');

        if ($rows === []) {
            $output->writeln('<comment>No SDK key is configured yet.</comment> Stores > Configuration > Security > Proteside.');
            return Cli::RETURN_SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf('<info>Key</info> %s', $row['key']));
            $output->writeln(sprintf('  Store views: %s', implode(', ', $row['store_views'])));
            $output->writeln(sprintf('  Site: %s', $row['site']));
            $output->writeln(sprintf('  Enabled: %s', $row['enabled'] ? 'yes' : 'no'));
            $output->writeln(sprintf('  Status: %s (last definitive: %s, HTTP %d)', $row['status'], $row['last_definitive_status'], $row['http_code']));
            $output->writeln(sprintf('  Decision: %s', $row['decision'] ? 'inject' : 'do not inject'));
            $output->writeln(sprintf('  Plan: %s%s', $row['plan'] ?? '-', $row['grace_until'] ? ' · grace until ' . $row['grace_until'] : ''));
            $output->writeln(sprintf('  Last check: %s · last success: %s', $this->fmt($row['checked_at']), $this->fmt($row['last_success_at'])));
            $output->writeln(sprintf('  Channel: %s (configured: %s)%s', $row['channel_effective'], $row['channel_configured'], $row['cdn_base'] ? ' · CDN ' . $row['cdn_base'] : ''));
            $output->writeln(sprintf('  Inline rules / GTM containers: %d / %d (config HTTP %d, %s)', $row['inline_rules'], $row['inline_gtm'], $row['config_http_code'], $this->fmt($row['config_checked_at'])));
            if ($row['message'] !== '') {
                $output->writeln(sprintf('  Message: %s', $row['message']));
            }
            foreach ($row['notices'] as $notice) {
                $output->writeln(sprintf('  <comment>! %s</comment>', $notice));
            }
            $output->writeln('');
        }
        $output->writeln('Verify: open your checkout and run Proteside.getStatus() in the browser console. installationOrderValid must be true.');

        return Cli::RETURN_SUCCESS;
    }

    private function fmt(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC' : 'never';
    }
}
