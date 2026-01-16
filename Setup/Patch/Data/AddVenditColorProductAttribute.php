<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddVenditColorProductAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
    ) {
    }

    public function apply()
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Check if attribute already exists
        $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'vendit_color');
        if ($attributeId) {
            return $this;
        }

        // Create vendit_color attribute
        $eavSetup->addAttribute(Product::ENTITY, 'vendit_color', [
            'type' => 'int',
            'label' => 'Color',
            'input' => 'select',
            'source' => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
            'required' => false,
            'sort_order' => 101,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'used_in_product_listing' => true,
            'visible_on_front' => true,
            'user_defined' => true,
            'filterable' => true,
            'filterable_in_search' => true,
            'used_for_promo_rules' => true,
            'searchable' => true,
            'comparable' => true,
            'visible' => true,
            'wysiwyg_enabled' => false,
            'is_html_allowed_on_front' => false,
            'is_used_for_price_rules' => true,
            'is_visible_in_advanced_search' => true,
            'used_for_sort_by' => false,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => true,
            'is_configurable' => true,
        ]);

        // Add to default attribute set
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSetId = $eavSetup->getDefaultAttributeSetId($entityTypeId);
        $attributeGroupId = $eavSetup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);

        $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $attributeGroupId, 'vendit_color', null);

        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
