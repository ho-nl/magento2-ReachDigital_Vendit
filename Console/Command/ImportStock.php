<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use ReachDigital\Vendit\Model\ImportStockXml;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportStock extends Command
{
    public function __construct(
        private readonly ImportStockXml $importer,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:import:stock')->setDescription(
            'Import stock data from XML file supplied by Vendit'
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
            $this->importer->run();
            $output->writeln('<info>Stock successfully imported</info>');

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
