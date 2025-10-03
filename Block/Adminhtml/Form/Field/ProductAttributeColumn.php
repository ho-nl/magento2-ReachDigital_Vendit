<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use ReachDigital\Vendit\Model\Config\Source\ProductAttributes;

class ProductAttributeColumn extends Select
{
    public function __construct(
        Context $context,
        public readonly ProductAttributes $productAttributes,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setInputId($value)
    {
        return $this->setId($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->productAttributes->toOptionArray());
        }

        return parent::_toHtml();
    }
}
