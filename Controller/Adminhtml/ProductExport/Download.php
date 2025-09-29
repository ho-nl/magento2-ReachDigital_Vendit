<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Controller\Adminhtml\ProductExport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Filesystem;
use ReachDigital\Vendit\Model\ExportProductsXml;

class Download extends Action
{
    public function __construct(
        Context $context,
        public RawFactory $resultRawFactory,
        public Filesystem $filesystem,
        public ExportProductsXml $exporter
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw|Redirect
    {
        $filePath = $this->exporter->getFilePath();
        $fileName = basename($filePath);

        if (!file_exists($filePath)) {
            $this->messageManager->addErrorMessage(__('Export file not found'));
            return $this->resultRedirectFactory
                ->create()
                ->setPath('adminhtml/system_config/edit/section/vendit_export');
        }

        $content = file_get_contents($filePath);

        return $this->resultRawFactory
            ->create()
            ->setHeader('Content-Type', 'application/xml')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->setContents($content);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Vendor_ProductExport::export');
    }
}
