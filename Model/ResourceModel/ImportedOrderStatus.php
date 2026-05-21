<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class ImportedOrderStatus
{
    private const TABLE = 'reachdigital_vendit_imported_order_statuses';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    public function hasBeenProcessed(string $orderIncrementId, string $statusEnumValue): bool
    {
        $connection = $this->resource->getConnection();

        $select = $connection
            ->select()
            ->from(self::TABLE, ['id'])
            ->where('order_increment_id = ?', $orderIncrementId)
            ->where('vendit_status_enum_value = ?', $statusEnumValue)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    public function markAsProcessed(string $orderIncrementId, string $statusEnumValue): void
    {
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            self::TABLE,
            [
                'order_increment_id' => $orderIncrementId,
                'vendit_status_enum_value' => $statusEnumValue,
                'imported_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['imported_at'],
        );
    }
}
