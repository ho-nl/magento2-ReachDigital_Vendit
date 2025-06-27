<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\UrlInterface;

class CategoryExportButton extends Field
{
    protected $_template = 'ReachDigital_Vendit::system/config/export_button.phtml';

    public function __construct(Context $context, public UrlInterface $urlBuilder, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getExportUrl(): string
    {
        return $this->urlBuilder->getUrl('vendit/categoryExport/run');
    }
}
