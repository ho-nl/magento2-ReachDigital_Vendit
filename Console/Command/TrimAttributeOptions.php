<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TrimAttributeOptions extends Command
{
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:trim-attribute-options')
            ->setDescription('Trim whitespace from attribute option labels')
            ->addArgument(
                'attribute_code',
                InputArgument::REQUIRED,
                'Attribute code (e.g., maten)'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        $attributeCode = $input->getArgument('attribute_code');
        $output->writeln("<info>Trimming attribute options for: {$attributeCode}</info>");

        try {
            $attribute = $this->attributeRepository->get($attributeCode);

            $connection = $this->resourceConnection->getConnection();
            $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
            $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');

            // Get all option IDs for this attribute
            $select = $connection->select()
                ->from($optionTable, ['option_id'])
                ->where('attribute_id = ?', $attribute->getAttributeId());

            $optionIds = $connection->fetchCol($select);

            $totalUpdates = 0;
            $optionsProcessed = 0;

            foreach ($optionIds as $optionId) {
                // Get all store-specific values for this option
                $select = $connection->select()
                    ->from($optionValueTable, ['value_id', 'store_id', 'value'])
                    ->where('option_id = ?', $optionId);

                $storeValues = $connection->fetchAll($select);

                $optionUpdated = false;
                foreach ($storeValues as $storeValue) {
                    $originalLabel = $storeValue['value'];
                    $trimmedLabel = trim($originalLabel);

                    if ($originalLabel !== $trimmedLabel) {
                        if (!$optionUpdated) {
                            $output->writeln("<comment>Trimming option_id {$optionId}:</comment>");
                            $optionUpdated = true;
                        }

                        // Update the label for this store
                        $connection->update(
                            $optionValueTable,
                            ['value' => $trimmedLabel],
                            ['value_id = ?' => $storeValue['value_id']]
                        );
                        $totalUpdates++;

                        $output->writeln("  <info>Store {$storeValue['store_id']}: '{$originalLabel}' -> '{$trimmedLabel}'</info>");
                    }
                }

                if ($optionUpdated) {
                    $optionsProcessed++;
                }
            }

            $output->writeln("<info>Trimmed {$optionsProcessed} option(s) across {$totalUpdates} store value(s)</info>");
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }
    }
}
