<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\Console\Cli;
use ReachDigital\Vendit\Model\ExportStockXml;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportStock extends Command
{
    public function __construct(public ExportStockXml $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:export:stock')->setDescription('Export all product stock items to XML');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->exporter->execute();
            $output->writeln(
                '<info>Stock items successfully exported to ' . $this->exporter->getFilePath() . '</info>'
            );

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
