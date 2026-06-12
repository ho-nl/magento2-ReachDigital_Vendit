<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use ReachDigital\Vendit\Model\MoveImportFiles;
use ReachDigital\Vendit\Model\ProcessImportFiles;
use ReachDigital\Vendit\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportStock extends Command
{
    public function __construct(
        private readonly MoveImportFiles $moveImportFiles,
        private readonly ProcessImportFiles $processImportFiles,
        private readonly State $state,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:stock:import')
            ->setDescription('Import stock data from XML file supplied by Vendit')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of files to process (default: all)');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isStockImportEnabled()) {
            $output->writeln('<error>Stock import is disabled</error>');
            return Cli::RETURN_FAILURE;
        }

        // Check if there are pending product imports (only relevant when product import is enabled)
        if ($this->config->isProductImportEnabled() && $this->config->hasPendingImportFiles(Config::TYPE_PRODUCT)) {
            $output->writeln('<info>Skipping stock import: product import files are still pending</info>');
            return Cli::RETURN_SUCCESS;
        }

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, continue
        }

        try {
            // First, move any files to processing queue (same as webhook)
            $movedFiles = $this->moveImportFiles->execute();

            if (!empty($movedFiles)) {
                $output->writeln(sprintf('<info>Moved %d file(s) to processing queue</info>', count($movedFiles)));
            }

            $limitOption = $input->getOption('limit');
            $limit = $limitOption !== null ? (int) $limitOption : null;

            $processed = $this->processImportFiles->process(Config::TYPE_STOCK, $limit);

            if ($processed > 0) {
                $output->writeln(sprintf('<info>Successfully processed %d stock import file(s)</info>', $processed));
            } else {
                $output->writeln('<info>No stock import files to process</info>');
            }

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
