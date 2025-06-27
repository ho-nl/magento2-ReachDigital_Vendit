<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Controller\Adminhtml\ProductExport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use ReachDigital\Vendit\Model\ExportProductsXml;

class Run extends Action
{
    public function __construct(Context $context, public ExportProductsXml $exporter)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $this->exporter->execute();

            $downloadUrl = $this->getUrl('vendit/productExport/download');
            $path = $this->exporter->getFilePath();

            $this->messageManager->addComplexSuccessMessage('downloadProductXmlExportSuccess', [
                'url' => $downloadUrl,
                'path' => $path,
            ]);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Something went wrong trying to export the product XML: %1', $e->getMessage())
            );
        }

        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit/section/vendit_export');
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('ReachDigital_Vendit::config');
    }
}
