<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Adapter;

class ImportProductsXml
{
    const FILENAME = 'Products.xml';

    public function __construct(
        private readonly Config $config,
        private readonly Import $importModel,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function execute(): void
    {
        $xmlFile = $this->config->getFilePath(self::FILENAME, Config::DIRECTORY_IMPORT);

        if (!file_exists($xmlFile)) {
            throw new \Exception(sprintf('Products import XML file not found (%s)', self::FILENAME));
        }
        $xml = simplexml_load_file($xmlFile);

        $products = [];
        foreach ($xml->Products->Product as $productNode) {
            $isConfigurable = isset($productNode->ProductVariations);
            if ($isConfigurable) {
                $configurable = $this->mapConfigurableProduct($productNode);

                $variations = [];
                foreach ($productNode->ProductVariations->ProductVariation as $variationNode) {
                    $variation = $this->mapProductVariation($variationNode);
                    $products[] = $variation;
                    $variations[] = $variation;
                }

                // @todo variable configurable product attributes, based on product type
                $configurable['configurable_variations'] = implode(
                    '|',
                    array_map(function ($variation) {
                        return "sku={$variation['sku']},maten={$variation['maten']}";
                    }, $variations)
                );
                $configurable['configurable_variation_labels'] = 'maten=Lengte';

                $products[] = $configurable;
            } else {
                // @todo implement at least simple products
                // @todo expand error handling
                throw new \Exception('Not implemented yet');
            }
        }

        $csvFilePath = $this->generateCsv($products);

        $this->importCsv($csvFilePath);
    }

    public function generateCsv($products): string
    {
        // Find all unique headers across all products
        $allHeaders = [];
        foreach ($products as $row) {
            foreach (array_keys($row) as $key) {
                $allHeaders[$key] = true;
            }
        }
        $headers = array_keys($allHeaders);

        $csvRows[] = $headers;
        foreach ($products as $row) {
            $fullRow = [];
            foreach ($headers as $header) {
                $fullRow[] = array_key_exists($header, $row) ? $row[$header] : '';
            }
            $csvRows[] = $fullRow;
        }

        $csvFilePath = BP . '/var/importexport/products_import.csv';
        $fp = fopen($csvFilePath, 'w');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return $csvFilePath;
    }

    public function importCsv($csvFilePath): void
    {
        $sourceAdapter = Adapter::findAdapterFor(
            $csvFilePath,
            $this->filesystem->getDirectoryWrite(DirectoryList::ROOT),
            ','
        );

        // Use Magento's import model with CSV data
        $this->importModel->setData([
            'entity' => ProductAttributeInterface::ENTITY_TYPE_CODE,
            'behavior' => Import::BEHAVIOR_APPEND,
            'csv_data' => file_get_contents($csvFilePath),
            'validation_strategy' => 'validation-stop-on-errors',
            'allowed_error_count' => 0,
        ]);

        $this->importModel->validateSource($sourceAdapter);

        $errorAggregator = $this->importModel->getErrorAggregator();
        $errorAggregator->initValidationStrategy(
            $this->importModel->getData(Import::FIELD_NAME_VALIDATION_STRATEGY),
            $this->importModel->getData(Import::FIELD_NAME_ALLOWED_ERROR_COUNT)
        );

        $this->importModel->importSource();
    }

    public function mapConfigurableProduct($productNode): array
    {
        return [
            'sku' => (string) $productNode->ProductNumber,
            'name' => (string) $productNode->Description,
            'product_type' => 'configurable',
            'attribute_set_code' => 'Armbanden', // @todo dynamic
            'price' => '',
            'revenue_group' => 'Omzet Zilver',
            'url_key' => strtolower((string) $productNode->ProductNumber), // @todo based on product name
            'product_websites' => 'base',
            'maten' => '',
            // @todo add attributes
        ];
    }

    public function mapProductVariation($variationNode): array
    {
        return [
            'sku' => (string) $variationNode->ProductId,
            'name' => (string) $variationNode->Size,
            'product_type' => 'simple',
            'attribute_set_code' => 'Armbanden', // @todo dynamic
            'price' => (string) $variationNode->SalesPriceInc,
            'revenue_group' => 'Omzet Zilver', // @todo based on product type
            'product_websites' => 'base',
            'maten' => (string) $variationNode->Size,
            // @todo add attributes
        ];
    }
}
