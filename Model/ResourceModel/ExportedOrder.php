<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class ExportedOrder
{
    private const TABLE = 'reachdigital_vendit_exported_orders';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function markOrderAsExported(string $orderIncrementId, string $exportXml = ''): void
    {
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            self::TABLE,
            [
                'order_increment_id' => $orderIncrementId,
                'exported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                'export_xml' => $exportXml,
            ],
            ['exported_at', 'export_xml'],
        );
    }

    /**
     * Clear export_xml for orders exported more than $months months ago.
     * Returns the number of affected rows.
     */
    public function clearOldExportXml(int $months): int
    {
        $connection = $this->resource->getConnection();
        $cutoff = (new \DateTime())->modify("-{$months} months")->format('Y-m-d H:i:s');

        return $connection->update(
            self::TABLE,
            ['export_xml' => null],
            ['exported_at < ?' => $cutoff, 'export_xml IS NOT NULL'],
        );
    }

    public function getAllExportedOrderIds(): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection
            ->select()
            ->from(self::TABLE, ['order_increment_id']);

        return $connection->fetchCol($select);
    }
}
