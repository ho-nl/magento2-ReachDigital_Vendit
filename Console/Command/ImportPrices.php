<?php

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

class ImportPrices extends Command
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
        $this->setName('vendit:price:import')
            ->setDescription('Import product prices from Products XML file supplied by Vendit');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isPriceImportEnabled()) {
            $output->writeln('<error>Price import is disabled</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, continue
        }

        try {
            $movedFiles = $this->moveImportFiles->execute();

            if (!empty($movedFiles)) {
                $output->writeln(sprintf('<info>Moved %d file(s) to processing queue</info>', count($movedFiles)));
            }

            $processed = $this->processImportFiles->process(Config::TYPE_PRICE);

            if ($processed > 0) {
                $output->writeln(sprintf('<info>Successfully processed %d price import file(s)</info>', $processed));
            } else {
                $output->writeln('<info>No price import files to process</info>');
            }

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
