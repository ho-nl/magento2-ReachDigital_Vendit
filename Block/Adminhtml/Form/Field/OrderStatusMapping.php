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

class OrderStatusMapping extends AbstractFieldArray
{
    private $orderStatusRenderer;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);

        $this->orderStatusRenderer = $this->getLayout()->createBlock(OrderStatusColumn::class, '', [
            'data' => ['is_render_to_js_template' => true],
        ]);
    }

    protected function _prepareToRender()
    {
        $this->addColumn('vendit_status_id', [
            'label' => __('Vendit Status ID'),
        ]);

        $this->addColumn('vendit_status_description', [
            'label' => __('Vendit Status Description'),
        ]);

        $this->addColumn('magento_status', [
            'label' => __('Magento Order Status'),
            'renderer' => $this->orderStatusRenderer,
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Status Mapping');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $magentoStatus = $row->getData('magento_status');

        if ($magentoStatus !== null) {
            $options['option_' . $this->orderStatusRenderer->calcOptionHash($magentoStatus)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }
}
