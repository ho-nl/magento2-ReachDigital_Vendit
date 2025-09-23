<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use ReachDigital\Vendit\Model\ResourceModel\ExportedOrder as ExportedOrderResource;

class ExportOrdersXml
{
    const FILENAME = 'orders.xml';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly DateTime $dateTime,
        private readonly Config $venditConfig,
        private readonly ExportedOrderResource $exportedOrderResource, // add this
    ) {
    }

    /**
     * @todo prevent exporting when file already exists?
     *       wait until the file is read or moved by Vendit?
     */
    public function execute(): void
    {
        $doc = new \DOMDocument('1.0', 'utf-16');
        $doc->formatOutput = true;

        $orderImport = $doc->createElement('OrderImport');
        $orderImport->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $orderImport->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $doc->appendChild($orderImport);

        $info = $doc->createElement('ImportInfo');
        $orderImport->appendChild($info);
        $info->appendChild($doc->createElement('ExportDateTime', $this->dateTime->gmtDate(DATE_ATOM)));

        $orders = $doc->createElement('Orders');
        $orderImport->appendChild($orders);

        // Get increment IDs of already exported orders
        $exportedOrderIds = $this->exportedOrderResource->getAllExportedOrderIds();

        $searchCriteriaBuilder = $this->searchCriteriaBuilder->addFilter('status', 'processing');
        if (!empty($exportedOrderIds)) {
            $searchCriteriaBuilder->addFilter('increment_id', $exportedOrderIds, 'nin');
        }
        $orderRepository = $this->orderRepository->getList($searchCriteriaBuilder->create());

        foreach ($orderRepository->getItems() as $order) {
            // Export order as XML
            $this->addOrderToXml($doc, $orders, $order);
            // Mark order as exported
            $this->exportedOrderResource->markOrderAsExported((string) $order->getIncrementId());
        }

        $doc->save($this->getFilePath());
    }

    public function addOrderToXml(\DOMDocument $doc, \DOMElement $parentNode, $order): void
    {
        $orderNode = $doc->createElement('Order');
        $parentNode->appendChild($orderNode);

        // Order basics
        $orderNode->appendChild($doc->createElement('OrderNumber', $order->getIncrementId()));
        $orderNode->appendChild($doc->createElement('StoreNumber', $order->getStoreId()));
        $orderNode->appendChild($doc->createElement('OrderType', 'Order'));
        $orderNode->appendChild($doc->createElement('OrderDate', $order->getCreatedAt()));
        $orderNode->appendChild($doc->createElement('TotalOrderAmount', number_format((float) $order->getGrandTotal(), 4, '.', '')));
        $orderNode->appendChild($doc->createElement('PaymentMethod', $order->getPayment() ? $order->getPayment()->getMethod() : ''));
        $orderNode->appendChild($doc->createElement('Paid', number_format((float) $order->getTotalPaid(), 4, '.', '')));
        $orderNode->appendChild($doc->createElement('ShippingMethod', $order->getShippingDescription()));
        $orderNode->appendChild($doc->createElement('ShippingCosts', number_format((float) $order->getShippingInclTax(), 4, '.', '')));
        $orderNode->appendChild($doc->createElement('InvoiceDiscountAmount', number_format((float) $order->getDiscountAmount(), 4, '.', '')));

        // Invoice (billing) address
        $billing = $order->getBillingAddress();
        if ($billing) {
            $orderNode->appendChild($doc->createElement('FirstName', $billing->getFirstname()));
            $orderNode->appendChild($doc->createElement('LastName', $billing->getLastname()));
            $orderNode->appendChild($doc->createElement('EmailAddress', $order->getCustomerEmail()));
            $orderNode->appendChild($doc->createElement('Phone', $billing->getTelephone()));
            $orderNode->appendChild($doc->createElement('InvoiceAddress', $billing->getStreetLine(1)));
            $orderNode->appendChild($doc->createElement('InvoiceHousenumber', $billing->getStreetLine(2) ?: ''));
            $orderNode->appendChild($doc->createElement('InvoiceHousenumberExtension', $billing->getStreetLine(3) ?: ''));
            $orderNode->appendChild($doc->createElement('InvoiceZipcode', $billing->getPostcode()));
            $orderNode->appendChild($doc->createElement('InvoiceCity', $billing->getCity()));
            $orderNode->appendChild($doc->createElement('InvoiceCountry', $billing->getCountryId())); // @todo country full name
            $orderNode->appendChild($doc->createElement('InvoiceCountryCode', $billing->getCountryId()));
        }

        // @todo implement, get IDs from Vendit
        $orderNode->appendChild($doc->createElement('OrderStatusId', ''));

        // Delivery (shipping) address
        $shipping = $order->getShippingAddress();
        if ($shipping) {
            $orderNode->appendChild($doc->createElement('DeliveryTitle', $shipping->getPrefix() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryFirstName', $shipping->getFirstname() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryMiddleName', $shipping->getMiddlename() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryLastName', $shipping->getLastname() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryAddress', $shipping->getStreetLine(1) ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryHousenumber', $shipping->getStreetLine(2) ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryHousenumberExtension', $shipping->getStreetLine(3) ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryZipcode', $shipping->getPostcode() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCity', $shipping->getCity() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCountry', $shipping->getCountryId() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCountryCode', $shipping->getCountryId() ?: ''));
        }

        // Products
        $productsNode = $doc->createElement('Products');
        foreach ($order->getAllVisibleItems() as $item) {
            $productNode = $doc->createElement('Product');
            $productNode->appendChild($doc->createElement('Sku', $item->getSku()));
            $productNode->appendChild($doc->createElement('Name', htmlentities($item->getName())));
            $productNode->appendChild($doc->createElement('Qty', $item->getQtyOrdered()));
            $productNode->appendChild($doc->createElement('Price', number_format((float)$item->getPriceInclTax(), 4, '.', '')));
            $productNode->appendChild($doc->createElement('RowTotal', number_format((float)$item->getRowTotalInclTax(), 4, '.', '')));
            $productsNode->appendChild($productNode);
        }
        $orderNode->appendChild($productsNode);

        // @todo implement after customer sync implementation?
        // $orderNode->appendChild($doc->createElement('CustomerNumber', ''));
    }

    public function getFilename(): string
    {
        return self::FILENAME;
    }

    public function getFilePath(): string
    {
        return $this->venditConfig->getFilePath($this->getFilename());
    }
}
