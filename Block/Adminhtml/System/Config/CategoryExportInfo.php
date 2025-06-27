<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use ReachDigital\Vendit\Model\ExportCategoriesXml;

class CategoryExportInfo extends Field
{
    protected $_template = 'ReachDigital_Vendit::system/config/export_info.phtml';

    public function __construct(
        Context $context,
        public ExportCategoriesXml $categoryExporter,
        public TimezoneInterface $timezone,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getFilePath(): string
    {
        return $this->categoryExporter->getFilePath();
    }

    public function getCreationDate(): string
    {
        $timestamp = filectime($this->getFilePath());
        return $this->timezone
            ->date((new \DateTime())->setTimestamp($timestamp))
            ->format(DateTime::DATETIME_PHP_FORMAT);
    }

    public function getDownloadUrl(): string
    {
        return $this->getUrl('vendit/categoryExport/download');
    }
}
