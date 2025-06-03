<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Controller\Adminhtml\CategoryExport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use ReachDigital\Vendit\Model\ExportCategoriesXml;

class Run extends Action
{
    public function __construct(Context $context, public ExportCategoriesXml $exporter)
    {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        try {
            $this->exporter->export();

            $downloadUrl = $this->getUrl('vendit/categoryExport/download');
            $path = $this->exporter->getFilePath();

            $this->messageManager->addComplexSuccessMessage('downloadCategoryXmlExportSuccess', [
                'url' => $downloadUrl,
                'path' => $path,
            ]);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Something went wrong trying to export the categories XML: %1', $e->getMessage())
            );
        }

        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit/section/vendit_export');
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('ReachDigital_Vendit::config');
    }
}
