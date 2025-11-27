<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

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
    ) {
    }

    public function loadOrderStatuses(): array
    {
        $xmlFilePath = $this->config->getOrderImportFilePath();
        if (!file_exists($xmlFilePath)) {
            throw new \Exception("Order status XML file not found: {$xmlFilePath}");
        }

        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read order status XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \Exception('Failed to parse order status XML file');
        }

        $orders = [];
        if (isset($xml->Orders->Order)) {
            foreach ($xml->Orders->Order as $orderNode) {
                $orderNumber = (string) ($orderNode->OrderNumber ?? '');
                $orderStatusId = (string) ($orderNode->OrderStatusId ?? '');
                $statusDescription = (string) ($orderNode->StatusDescription ?? '');
                $orderStatusDate = (string) ($orderNode->OrderStatusDate ?? '');

                if (empty($orderNumber) || empty($orderStatusId)) {
                    continue;
                }

                $orders[] = [
                    'order_number' => $orderNumber,
                    'vendit_status_id' => $orderStatusId,
                    'status_description' => $statusDescription,
                    'status_date' => $orderStatusDate,
                ];
            }
        }

        return $orders;
    }

    public function run(): void
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

        $orders = $this->loadOrderStatuses();

        $this->processedOrders = [];
        $this->skippedOrders = [];
        $errors = [];

        foreach ($orders as $orderData) {
            try {
                $this->updateOrderStatus($orderData);
            } catch (\Exception $e) {
                $errorMsg = $e->getMessage();
                $this->logger->error('Failed to update order', [
                    'order_number' => $orderData['order_number'],
                    'error' => $errorMsg,
                ]);
                $errors[] = [
                    'order_number' => $orderData['order_number'],
                    'error' => $errorMsg,
                ];
            }
        }

        $this->logger->info('Order status import completed', [
            'processed' => count($this->processedOrders),
            'skipped' => count($this->skippedOrders),
            'errors' => count($errors),
        ]);
    }

    private function updateOrderStatus(array $orderData): void
    {
        $orderNumber = $orderData['order_number'];
        $venditStatusId = $orderData['vendit_status_id'];

        // Check if we have a mapping for this Vendit status
        if (!isset($this->statusMapping[$venditStatusId])) {
            $reason = "No mapping found for Vendit status ID: {$venditStatusId}";
            $this->logger->debug('Skipping order', ['order_number' => $orderNumber, 'reason' => $reason]);
            $this->skippedOrders[] = [
                'order_number' => $orderNumber,
                'reason' => $reason,
            ];
            return;
        }

        $magentoStatus = $this->statusMapping[$venditStatusId];
        $magentoState = $this->getOrderStateByStatus($magentoStatus);

        $searchCriteria = $this->searchCriteriaBuilder->addFilter(OrderInterface::INCREMENT_ID, $orderNumber)->create();

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

        // Only update if status has changed
        if ($currentStatus === $magentoStatus) {
            $reason = "Status already set to: {$magentoStatus}";
            $this->logger->debug('Status unchanged', ['order_number' => $orderNumber, 'status' => $magentoStatus]);
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
                $this->createShipment($order, $orderData);

                $this->logger->info('Successfully created shipment and updated order', [
                    'order_number' => $orderNumber,
                    'status' => $magentoStatus,
                ]);

                $this->processedOrders[] = [
                    'order_number' => $orderNumber,
                    'old_status' => $currentStatus,
                    'new_status' => $magentoStatus,
                    'vendit_status' => $orderData['status_description'],
                    'action' => 'Created shipment',
                ];
            } catch (\Exception $e) {
                throw new \Exception('Failed to create shipment: ' . $e->getMessage());
            }
        }

        $this->logger->info('Updating order status', [
            'order_number' => $orderNumber,
            'from_status' => $currentStatus,
            'to_status' => $magentoStatus,
            'vendit_status' => $orderData['status_description'],
        ]);

        $order->setStatus($magentoStatus);
        $order->addCommentToStatusHistory(
            sprintf(
                'Order status updated from Vendit. Status: %s (Vendit Status ID: %s)',
                $orderData['status_description'],
                $venditStatusId,
            ),
            $magentoStatus,
        );

        $this->orderRepository->save($order);

        $this->logger->info('Successfully updated order status', [
            'order_number' => $orderNumber,
            'status' => $magentoStatus,
        ]);

        $this->processedOrders[] = [
            'order_number' => $orderNumber,
            'old_status' => $currentStatus,
            'new_status' => $magentoStatus,
            'vendit_status' => $orderData['status_description'],
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

    private function createShipment(Order $order, array $orderData): void
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
                'Shipment automatically created from Vendit status update. Status: %s (Vendit Status ID: %s)',
                $orderData['status_description'],
                $orderData['vendit_status_id'],
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
