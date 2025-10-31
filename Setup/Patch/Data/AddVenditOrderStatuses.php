<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;

class AddVenditOrderStatuses implements DataPatchInterface
{
    const STATUS_CODE_PREFIX = 'vendit_';

    public array $statuses = [
        'Nieuwe order' => Order::STATE_PROCESSING,
        'In behandeling' => Order::STATE_PROCESSING,
        'Voorraad gereserveerd' => Order::STATE_PROCESSING,
        'Assemblage' => Order::STATE_PROCESSING,
        'Klaar / factureren' => Order::STATE_PROCESSING,
        'Niet geactiveerd' => Order::STATE_PROCESSING,
        'Gedeeltelijk uitgeleverd' => Order::STATE_PROCESSING,
        'Uitgeleverd' => Order::STATE_PROCESSING,
        'Uitgeleverd (Complete)' => Order::STATE_COMPLETE,
        'Gefactureerd' => Order::STATE_PROCESSING,
        'Nieuwe order E-commerce' => Order::STATE_PROCESSING,
        'Wordt gepickt' => Order::STATE_PROCESSING,
        'Is gepickt' => Order::STATE_PROCESSING,
        'Incompleet gepickt' => Order::STATE_PROCESSING,
        'Vervallen' => Order::STATE_PROCESSING,
    ];

    public function __construct(
        private readonly StatusFactory $statusFactory,
        private readonly StatusResourceFactory $statusResourceFactory,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function apply(): self
    {
        /** @var StatusResource $statusResource */
        $statusResource = $this->statusResourceFactory->create();

        foreach ($this->statuses as $statusLabel => $state) {
            $statusCode = trim(preg_replace('/[^a-z0-9]+/i', '_', strtolower($statusLabel)), '_');

            $status = $this->statusFactory->create();
            $status->setData([
                'status' => self::STATUS_CODE_PREFIX . $statusCode,
                'label' => $statusLabel,
            ]);

            try {
                $statusResource->save($status);
                $status->assignState($state, false, true);
            } catch (AlreadyExistsException $exception) {
                continue;
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
