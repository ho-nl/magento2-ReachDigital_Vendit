<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddVenditCustomerNumberAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory,
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, 'vendit_customer_number', [
            'type' => 'varchar',
            'label' => 'Vendit Customer Number',
            'input' => 'text',
            'required' => false,
            'visible' => true,
            'user_defined' => false,
            'sort_order' => 100,
            'position' => 100,
            'system' => 0,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
            'is_searchable_in_grid' => true,
        ]);

        // Add attribute to default attribute set and forms
        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, 'vendit_customer_number');
        $attribute->setData('used_in_forms', [
            'adminhtml_customer',
            'adminhtml_checkout',
            'customer_account_edit',
        ]);

        // Add to attribute set
        $customerSetup->addAttributeToSet(
            Customer::ENTITY,
            'Default',
            'General',
            'vendit_customer_number'
        );

        $attribute->save();

        return $this;
    }
}
