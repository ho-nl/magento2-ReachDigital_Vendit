<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ReachDigital\Vendit\Model\ExportOrdersXml;
use ReachDigital\Vendit\Model\Config;

class ExportOrders extends Command
{
    public function __construct(
        public ExportOrdersXml $exporter,
        private readonly Config $config,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vendit:orders:export')->setDescription('Export all orders to XML');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isOrderExportEnabled()) {
            $output->writeln('<error>Order export is disabled</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $exported = $this->exporter->execute();

            if ($exported) {
                $directory = dirname($this->config->getOrderExportFilePath());
                $output->writeln(
                    sprintf(
                        '<info>Successfully exported %s order(s) to separate XML files in %s</info>',
                        $exported,
                        $directory
                    )
                );
            } else {
                $output->writeln('<info>No orders found to be exported.</info>');
            }

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
