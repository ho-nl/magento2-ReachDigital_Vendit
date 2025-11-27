<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Ho\Import\Logger\Log;
use Ho\Import\Model\ImportProfile;
use Ho\Import\RowModifier\ItemMapperFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class ImportStockXml extends ImportProfile
{
    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        Stopwatch $stopwatch,
        ConsoleOutput $consoleOutput,
        Log $log,
        private readonly ItemMapperFactory $itemMapperFactory,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
    ) {
        parent::__construct($objectManagerFactory, $stopwatch, $consoleOutput, $log);
    }

    public function getConfig(): array
    {
        return [
            'behavior' => Import::BEHAVIOR_APPEND,
            'entity' => Product::ENTITY,
            'validation_strategy' => ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_STOP_ON_ERROR,
            'allowed_error_count' => 0,
        ];
    }

    public function getItems(): array
    {
        // Load all stock data from XML file
        $stockItems = $this->loadStock();

        // Get barcode attribute from config
        $barcodeAttribute = $this->config->getBarcodeAttribute();
        if (empty($barcodeAttribute)) {
            $this->log->error('Barcode attribute not configured in system config');
            throw new \Exception(
                'Barcode attribute not configured. Please configure it in Stores > Configuration > Vendit > Product Attribute Mapping',
            );
        }

        // Build barcode to SKU mapping upfront
        $barcodeToSku = $this->buildBarcodeToSkuMapping($barcodeAttribute, array_keys($stockItems));

        // Map stock data to Magento product import format
        $mapping = [
            'sku' => function ($item) use ($barcodeToSku) {
                $barcode = $item['Barcode'] ?? null;
                return $barcode ? $barcodeToSku[$barcode] ?? null : null;
            },
            'qty' => function ($item) {
                return isset($item['Quantity']) && is_numeric($item['Quantity']) ? (float) $item['Quantity'] : 0;
            },
            'is_in_stock' => function ($item) {
                $qty = isset($item['Quantity']) && is_numeric($item['Quantity']) ? (float) $item['Quantity'] : 0;

                // Also check availability status
                $status = $item['AvailabilityStatus'] ?? '';
                $isAvailable = (string) $status === 'Leverbaar';

                return $qty > 0 && $isAvailable ? '1' : '0';
            },
        ];

        $itemMapper = $this->itemMapperFactory->create([
            'mapping' => $mapping,
        ]);

        $itemMapper->setItems($stockItems);
        $itemMapper->process();

        // Filter out items without SKU (couldn't match barcode)
        $stockItems = array_filter($stockItems, function ($item) {
            if (empty($item['sku'])) {
                return false;
            }
            return true;
        });

        // Save processed items for debugging
        $this->saveProcessedItems($stockItems);

        return $stockItems;
    }

    /**
     * Build a mapping of barcode to SKU for all barcodes in a single query.
     *
     * @param string $barcodeAttribute
     * @param array $barcodes
     * @return array
     */
    private function buildBarcodeToSkuMapping(string $barcodeAttribute, array $barcodes): array
    {
        if (empty($barcodes)) {
            return [];
        }

        try {
            $filter = $this->filterBuilder
                ->setField($barcodeAttribute)
                ->setValue($barcodes)
                ->setConditionType('in')
                ->create();

            $searchCriteria = $this->searchCriteriaBuilder->addFilters([$filter])->create();

            $searchResults = $this->productRepository->getList($searchCriteria);

            $mapping = [];
            foreach ($searchResults->getItems() as $product) {
                $barcode = $product->getData($barcodeAttribute);
                if ($barcode) {
                    $mapping[$barcode] = $product->getSku();
                }
            }

            return $mapping;
        } catch (\Exception $e) {
            $this->log->error('Failed to build barcode to SKU mapping: ' . $e->getMessage());
            return [];
        }
    }

    public function loadStock(): array
    {
        $xmlFilePath = $this->config->getStockImportFilePath();
        if (!file_exists($xmlFilePath)) {
            $this->log->error("Stock XML file not found: {$xmlFilePath}");
            throw new \Exception("Stock XML file not found: {$xmlFilePath}");
        }

        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read stock XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \Exception('Failed to parse stock XML file');
        }

        $items = [];
        foreach ($xml->Products->Product as $productNode) {
            $productArray = json_decode(json_encode($productNode), true);

            // Extract the first barcode if multiple exist
            $barcode = null;
            if (isset($productArray['Barcodes']['Barcode'])) {
                if (is_array($productArray['Barcodes']['Barcode'])) {
                    $barcode = $productArray['Barcodes']['Barcode'][0] ?? null;
                } else {
                    $barcode = $productArray['Barcodes']['Barcode'];
                }
            }

            if (empty($barcode)) {
                continue;
            }

            $productArray['Barcode'] = (string) $barcode;
            $items[$barcode] = $productArray;
        }

        return $items;
    }

    /**
     * Save mapped items as json for debugging.
     *
     * @throws FileSystemException
     */
    public function saveProcessedItems(array $items): void
    {
        $directory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $stream = $directory->openFile('importexport/stock_import.json', 'w+');
        $stream->lock();
        $stream->write("{\n");

        $i = 1;
        foreach ($items as $item) {
            $stream->write("\"$i\":" . json_encode($item) . (count($items) == $i ? '' : ',') . "\n");
            $i++;
        }

        $stream->write("}\n");
        $stream->unlock();
    }
}
