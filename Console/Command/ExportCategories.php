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
use ReachDigital\Vendit\Model\ExportCategoriesXml;

class ExportCategories extends Command
{
    public function __construct(public ExportCategoriesXml $exporter, string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('vendit:export:categories')->setDescription(
            'Export all categories to XML, preserving tree structure'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->exporter->execute();
            $output->writeln('<info>Categories successfully exported to ' . $this->exporter->getFilePath() . '</info>');

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }
    }
}
