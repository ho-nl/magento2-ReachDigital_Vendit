<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Console\Command;

use Magento\Catalog\Api\AttributeSetRepositoryInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AssignAttributesToDefaultSet extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const DEFAULT_ATTRIBUTE_SET_ID = 4;

    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly AttributeGroupRepositoryInterface $attributeGroupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly EavSetup $eavSetup,
        private readonly State $state,
        private readonly ResourceConnection $resourceConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vendit:attributes:assign-to-default-set')
            ->setDescription('Assign all unassigned product attributes to the Default attribute set')
            ->addOption(
                self::OPTION_DRY_RUN,
                'd',
                InputOption::VALUE_NONE,
                'Show what would be changed without actually making changes',
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = $input->getOption(self::OPTION_DRY_RUN);

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, continue
        }

        try {
            $output->writeln('<info>Finding attributes not assigned to Default attribute set...</info>');

            // Get Default attribute set
            $defaultAttributeSet = $this->attributeSetRepository->get(self::DEFAULT_ATTRIBUTE_SET_ID);
            $output->writeln(sprintf(
                'Default attribute set: %s (ID: %d)',
                $defaultAttributeSet->getAttributeSetName(),
                $defaultAttributeSet->getAttributeSetId(),
            ));

            // Get all product attributes
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $attributes = $this->attributeRepository->getList($searchCriteria)->getItems();

            $assigned = 0;
            $skipped = 0;
            $notInAnySet = [];
            $unassignedToDefault = [];

            foreach ($attributes as $attribute) {
                $attributeCode = $attribute->getAttributeCode();
                $attributeId = (int) $attribute->getAttributeId();

                // Get all attribute sets this attribute is assigned to
                $attributeSets = $this->getAttributeSetIdsForAttribute($attributeId);

                // Skip if attribute is not in any attribute set (these are intentionally unassigned)
                if (empty($attributeSets)) {
                    $notInAnySet[] = $attributeCode;
                    continue;
                }

                // Convert to integers for comparison
                $attributeSets = array_map('intval', $attributeSets);

                // Skip if already assigned to Default
                if (in_array(self::DEFAULT_ATTRIBUTE_SET_ID, $attributeSets, true)) {
                    $skipped++;
                    continue;
                }

                // This attribute is assigned to other sets but not Default
                $unassignedToDefault[] = $attributeCode;

                if ($isDryRun) {
                    $output->writeln(sprintf(
                        '  [DRY RUN] Would assign: %s (currently in sets: %s)',
                        $attributeCode,
                        implode(', ', $attributeSets),
                    ));
                } else {
                    // Get the General group of the Default attribute set
                    $generalGroup = $this->getGeneralGroup(self::DEFAULT_ATTRIBUTE_SET_ID);

                    // Add attribute to Default attribute set
                    $this->eavSetup->addAttributeToSet(
                        \Magento\Catalog\Model\Product::ENTITY,
                        self::DEFAULT_ATTRIBUTE_SET_ID,
                        $generalGroup->getAttributeGroupId(),
                        $attributeCode,
                    );

                    $output->writeln(sprintf(
                        '  <comment>Assigned:</comment> %s',
                        $attributeCode,
                    ));
                    $assigned++;
                }
            }

            $output->writeln('');
            $output->writeln('<info>Summary:</info>');
            $output->writeln(sprintf('  Total attributes checked: %d', count($attributes)));
            $output->writeln(sprintf('  Already assigned to Default: %d', $skipped));
            $output->writeln(sprintf('  Not in any attribute set: %d', count($notInAnySet)));

            if ($isDryRun) {
                $output->writeln(sprintf('  <comment>Would assign to Default: %d</comment>', count($unassignedToDefault)));
            } else {
                $output->writeln(sprintf('  <info>Assigned to Default: %d</info>', $assigned));
            }

            if (!empty($notInAnySet)) {
                $output->writeln('');
                $output->writeln('<comment>Attributes not in any attribute set (skipped):</comment>');
                foreach ($notInAnySet as $code) {
                    $output->writeln(sprintf('  - %s', $code));
                }
            }

            if ($isDryRun && !empty($unassignedToDefault)) {
                $output->writeln('');
                $output->writeln('<comment>Run without --dry-run to apply these changes</comment>');
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

    /**
     * Get all attribute set IDs that an attribute is assigned to
     */
    private function getAttributeSetIdsForAttribute(int $attributeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('eav_entity_attribute'), ['attribute_set_id'])
            ->where('attribute_id = ?', $attributeId)
            ->distinct();

        return $connection->fetchCol($select);
    }

    /**
     * Get the first attribute group for an attribute set (usually General/Product Details)
     */
    private function getGeneralGroup(int $attributeSetId): \Magento\Eav\Api\Data\AttributeGroupInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_id', $attributeSetId)
            ->create();

        $groups = $this->attributeGroupRepository->getList($searchCriteria)->getItems();

        if (empty($groups)) {
            throw new \RuntimeException(sprintf('No attribute groups found for attribute set %d', $attributeSetId));
        }

        // Return the first group (sorted by sort order, so this will be the primary group)
        return reset($groups);
    }
}
