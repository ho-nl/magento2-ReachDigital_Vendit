<?php
namespace ReachDigital\Vendit\Console\Command;

use ReachDigital\Vendit\Model\ExportProductsXml;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// @todo syntax etc
class ExportProducts extends Command
{
    public function __construct(public ExportProductsXml $builder)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('vendit:export:products')->setDescription(
            // @todo
            ''
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $xml = $this->builder->execute(true);
        $output->writeln('done');
        return Command::SUCCESS;
    }
}
