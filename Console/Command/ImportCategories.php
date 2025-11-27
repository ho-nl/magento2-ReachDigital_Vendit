<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use ReachDigital\Vendit\Model\ImportCategoriesXml;
use ReachDigital\Vendit\Model\MoveImportFiles;
use ReachDigital\Vendit\Model\ProcessImportFiles;
use ReachDigital\Vendit\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCategories extends Command
{
    private const OPTION_EXECUTE = 'execute';

    public function __construct(
        private readonly ImportCategoriesXml $importer,
        private readonly MoveImportFiles $moveImportFiles,
        private readonly ProcessImportFiles $processImportFiles,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:import:categories')
            ->setDescription('Import categories from XML file supplied by Vendit')
            ->addOption(
                self::OPTION_EXECUTE,
                null,
                InputOption::VALUE_NONE,
                'Execute the import (default is dry-run mode)'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

            // Then process files from the processing queue (webhook/automated flow)
            $processed = $this->processImportFiles->process(Config::TYPE_CATEGORY);

            if ($processed > 0) {
                $output->writeln(sprintf('<info>Successfully processed %d category import file(s)</info>', $processed));
                $output->writeln('');
                // Files were processed automatically, no need to run manual import
                return Cli::RETURN_SUCCESS;
            }

            // Manual import flow (no files in processing queue)
            $executeImport = $input->getOption(self::OPTION_EXECUTE);

            if (!$executeImport) {
                $output->writeln('<comment>Running in DRY-RUN mode. No categories will be imported.</comment>');
                $output->writeln('<comment>Use --execute flag to actually import categories.</comment>');
                $output->writeln('');
            }

            $this->importer->setDryRun(!$executeImport);

            // Load and display the category tree
            $tree = $this->importer->loadCategories();

            $output->writeln('<info>Category Tree Preview:</info>');
            $output->writeln('<info>Legend: <fg=green>[NEW]</> = new category, <fg=yellow>[MODIFIED]</> = changes detected</info>');
            $output->writeln('');
            $output->writeln('<comment>[ROOT] Root Catalog (ID: 1)</comment>');
            $output->writeln('<comment>  └─ [DEFAULT] Default Category (ID: 2)</comment>');
            $output->write($this->importer->displayCategoryTree($tree, 2));
            $output->writeln('');

            // Show orphaned categories (exist in Magento but not in XML)
            $orphaned = $this->importer->getOrphanedCategories($tree);
            if (!empty($orphaned)) {
                $output->writeln('<fg=red>Categories that will become orphaned (not in XML):</>');
                foreach ($orphaned as $orphan) {
                    $output->writeln('  <fg=red>⚠</> ' . $orphan['path']);
                }
                $output->writeln('');
            }

            if (!$executeImport) {
                $output->writeln('<comment>DRY-RUN complete. To import these categories, run:</comment>');
                $output->writeln('<comment>  bin/magento vendit:import:categories --execute</comment>');

                return Cli::RETURN_SUCCESS;
            }

            // Actually run the import
            $output->writeln('<info>Executing category import...</info>');
            $this->importer->run();
            $output->writeln('<info>Categories successfully imported</info>');

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
