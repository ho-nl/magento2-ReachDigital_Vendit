<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as OrderStatusCollectionFactory;

class OrderStatuses implements OptionSourceInterface
{
    private array $states = [
        Order::STATE_NEW,
        Order::STATE_PENDING_PAYMENT,
        Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
        Order::STATE_CLOSED,
        Order::STATE_CANCELED,
        Order::STATE_HOLDED,
        Order::STATE_PAYMENT_REVIEW,
    ];

    public function __construct(private readonly OrderStatusCollectionFactory $orderStatusCollectionFactory)
    {
    }

    public function toOptionArray(): array
    {
        $options = [
            [
                'label' => __('-- Please Select --'),
                'value' => '',
            ],
        ];

        foreach ($this->states as $code) {
            $statusCollection = $this->orderStatusCollectionFactory->create()->addStateFilter($code);

            $groupedOptions = [];
            foreach ($statusCollection as $status) {
                $groupedOptions[] = [
                    'label' => sprintf('%s (%s)', $status->getLabel(), $status->getStatus()),
                    'value' => $status->getStatus(),
                ];
            }

            $options[] = ['label' => sprintf('%s:', $code), 'value' => $groupedOptions];
        }

        return $options;
    }
}
