<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory as StatusCollectionFactory;
use ReachDigital\Vendit\Logger\OrderStatusLogger;
use ReachDigital\Vendit\Model\ResourceModel\ImportedOrderStatus;

class ImportOrderStatusXml
{
    private array $statusMapping = [];
    private array $processedOrders = [];
    private array $skippedOrders = [];
    private ?array $orderStateByStatus = null;

    public function __construct(
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $shipmentItemCreationFactory,
        private readonly ShipmentCommentCreationInterfaceFactory $commentCreationFactory,
        private readonly OrderStatusLogger $logger,
        private readonly StatusCollectionFactory $statusCollectionFactory,
        private readonly ImportedOrderStatus $importedOrderStatus,
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function run(): int
    {
        $this->logger->info('Starting Vendit order status import');

        $this->statusMapping = $this->config->getOrderStatusMapping();
        if (empty($this->statusMapping)) {
            $this->logger->error('No order status mapping configured');
            throw new LocalizedException(
                __(
                    'No order status mapping configured. Please configure it in Stores > Configuration > Vendit > Order Status Mapping',
                ),
            );
        }

        $xmlFilePath = $this->config->getOrderImportFilePath();
        if (!file_exists($xmlFilePath)) {
            throw new \Exception("Order status XML file not found: {$xmlFilePath}");
        }

        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read order status XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \Exception('Failed to parse order status XML file');
        }

        // Build human-readable descriptions from OrderStatusList for comments
        $statusDescriptions = $this->buildStatusDescriptions($xml);

        $this->processedOrders = [];
        $this->skippedOrders = [];
        $errors = [];

        if (isset($xml->Orders->Order)) {
            foreach ($xml->Orders->Order as $orderNode) {
                try {
                    $this->updateOrderStatus($orderNode, $statusDescriptions);
                } catch (\Exception $e) {
                    $orderNumber = (string) ($orderNode->OrderNumber ?? '');
                    $this->logger->error('Failed to update order', [
                        'order_number' => $orderNumber,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = [
                        'order_number' => $orderNumber,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $this->logger->info('Order status import completed', [
            'processed' => count($this->processedOrders),
            'skipped' => count($this->skippedOrders),
            'errors' => count($errors),
        ]);

        return Cli::RETURN_SUCCESS;
    }

    /**
     * Build human-readable status descriptions from OrderStatusList (EnumValue => StatusDescription)
     */
    private function buildStatusDescriptions(\SimpleXMLElement $xml): array
    {
        $descriptions = [];

        if (isset($xml->OrderStatusList->OrderStatus)) {
            foreach ($xml->OrderStatusList->OrderStatus as $status) {
                $enumValue = (string) ($status->EnumValue ?? '');
                $description = (string) ($status->StatusDescription ?? '');

                if ($enumValue !== '' && $description !== '') {
                    // If multiple statuses have same enum value, use the first one
                    if (!isset($descriptions[$enumValue])) {
                        $descriptions[$enumValue] = $description;
                    }
                }
            }
        }

        return $descriptions;
    }

    private function updateOrderStatus(\SimpleXMLElement $orderNode, array $statusDescriptions): void
    {
        $orderNumber = (string) ($orderNode->OrderNumber ?? '');
        $statusEnumValue = (string) ($orderNode->StatusEnumValue ?? '');

        if (empty($orderNumber)) {
            return;
        }

        // Strip 'E-' prefix that Vendit adds to order numbers
        $magentoOrderNumber = preg_replace('/^E-/', '', $orderNumber);

        if ($statusEnumValue === '') {
            return;
        }

        if ($this->importedOrderStatus->hasBeenProcessed($magentoOrderNumber, $statusEnumValue)) {
            $this->logger->info('Skipping already-processed Vendit status', [
                'order_number' => $orderNumber,
                'status_enum' => $statusEnumValue,
            ]);
            return;
        }

        // Get human-readable description from OrderStatusList
        $statusDescription = $statusDescriptions[$statusEnumValue] ?? 'Unknown Status';

        // Get order from Magento
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $magentoOrderNumber)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria);
        if ($orders->getTotalCount() === 0) {
            $reason = 'Order not found in Magento';
            $this->logger->warning('Order not found', ['order_number' => $orderNumber]);
            $this->skippedOrders[] = [
                'order_number' => $orderNumber,
                'reason' => $reason,
            ];
            return;
        }

        $order = $orders->getFirstItem();
        $currentStatus = $order->getStatus();

        // Match StatusEnumValue directly against vendit_status_id in config
        if (!isset($this->statusMapping[$statusEnumValue])) {
            // No mapping found - only add a comment, don't update status
            $order->addCommentToStatusHistory(
                sprintf(
                    'Order status update from Vendit (no mapping configured): %s (Status Enum: %s)',
                    $statusDescription,
                    $statusEnumValue,
                ),
            );
            $this->orderRepository->save($order);
            $this->importedOrderStatus->markAsProcessed($magentoOrderNumber, $statusEnumValue);

            $this->skippedOrders[] = [
                'order_number' => $orderNumber,
                'reason' => "No mapping for status enum: {$statusEnumValue}",
            ];
            return;
        }

        $magentoStatus = $this->statusMapping[$statusEnumValue];
        $magentoState = $this->getOrderStateByStatus($magentoStatus);

        // Only update if status has changed
        if ($currentStatus === $magentoStatus) {
            $reason = "Status already set to: {$magentoStatus}";
            $this->skippedOrders[] = [
                'order_number' => $orderNumber,
                'reason' => $reason,
            ];
            return;
        }

        // Create shipment if transitioning to complete state
        if ($magentoState === Order::STATE_COMPLETE) {
            if (!$order->canShip()) {
                $reason = 'Cannot create shipment - order cannot be shipped';
                $this->logger->warning('Cannot ship order', ['order_number' => $orderNumber]);
                $this->skippedOrders[] = [
                    'order_number' => $orderNumber,
                    'reason' => $reason,
                ];
                return;
            }

            try {
                $this->logger->info('Creating shipment for order', [
                    'order_number' => $orderNumber,
                    'from_status' => $currentStatus,
                    'to_status' => $magentoStatus,
                ]);
                $this->createShipment($order, $statusDescription, $statusEnumValue);

                $this->logger->info('Successfully created shipment and updated order', [
                    'order_number' => $orderNumber,
                    'status' => $magentoStatus,
                ]);

                $this->processedOrders[] = [
                    'order_number' => $orderNumber,
                    'old_status' => $currentStatus,
                    'new_status' => $magentoStatus,
                    'vendit_status' => $statusDescription,
                    'action' => 'Created shipment',
                ];
            } catch (\Exception $e) {
                $errorMessage = 'Failed to create shipment from Vendit status update: ' . $e->getMessage();
                $order->addCommentToStatusHistory($errorMessage);
                $this->orderRepository->save($order);
                throw new \Exception($errorMessage);
            }
        }

        $this->logger->info('Updating order status', [
            'order_number' => $orderNumber,
            'from_status' => $currentStatus,
            'to_status' => $magentoStatus,
            'vendit_status' => $statusDescription,
        ]);

        $order->setStatus($magentoStatus);
        $order->addCommentToStatusHistory(
            sprintf('Order status updated from Vendit: %s (Status Enum: %s)', $statusDescription, $statusEnumValue),
            $magentoStatus,
        );

        $this->orderRepository->save($order);
        $this->importedOrderStatus->markAsProcessed($magentoOrderNumber, $statusEnumValue);

        $this->logger->info('Successfully updated order status', [
            'order_number' => $orderNumber,
            'status' => $magentoStatus,
        ]);

        $this->processedOrders[] = [
            'order_number' => $orderNumber,
            'old_status' => $currentStatus,
            'new_status' => $magentoStatus,
            'vendit_status' => $statusDescription,
        ];
    }

    /**
     * @throws LocalizedException
     */
    public function getOrderStateByStatus(string $orderStatus): string
    {
        if (is_null($this->orderStateByStatus)) {
            $collection = $this->statusCollectionFactory->create()->joinStates();

            $stateByStatus = [];
            foreach ($collection as $status) {
                $stateByStatus[$status->getStatus()] = $status->getState();
            }
            $this->orderStateByStatus = $stateByStatus;
        }

        if (isset($this->orderStateByStatus[$orderStatus])) {
            return $this->orderStateByStatus[$orderStatus];
        }

        throw new LocalizedException(__('Invalid order status: %1', $orderStatus));
    }

    private function createShipment(Order $order, string $statusDescription, string $statusEnumValue): void
    {
        $items = [];
        foreach ($order->getAllItems() as $orderItem) {
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $shipmentItem = $this->shipmentItemCreationFactory->create();
            $shipmentItem->setOrderItemId($orderItem->getId());
            $shipmentItem->setQty($orderItem->getQtyToShip());
            $items[] = $shipmentItem;
        }

        if (empty($items)) {
            throw new \Exception('No items to ship');
        }

        $comment = $this->commentCreationFactory->create();
        $comment->setComment(
            sprintf(
                'Shipment automatically created from Vendit status update: %s (Status Enum: %s)',
                $statusDescription,
                $statusEnumValue,
            ),
        );
        $comment->setIsVisibleOnFront(false);

        $this->shipOrder->execute(
            $order->getEntityId(),
            $items,
            false, // notify customer
            false, // append comment
            $comment,
        );
    }
}
