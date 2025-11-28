<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Plugin;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\ImportExport\Model\ResourceModel\Import\Data;

/**
 * Clean unprocessed import bunches.
 * After one import fails, it will keep retrying the failed import when starting a new one.
 * By always running cleanBunches instead of cleanProcessedBunches, we clean up all previously (failed) imports.
 *
 * @see https://github.com/magento/magento2/issues/37593
 */
class CleanupUnprocessedImportDataBunches
{
    public function aroundCleanProcessedBunches(Data $subject, callable $proceed): AdapterInterface|int
    {
        return $subject->cleanBunches();
    }
}
