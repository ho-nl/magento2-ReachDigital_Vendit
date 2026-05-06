<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use ReachDigital\Vendit\Model\ResourceModel\ExportedOrder as ExportedOrderResource;

class ExportOrdersXml
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly DateTime $dateTime,
        private readonly Config $venditConfig,
        private readonly ExportedOrderResource $exportedOrderResource,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
    }

    public function execute(): int
    {
        // Get increment IDs of already exported orders
        $exportedOrderIds = $this->exportedOrderResource->getAllExportedOrderIds();

        $searchCriteriaBuilder = $this->searchCriteriaBuilder->addFilter('status', 'processing');
        if (!empty($exportedOrderIds)) {
            $searchCriteriaBuilder->addFilter('increment_id', $exportedOrderIds, 'nin');
        }
        $orderRepository = $this->orderRepository->getList($searchCriteriaBuilder->create());

        $exportTimestamp = $this->dateTime->gmtDate(DATE_ATOM);
        $i = 0;

        foreach ($orderRepository->getItems() as $order) {
            // Create separate XML file for each order
            $this->exportSingleOrder($order, $exportTimestamp);

            // Mark order as exported
            $this->exportedOrderResource->markOrderAsExported((string) $order->getIncrementId());

            $i++;
        }

        return $i;
    }

    private function exportSingleOrder($order, string $exportTimestamp): void
    {
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;

        $orderImport = $doc->createElement('OrderImport');
        $orderImport->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $orderImport->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $doc->appendChild($orderImport);

        $info = $doc->createElement('ImportInfo');
        $orderImport->appendChild($info);
        $info->appendChild($doc->createElement('ExportDateTime', $exportTimestamp));

        $orders = $doc->createElement('Orders');
        $orderImport->appendChild($orders);

        // Export order as XML
        $this->addOrderToXml($doc, $orders, $order);

        // Generate filename with order increment ID and timestamp
        $filename = $this->generateFilename($order, $exportTimestamp);
        $filePath = $this->getExportDirectory() . DIRECTORY_SEPARATOR . $filename;

        $xml = $doc->saveXML();
        file_put_contents($filePath, $xml);
    }

    public function addOrderToXml(\DOMDocument $doc, \DOMElement $parentNode, $order): void
    {
        $orderNode = $doc->createElement('Order');
        $parentNode->appendChild($orderNode);

        // Order basics
        $orderNode->appendChild($doc->createElement('OrderNumber', $order->getIncrementId()));
        $orderNode->appendChild($doc->createElement('StoreNumber', $order->getStoreId()));
        $orderNode->appendChild($doc->createElement('OrderType', 'Order'));

        // OrderDate format: yyyy-MM-dd HH:mm:ss
        $orderDate = (new \DateTime($order->getCreatedAt()))->format('Y-m-d H:i:s');
        $orderNode->appendChild($doc->createElement('OrderDate', $orderDate));

        $orderNode->appendChild($doc->createElement('TotalOrderAmount', $this->formatDecimal($order->getGrandTotal())));
        $orderNode->appendChild(
            $doc->createElement('PaymentMethod', $order->getPayment() ? $order->getPayment()->getMethod() : ''),
        );
        $orderNode->appendChild($doc->createElement('PaymentCosts', $this->formatDecimal(0.0)));
        $orderNode->appendChild($doc->createElement('Paid', $this->formatDecimal($order->getTotalPaid())));
        $orderNode->appendChild($doc->createElement('ShippingMethod', $order->getShippingDescription()));
        $orderNode->appendChild(
            $doc->createElement('ShippingCosts', $this->formatDecimal($order->getShippingInclTax())),
        );

        // Discount
        $orderNode->appendChild($doc->createElement('InvoiceDiscountName', $order->getDiscountDescription() ?: ''));
        $orderNode->appendChild(
            $doc->createElement(
                'InvoiceDiscountAmount',
                $this->formatDecimal(abs((float) $order->getDiscountAmount())),
            ),
        );

        // Invoice (billing) address
        $billing = $order->getBillingAddress();
        if ($billing) {
            $orderNode->appendChild($doc->createElement('Title', $billing->getPrefix() ?: ''));
            $orderNode->appendChild($doc->createElement('FirstName', $billing->getFirstname()));
            $orderNode->appendChild($doc->createElement('MiddleName', $billing->getMiddlename() ?: ''));
            $orderNode->appendChild($doc->createElement('LastName', $billing->getLastname()));
            $orderNode->appendChild($doc->createElement('CompanyName', $billing->getCompany() ?: ''));
            $orderNode->appendChild($doc->createElement('EmailAddress', $order->getCustomerEmail()));
            $orderNode->appendChild($doc->createElement('Phone', $billing->getTelephone()));
            $orderNode->appendChild($doc->createElement('PhoneMobile', $billing->getTelephone()));
            $orderNode->appendChild($doc->createElement('InvoiceAddress', $billing->getStreetLine(1)));
            $orderNode->appendChild($doc->createElement('InvoiceHousenumber', $billing->getStreetLine(2) ?: ''));
            $orderNode->appendChild(
                $doc->createElement('InvoiceHousenumberExtension', $billing->getStreetLine(3) ?: ''),
            );
            $orderNode->appendChild($doc->createElement('InvoiceZipcode', $billing->getPostcode()));
            $orderNode->appendChild($doc->createElement('InvoiceCity', $billing->getCity()));
            $orderNode->appendChild($doc->createElement('InvoiceCountry', $billing->getCountryId()));
            $orderNode->appendChild($doc->createElement('InvoiceCountryCode', $billing->getCountryId()));
        }

        // Get Vendit status ID from config mapping
        $venditStatusId = $this->venditConfig->getVenditStatusIdForMagentoStatus($order->getStatus());
        $orderNode->appendChild($doc->createElement('OrderStatusId', $venditStatusId));

        // Delivery (shipping) address
        $shipping = $order->getShippingAddress();
        if ($shipping) {
            $orderNode->appendChild($doc->createElement('DeliveryTitle', $shipping->getPrefix() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryFirstName', $shipping->getFirstname() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryMiddleName', $shipping->getMiddlename() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryLastName', $shipping->getLastname() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCompanyName', $shipping->getCompany() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryAddress', $shipping->getStreetLine(1) ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryHousenumber', $shipping->getStreetLine(2) ?: ''));
            $orderNode->appendChild(
                $doc->createElement('DeliveryHousenumberExtension', $shipping->getStreetLine(3) ?: ''),
            );
            $orderNode->appendChild($doc->createElement('DeliveryZipcode', $shipping->getPostcode() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCity', $shipping->getCity() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCountry', $shipping->getCountryId() ?: ''));
            $orderNode->appendChild($doc->createElement('DeliveryCountryCode', $shipping->getCountryId() ?: ''));
        }

        // Products
        $productsNode = $doc->createElement('Products');
        foreach ($order->getAllVisibleItems() as $item) {
            $product = $item->getProduct();

            if ($product && $product->getTypeId() === 'configurable') {
                try {
                    $product = $this->productRepository->get($item->getSku());
                } catch (\Exception) {
                    // Fall back to parent product
                }
            }

            $barcodeAttribute = $this->venditConfig->getBarcodeAttribute();
            $productNode = $doc->createElement('Product');

            // EcommerceProductGuid and ProductId must only be included together as a valid pair.
            // If the GUID is not stored on the product, omit both and identify via EAN only.
            $guid = $product ? (string) $product->getData('vendit_product_guid') : '';
            if ($guid !== '') {
                $productNode->appendChild($doc->createElement('EcommerceProductGuid', $guid));
                $productNode->appendChild($doc->createElement('ProductId', $item->getSku()));
            }

            // EAN/Barcode — primary identifier when GUID+ProductId are unavailable
            $ean = $barcodeAttribute && $product ? (string) $product->getData($barcodeAttribute) : '';
            $productNode->appendChild($doc->createElement('EAN', $ean));

            // Unit prices (not totals)
            $productNode->appendChild(
                $doc->createElement('ProductSalesPriceEx', $this->formatDecimal($item->getPrice())),
            );
            $productNode->appendChild(
                $doc->createElement('ProductSalesPriceInc', $this->formatDecimal($item->getPriceInclTax())),
            );

            // @todo Implement supporting PrivateCopyLevy (thuiskopieheffing) for specific product categories

            $productNode->appendChild($doc->createElement('Quantity', $this->formatDecimal($item->getQtyOrdered())));
            $productNode->appendChild($doc->createElement('Remarks', ''));
            $productNode->appendChild($doc->createElement('OfficeId', '1'));
            $productNode->appendChild($doc->createElement('ReserveStock', 'true'));

            // Description is optional but used by Vendit as fallback when product cannot be matched
            $productNode->appendChild($doc->createElement('Description', htmlentities($item->getName())));

            $productNode->appendChild($doc->createElement('IsDropshipment', 'false'));

            $productsNode->appendChild($productNode);
        }
        $orderNode->appendChild($productsNode);

        // @todo implement after customer sync implementation?
        // $orderNode->appendChild($doc->createElement('CustomerNumber', ''));
    }

    private function generateFilename($order, string $exportTimestamp): string
    {
        // Get order increment ID and sanitize it
        $orderIncrementId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $order->getIncrementId());

        // Format timestamp for filename (YmdHis format)
        try {
            $timestamp = (new \DateTime($exportTimestamp))->format('YmdHis');
        } catch (\Exception $e) {
            $timestamp = date('YmdHis');
        }

        // Get filename template from config (e.g., "Order_%s.xml")
        $filenameTemplate = basename($this->venditConfig->getOrderExportFilePath());

        // Replace %s placeholders with order increment ID and timestamp
        // Example: Order_123456_20250130120000.xml
        $replacement = $orderIncrementId . '_' . $timestamp;

        return sprintf($filenameTemplate, $replacement);
    }

    private function getExportDirectory(): string
    {
        $filePath = $this->venditConfig->getOrderExportFilePath();
        return dirname($filePath);
    }

    private function formatDecimal(float|string|null $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
