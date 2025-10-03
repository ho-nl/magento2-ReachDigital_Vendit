<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use DOMDocument;
use DOMElement;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ExportStockXml
{
    const NODE_TYPE_STRING = 'string';
    const NODE_TYPE_NUMBER = 'number';

    public function __construct(
        public CollectionFactory $productCollectionFactory,
        public CategoryRepository $categoryRepo,
        public File $file,
        public DateTime $dateTime,
        public DirectoryList $directoryList,
        public IoFile $ioFile,
        public ExportCategoriesXml $exportCategoriesXml,
        public ProductAttributeRepositoryInterface $productAttributeRepository,
        public SearchCriteriaBuilder $searchCriteriaBuilder,
        public StockRegistryInterface $stockRegistry,
        public Config $venditConfig,
    ) {
    }

    public function execute(bool $debug = false): bool|int
    {
        $doc = new DOMDocument('1.0', 'utf-16');
        $doc->formatOutput = true;

        $root = $doc->createElement('StockExport');
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance',
        );
        $doc->appendChild($root);

        // ExportInfo
        $info = $doc->createElement('ExportInfo');
        $info->appendChild($doc->createElement('ExportDateTime', $this->dateTime->gmtDate('c')));
        $info->appendChild($doc->createElement('Type', 'Full'));
        $info->appendChild($doc->createElement('ExportStarted', 'Manual'));
        $root->appendChild($info);

        $productsNode = $doc->createElement('Products');

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->setPageSize(100);
        // Only retrieve simple products, since these are the ones that have stock information
        $collection->addAttributeToFilter('type_id', Type::TYPE_SIMPLE);

        $s = microtime(true);
        $page = $p = 1;
        do {
            $collection->setCurPage($page);
            $collection->load();

            foreach ($collection as $product) {
                if ($debug && $p % 500 == 0) {
                    echo sprintf('Exported %d products, %s memory in use', $p, $this->getMemoryUsage()) . PHP_EOL;
                }

                /** @var \Magento\Catalog\Model\Product $product */
                $productNode = $doc->createElement('Product');

                $stockItem = $this->stockRegistry->getStockItem($product->getId());
                $availabilityStatus = $stockItem->getIsInStock() ? 'Leverbaar' : 'Niet leverbaar';

                $elements = [
                    'EcommerceProductGuid' => $this->getGuid((string) $product->getId()),
                    'ProductId' => $product->getId(),
                    'Quantity' => number_format($stockItem->getQty() ?? 0, 2, '.', ''),
                    'AvailabilityStatus' => $availabilityStatus,
                ];

                foreach ($elements as $name => $value) {
                    $productNode->appendChild($doc->createElement($name, htmlspecialchars($value)));
                }

                $barcodes = $doc->createElement('Barcodes');
                $barcode = $doc->createElement('Barcode', $product->getData('codeeanbarcode4') ?? '');
                $domAttribute = $doc->createAttribute('BarcodeOrder');
                $domAttribute->value = '1';
                $barcode->appendChild($domAttribute);
                $barcodes->appendChild($barcode);
                $productNode->appendChild($barcodes);

                $productsNode->appendChild($productNode);
                $p++;
            }

            $collection->clear(); // Clear memory
            $page++;
        } while ($page <= $collection->getLastPageNumber());

        if ($debug) {
            echo sprintf('Export duration: %ss', number_format(microtime(true) - $s, 4)) . PHP_EOL;
        }

        $root->appendChild($productsNode);

        return $doc->save($this->getFilePath());
    }

    public function getFilePath(): string
    {
        return $this->venditConfig->getStockExportFilePath();
    }

    public function getMemoryUsage(): string
    {
        $size = memory_get_usage(true);
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        return @round($size / pow(1024, $i = floor(log($size, 1024))), 2) . ' ' . $unit[$i];
    }

    /**
     * Generate a GUID based on the given string (e.g., product ID).
     */
    private function getGuid(string $id): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5($id), 4));
    }
}
