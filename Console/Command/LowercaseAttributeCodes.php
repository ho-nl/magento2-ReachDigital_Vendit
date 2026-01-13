<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LowercaseAttributeCodes extends Command
{
    private const OPTION_FORCE = 'force';
    private const OPTION_ATTRIBUTE = 'attribute';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:attribute-codes:lowercase')
            ->setDescription('Transform product attribute codes to lowercase')
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Actually apply the changes (without this flag, only shows what would be changed)'
            )
            ->addOption(
                self::OPTION_ATTRIBUTE,
                'a',
                InputOption::VALUE_OPTIONAL,
                'Specific attribute code to transform (e.g., Sale_moments). If not specified, all attributes will be checked.'
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

        $force = $input->getOption(self::OPTION_FORCE);
        $specificAttribute = $input->getOption(self::OPTION_ATTRIBUTE);

        if (!$force) {
            $output->writeln('<comment>DRY RUN MODE - No changes will be made (use --force to apply changes)</comment>');
        }

        $connection = $this->setup->getConnection();

        // Get all product attributes
        $select = $connection->select()
            ->from($this->setup->getTable('eav_attribute'), ['attribute_id', 'attribute_code'])
            ->join(
                ['entity_type' => $this->setup->getTable('eav_entity_type')],
                'eav_attribute.entity_type_id = entity_type.entity_type_id',
                []
            )
            ->where('entity_type.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY);

        if ($specificAttribute) {
            $select->where('eav_attribute.attribute_code = ?', $specificAttribute);
        }

        $attributes = $connection->fetchAll($select);

        if (empty($attributes)) {
            if ($specificAttribute) {
                $output->writeln("<error>Attribute '{$specificAttribute}' not found</error>");
                return Cli::RETURN_FAILURE;
            }
            $output->writeln('<info>No attributes found</info>');
            return Cli::RETURN_SUCCESS;
        }

        $changedCount = 0;
        $skippedCount = 0;

        foreach ($attributes as $attribute) {
            $oldCode = $attribute['attribute_code'];
            $newCode = strtolower($oldCode);

            if ($oldCode === $newCode) {
                $skippedCount++;
                continue;
            }

            // Check if lowercase version already exists (case-sensitive)
            $existingSelect = $connection->select()
                ->from($this->setup->getTable('eav_attribute'), ['attribute_id'])
                ->join(
                    ['entity_type' => $this->setup->getTable('eav_entity_type')],
                    'eav_attribute.entity_type_id = entity_type.entity_type_id',
                    []
                )
                ->where('entity_type.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
                ->where('BINARY eav_attribute.attribute_code = ?', $newCode);

            $existing = $connection->fetchOne($existingSelect);

            if ($existing) {
                $output->writeln("<error>Cannot rename '{$oldCode}' to '{$newCode}' - lowercase version already exists</error>");
                continue;
            }

            $output->writeln("<info>Changing: '{$oldCode}' -> '{$newCode}'</info>");

            if ($force) {
                try {
                    $connection->update(
                        $this->setup->getTable('eav_attribute'),
                        ['attribute_code' => $newCode],
                        ['attribute_id = ?' => $attribute['attribute_id']]
                    );
                    $changedCount++;
                } catch (\Exception $e) {
                    $output->writeln("<error>Failed to update '{$oldCode}': {$e->getMessage()}</error>");
                }
            } else {
                $changedCount++;
            }
        }

        $output->writeln('');
        $output->writeln('<info>Summary:</info>');
        $output->writeln("  Changed: $changedCount");
        $output->writeln("  Skipped (already lowercase): $skippedCount");

        if (!$force && $changedCount > 0) {
            $output->writeln('');
            $output->writeln('<comment>This was a dry run. Run with --force to apply changes.</comment>');
        }

        return Cli::RETURN_SUCCESS;
    }
}
