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
use Symfony\Component\Console\Output\OutputInterface;

class ImportCategories extends Command
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
        $this->setName('vendit:category:import')->setDescription('Import categories from XML file supplied by Vendit');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isCategoryImportEnabled()) {
            $output->writeln('<error>Category import is disabled</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, continue
        }

        try {
            // First, move any files to processing queue
            $movedFiles = $this->moveImportFiles->execute();

            if (!empty($movedFiles)) {
                $output->writeln(sprintf('<info>Moved %d file(s) to processing queue</info>', count($movedFiles)));
            }

            // Then process files from the processing queue
            $processed = $this->processImportFiles->process(Config::TYPE_CATEGORY);

            if ($processed > 0) {
                $output->writeln(sprintf('<info>Successfully processed %d category import file(s)</info>', $processed));
            } else {
                $output->writeln('<info>No category import files to process</info>');
            }

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            if ($output->isVerbose()) {
                $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            }

            return Cli::RETURN_FAILURE;
        }
    }
}
