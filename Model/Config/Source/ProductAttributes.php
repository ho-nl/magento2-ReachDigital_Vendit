<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReachDigital\Vendit\Block\Adminhtml\Form\Field\ProductAttributeColumn;

class ProductAttributes implements OptionSourceInterface
{
    public function __construct(private readonly ProductAttributeColumn $productAttributeColumn)
    {
    }

    public function toOptionArray(): array
    {
        return $this->productAttributeColumn->getSourceOptions();
    }
}
