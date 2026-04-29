<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection;
use Zend_Db_Expr;
use Zend_Db_Select;

/**
 * Joins the Vendit exported orders table to the admin order grid collection
 * so the exported status is available as a filterable column.
 */
class AddVenditExportedColumnToOrderGrid
{
    public function beforeLoad(
        Collection $subject,
        bool $printQuery = false,
        bool $logQuery = false,
    ): array {
        if (!$subject->isLoaded()) {
            $this->ensureJoin($subject);
        }

        return [$printQuery, $logQuery];
    }

    public function aroundAddFieldToFilter(
        Collection $subject,
        callable $proceed,
        $field,
        $condition = null,
    ): Collection {
        if ($field !== 'vendit_exported') {
            return $proceed($field, $condition);
        }

        $this->ensureJoin($subject);

        $value = is_array($condition) ? ($condition['eq'] ?? null) : $condition;
        if ((string) $value === '1') {
            $subject->getSelect()->where('veo.order_increment_id IS NOT NULL');
        } else {
            $subject->getSelect()->where('veo.order_increment_id IS NULL');
        }

        return $subject;
    }

    private function ensureJoin(Collection $subject): void
    {
        $fromPart = $subject->getSelect()->getPart(Zend_Db_Select::FROM);
        if (isset($fromPart['veo'])) {
            return;
        }

        $subject->getSelect()->joinLeft(
            ['veo' => $subject->getResource()->getTable('reachdigital_vendit_exported_orders')],
            'main_table.increment_id = veo.order_increment_id',
            ['vendit_exported' => new Zend_Db_Expr('IF(veo.order_increment_id IS NOT NULL, 1, 0)')],
        );
    }
}
