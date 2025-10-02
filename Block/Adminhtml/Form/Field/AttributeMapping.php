<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class AttributeMapping extends AbstractFieldArray
{
    private $attributeOptions;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);

        $this->attributeOptions = $this->getLayout()->createBlock(ProductAttributeColumn::class, '', [
            'data' => ['is_render_to_js_template' => true],
        ]);
    }

    protected function _prepareToRender()
    {
        $this->addColumn('attribute_code', [
            'label' => __('Magento Attribute'),
            'renderer' => $this->attributeOptions,
        ]);

        $this->addColumn('spec_name', [
            'label' => __('Vendit Spec Name'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Attribute Mapping');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $attributeCode = $row->getData('attribute_code');

        if ($attributeCode !== null) {
            $options['option_' . $this->attributeOptions->calcOptionHash($attributeCode)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }
}
