<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Ho\Import\Logger\Log;
use Ho\Import\Model\ImportProfile;
use Ho\Import\RowModifier\ItemMapperFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class ImportPricesXml extends ImportProfile
{
    /**
     * Pre-check getItems() before delegating to the parent. When no XML items
     * match existing Magento products the result is empty, which would crash
     * the importer with "Empty column names". We detect this early and return
     * success — there is simply nothing to import.
     */
    public function run()
    {
        if (empty($this->getItems())) {
            $this->log->info('Price import: no matching items found, nothing to import.');
            return Cli::RETURN_SUCCESS;
        }

        return parent::run();
    }

    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        Stopwatch $stopwatch,
        ConsoleOutput $consoleOutput,
        Log $log,
        private readonly ItemMapperFactory $itemMapperFactory,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly ResourceConnection $resourceConnection,
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
            'skip_url_generation' => true,
        ];
    }

    public function getItems(): array
    {
        $priceItems = $this->loadPrices();

        // Collect all barcodes and resolve which ones exist as SKUs
        $allBarcodes = [];
        foreach ($priceItems as $item) {
            $barcodes = $item['Barcodes']['Barcode'] ?? null;
            if ($barcodes === null) {
                continue;
            }
            if (!is_array($barcodes)) {
                $barcodes = [$barcodes];
            }
            foreach ($barcodes as $barcode) {
                $allBarcodes[] = (string) $barcode;
            }
        }
        $allBarcodes = array_unique($allBarcodes);
        $existingBarcodeSkus = !empty($allBarcodes) ? $this->getExistingSkus($allBarcodes) : [];

        $mapping = [
            'sku' => function ($item) use ($existingBarcodeSkus) {
                $barcodes = $item['Barcodes']['Barcode'] ?? null;
                if ($barcodes !== null) {
                    if (!is_array($barcodes)) {
                        $barcodes = [$barcodes];
                    }
                    foreach ($barcodes as $barcode) {
                        if (isset($existingBarcodeSkus[(string) $barcode])) {
                            return (string) $barcode;
                        }
                    }
                    return (string) ($barcodes[0] ?? '');
                }
                return '';
            },
            'price' => function ($item) {
                return isset($item['SalesPriceInc']) && is_numeric($item['SalesPriceInc'])
                    ? round((float) $item['SalesPriceInc'], 2)
                    : null;
            },
            'special_price' => function ($item) {
                $actionPrice = $this->getActiveActionPrice($item);
                return $actionPrice !== null ? round((float) $actionPrice['ActionPriceInc'], 2) : '';
            },
            'special_from_date' => function ($item) {
                $actionPrice = $this->getActiveActionPrice($item);
                return $actionPrice !== null ? (string) $actionPrice['ActionStart'] : '';
            },
            'special_to_date' => function ($item) {
                $actionPrice = $this->getActiveActionPrice($item);
                return $actionPrice !== null ? (string) $actionPrice['ActionEnd'] : '';
            },
        ];

        $itemMapper = $this->itemMapperFactory->create([
            'mapping' => $mapping,
        ]);

        $itemMapper->setItems($priceItems);
        $itemMapper->process();

        $priceItems = array_filter($priceItems, fn($item) => !empty($item['sku']));

        // Filter out items whose SKU does not exist in Magento
        $skus = array_column(array_values($priceItems), 'sku');
        $existingSkus = $this->getExistingSkus($skus);
        $skipped = 0;
        $priceItems = array_filter($priceItems, function ($item) use ($existingSkus, &$skipped) {
            if (!isset($existingSkus[$item['sku']])) {
                $skipped++;
                return false;
            }
            return true;
        });

        // Filter out items with no valid price
        $priceItems = array_filter($priceItems, fn($item) => $item['price'] !== null);

        if ($skipped > 0) {
            $this->log->info("Price import: skipped {$skipped} item(s) with no matching Magento product.");
        }

        $this->saveProcessedItems($priceItems);

        return $priceItems;
    }

    /**
     * Get the lowest currently active action price from the variation, or null if none active.
     */
    public function getActiveActionPrice(array $item): ?array
    {
        $actionPrices = $item['ActionPrices']['ActionPrice'] ?? null;
        if ($actionPrices === null) {
            return null;
        }

        // Single action price comes as associative array, multiple as indexed array
        if (isset($actionPrices['ActionPriceInc'])) {
            $actionPrices = [$actionPrices];
        }

        $lowestPrice = null;
        foreach ($actionPrices as $actionPrice) {
            if (!isset($actionPrice['ActionPriceInc']) || !is_numeric($actionPrice['ActionPriceInc'])) {
                continue;
            }

            $price = (float) $actionPrice['ActionPriceInc'];
            if ($lowestPrice === null || $price < (float) $lowestPrice['ActionPriceInc']) {
                $lowestPrice = $actionPrice;
            }
        }

        return $lowestPrice;
    }

    /**
     * Load product variations with prices from Products XML.
     *
     * Flattens the Products > Product > ProductVariations > ProductVariation
     * structure into a flat list of variations with their barcodes and prices.
     */
    public function loadPrices(): array
    {
        $xmlFilePath = $this->config->getPriceImportFilePath();
        if (!file_exists($xmlFilePath)) {
            $this->log->error("Products XML file not found: {$xmlFilePath}");
            throw new \Exception("Products XML file not found: {$xmlFilePath}");
        }

        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read Products XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new \Exception('Failed to parse Products XML file');
        }

        $items = [];
        foreach ($xml->Products->Product as $productNode) {
            if (!isset($productNode->ProductVariations->ProductVariation)) {
                continue;
            }

            foreach ($productNode->ProductVariations->ProductVariation as $variationNode) {
                $variationArray = json_decode(json_encode($variationNode), true);
                $guid = (string) ($variationArray['EcommerceProductVariationGuid'] ?? '');
                if (empty($guid)) {
                    continue;
                }
                $items[$guid] = $variationArray;
            }
        }

        return $items;
    }

    /**
     * Return a map of sku => true for all given SKUs that exist in catalog_product_entity.
     *
     * @param string[] $skus
     * @return array<string, true>
     */
    public function getExistingSkus(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $rows = $connection->fetchCol(
            $connection->select()->from($table, ['sku'])->where('sku IN (?)', $skus),
        );

        return array_fill_keys($rows, true);
    }

    /**
     * Save mapped items as json for debugging.
     *
     * @throws FileSystemException
     */
    public function saveProcessedItems(array $items): void
    {
        $directory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $stream = $directory->openFile('importexport/price_import.json', 'w+');
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
