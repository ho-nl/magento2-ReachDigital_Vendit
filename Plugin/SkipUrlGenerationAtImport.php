<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Plugin;

use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\CatalogUrlRewrite\Observer\AfterImportDataObserver;
use Magento\Framework\Event\Observer;

class SkipUrlGenerationAtImport
{
    protected ?ImportProduct $import = null;

    public function aroundExecute(AfterImportDataObserver $subject, callable $proceed, Observer $observer): ?callable
    {
        $this->import = $observer->getEvent()->getAdapter();

        $skip = $this->import->getParameters()['skip_url_generation'] ?? false;
        if ($skip) {
            return null;
        }

        return $proceed($observer);
    }
}
