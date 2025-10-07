<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Ho\Import\Logger\Log;
use Ho\Import\Model\ImportProfile;
use Ho\Import\RowModifier\AttributeOptionCreatorFactory;
use Ho\Import\RowModifier\ItemMapperFactory;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use ReachDigital\Vendit\Model\RowModifier\MultiSelectAttributeOptionCreatorFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class ImportProductsXml extends ImportProfile
{
    private array $attributeTypeCache = [];

    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        Stopwatch $stopwatch,
        ConsoleOutput $consoleOutput,
        Log $log,
        private readonly ItemMapperFactory $itemMapperFactory,
        private readonly MultiSelectAttributeOptionCreatorFactory $multiSelectAttributeOptionCreatorFactory,
        private readonly AttributeOptionCreatorFactory $attributeOptionCreatorFactory,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly AttributeRepositoryInterface $attributeRepository,
    ) {
        parent::__construct($objectManagerFactory, $stopwatch, $consoleOutput, $log);
    }

    public function getConfig(): array
    {
        return [
            'behavior' => Import::BEHAVIOR_APPEND,
            'entity' => Product::ENTITY,
            'validation_strategy' => ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_SKIP_ERRORS,
            'allowed_error_count' => 100,
        ];
    }

    public function getItems(): array
    {
        // Load all products from single XML file
        $items = $this->loadProducts();

        // Get configured size attribute
        $sizeAttribute = $this->config->getSizeAttribute();
        if (empty($sizeAttribute)) {
            throw new \Exception('Size attribute not configured in Vendit configuration');
        }

        $skuResolver = function ($item) {
            // Check if this is a configurable parent marker
            if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                return $item['ProductNumber'] ?? null;
            }

            // Otherwise it's a simple product
            if (isset($item['ProductVariations']['ProductVariation']['ProductNumber'])) {
                return (string) $item['ProductVariations']['ProductVariation']['ProductNumber'];
            }
            return null;
        };

        // Build base mapping
        $mapping = [
            'sku' => $skuResolver,
            'store_view_code' => null,
            'attribute_set_code' => 'Default',
            'product_type' => function ($item) {
                return isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']
                    ? 'configurable'
                    : 'simple';
            },
            'categories' => null,
            'product_websites' => 'base',
            'name' => function ($item) {
                return isset($item['Description']) && !is_array($item['Description'])
                    ? (string) $item['Description']
                    : '';
            },
            'description' => function ($item) {
                if (isset($item['BigInfo']) && !is_array($item['BigInfo'])) {
                    $bigInfo = (string) $item['BigInfo'];
                    return !empty($bigInfo) ? $bigInfo : null;
                }
                return null;
            },
            'short_description' => function ($item) {
                if (isset($item['SmallInfo']) && !is_array($item['SmallInfo'])) {
                    $smallInfo = (string) $item['SmallInfo'];
                    return !empty($smallInfo) ? html_entity_decode(strip_tags($smallInfo)) : null;
                }
                return null;
            },
            'price' => function ($item) {
                // Skip price for configurable parents - will be derived from children
                if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                    return null;
                }
                $price = $item['ProductVariations']['ProductVariation']['SalesPriceEx'] ?? 0;
                return is_numeric($price) ? (float) $price : 0;
            },
            // @todo configurable tax class id
            'tax_class_id' => 2,
            'visibility' => function ($item) {
                // Configurable parents should be visible
                if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                    $visible = isset($item['Visible']) ? (string) $item['Visible'] : 'False';
                    return $visible === 'True' ? 'Catalogus, zoeken' : 'Niet individueel zichtbaar';
                }
                // Simple products under configurables should not be individually visible
                if (isset($item['_configurable_parent_sku'])) {
                    return 'Niet individueel zichtbaar';
                }
                // Standalone simple products
                $visible = isset($item['Visible']) ? (string) $item['Visible'] : 'False';
                return $visible === 'True' ? 'Catalogus, zoeken' : 'Niet individueel zichtbaar';
            },
            'status' => function ($item) {
                $deleted = isset($item['IsDeleted']) ? (string) $item['IsDeleted'] : 'True';
                return $deleted === 'False' ? '1' : '0';
            },
            'qty' => 0,
            'is_in_stock' => function ($item) {
                // Skip stock for configurable parents
                if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                    return null;
                }
                $status = $item['ProductVariations']['ProductVariation']['AvailabilityStatus'] ?? '';
                return (string) $status === 'Leverbaar' ? '1' : '0';
            },
            'manage_stock' => 1,
            'use_config_min_qty' => '1',
            'use_config_backorders' => '1',
            'use_config_min_sale_qty' => '1',
            'use_config_max_sale_qty' => '1',
            'use_config_notify_stock_qty' => '1',
            'use_config_manage_stock' => '1',
            'use_config_qty_increments' => '1',
            'use_config_enable_qty_inc' => '1',
            'meta_title' => function ($item) {
                if (isset($item['PageTitle']) && !is_array($item['PageTitle'])) {
                    $title = (string) $item['PageTitle'];
                    return !empty($title) ? $title : null;
                }
                return null;
            },
            'meta_keywords' => function ($item) {
                if (isset($item['MetaKeywords']) && !is_array($item['MetaKeywords'])) {
                    $keywords = (string) $item['MetaKeywords'];
                    return !empty($keywords) ? $keywords : null;
                }
                return null;
            },
            'meta_description' => function ($item) {
                if (isset($item['MetaDescription']) && !is_array($item['MetaDescription'])) {
                    $description = (string) $item['MetaDescription'];
                    return !empty($description) ? $description : null;
                }
                return null;
            },
            'news_from_date' => function ($item) {
                if (isset($item['AvailableFrom']) && !is_array($item['AvailableFrom'])) {
                    $value = (string) $item['AvailableFrom'];
                    return !empty($value) ? $value : null;
                }
                return null;
            },
            'news_to_date' => function ($item) {
                if (isset($item['AvailableUntil']) && !is_array($item['AvailableUntil'])) {
                    $value = (string) $item['AvailableUntil'];
                    return !empty($value) ? $value : null;
                }
                return null;
            },
            // Preserve internal fields for configurable building
            '_configurable_parent_sku' => function ($item) {
                return $item['_configurable_parent_sku'] ?? null;
            },
            '_is_configurable_parent' => function ($item) {
                return $item['_is_configurable_parent'] ?? null;
            },
        ];

        // Add size attribute mapping (from Vendit Size field)
        $mapping[$sizeAttribute] = function ($item) {
            // Skip for configurable parents
            if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                return null;
            }
            $size = $item['ProductVariations']['ProductVariation']['Size'] ?? null;
            return $size && !is_array($size) ? trim($size) : null;
        };

        // Add dynamically mapped attributes from config
        $attributeMapping = $this->config->getAttributeMapping();
        foreach ($attributeMapping as $specName => $attributeCode) {
            // Skip if already mapped
            if (isset($mapping[$attributeCode])) {
                continue;
            }

            $mapping[$attributeCode] = fn($item) => $this->getSpecValue($item, $specName);
        }

        // Map XML data to Magento product import format
        $itemMapper = $this->itemMapperFactory->create([
            'mapping' => $mapping,
        ]);

        $itemMapper->setItems($items);
        $itemMapper->process();

        // Collect all select/multiselect attributes that need option creation
        $selectAttributes = [$sizeAttribute];
        $multiselectAttributes = [];
        foreach ($attributeMapping as $attributeCode) {
            switch ($this->getAttributeFrontendInput($attributeCode)) {
                case 'select':
                    $selectAttributes[] = $attributeCode;
                    break;
                case 'multiselect':
                    $multiselectAttributes[] = $attributeCode;
                    break;
            }
        }
        $selectAttributes = array_unique($selectAttributes);

        // Create non-existing values for select attributes
        $attributeOptionCreator = $this->attributeOptionCreatorFactory->create([
            'attributes' => $selectAttributes,
        ]);
        $attributeOptionCreator->setItems($items);
        $attributeOptionCreator->process();

        // Create non-existing values for multiselect attributes
        $multiselectAttributeOptionCreator = $this->multiSelectAttributeOptionCreatorFactory->create([
            'attributes' => $multiselectAttributes,
        ]);
        $multiselectAttributeOptionCreator->setItems($items);
        $multiselectAttributeOptionCreator->process();

        // Build configurable_variations field for configurable parents
        $this->buildConfigurableVariations($items, $sizeAttribute);

        // Save processed items for debugging (includes configurable_variations)
        $this->saveProcessedItems($items);

        // Remove internal fields that shouldn't be passed to Magento importer
        foreach ($items as &$item) {
            unset($item['_configurable_parent_sku']);
            unset($item['_is_configurable_parent']);
        }

        return $items;
    }

    public function buildConfigurableVariations(array &$items, string $sizeAttribute): void
    {
        $configurableGroups = [];
        foreach ($items as $sku => $item) {
            // Skip configurable parents
            if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                continue;
            }

            // Find simple products that belong to a configurable
            if (isset($item['_configurable_parent_sku']) && !empty($item['_configurable_parent_sku'])) {
                $parentSku = $item['_configurable_parent_sku'];
                if (!isset($configurableGroups[$parentSku])) {
                    $configurableGroups[$parentSku] = [];
                }
                $configurableGroups[$parentSku][] = $sku;
            }
        }

        foreach ($configurableGroups as $parentSku => $childSkus) {
            if (!isset($items[$parentSku])) {
                $this->log->warning("Parent SKU $parentSku not found in items");
                continue;
            }

            // Build the configurable_variations value
            // Format: sku=child1,attribute1=value1,attribute2=value2|sku=child2,attribute1=value3,attribute2=value4
            $variations = [];
            foreach ($childSkus as $childSku) {
                if (!isset($items[$childSku])) {
                    continue;
                }

                $child = $items[$childSku];
                $variationParts = ['sku=' . $childSku];

                // Add the size attribute if it exists
                // Use the same value that was set in the child product (already normalized)
                if (!empty($child[$sizeAttribute])) {
                    $variationParts[] = $sizeAttribute . '=' . trim(mb_strtolower($child[$sizeAttribute]));
                }

                $variations[] = implode(',', $variationParts);
            }

            if (!empty($variations)) {
                $variationsString = implode('|', $variations);
                $items[$parentSku]['configurable_variations'] = $variationsString;
                $this->log->info("Built configurable product $parentSku with " . count($childSkus) . ' variations');
            }
        }
    }

    public function loadProducts(): array
    {
        $xmlFilePath = $this->config->getProductImportFilePath();
        if (!file_exists($xmlFilePath)) {
            $this->log->error("Products XML file not found: {$xmlFilePath}");
            throw new \Exception("Products XML file not found: {$xmlFilePath}");
        }

        $xml = simplexml_load_file($xmlFilePath);
        if ($xml === false) {
            throw new \Exception('Failed to parse products XML file');
        }

        $items = [];
        foreach ($xml->Products->Product as $productNode) {
            $productArray = json_decode(json_encode($productNode), true);

            // Determine if product has single or multiple variants
            $hasMultipleVariants = isset($productArray['ProductVariations']['ProductVariation'][0]);
            $hasOneVariant =
                isset($productArray['ProductVariations']['ProductVariation']['ProductNumber']) && !$hasMultipleVariants;

            // Skip products with no variations
            if (!$hasOneVariant && !$hasMultipleVariants) {
                $this->log->warning('Product skipped: no valid ProductVariation found');
                continue;
            }

            // Check if all required attributes (Magento attribute codes) exist in Specs
            $requiredAttributeCodes = $this->config->getRequiredAttributes();
            $attributeMapping = $this->config->getAttributeMapping();
            $missingRequired = false;

            foreach ($requiredAttributeCodes as $requiredAttributeCode) {
                // Find the spec name for this attribute code
                $specName = array_search($requiredAttributeCode, $attributeMapping, true);

                if ($specName === false) {
                    // Attribute not in mapping, skip check
                    continue;
                }

                // Check if spec value exists and is not empty
                $specValue = $this->getSpecValue($productArray, $specName);
                if (empty($specValue)) {
                    $productNumber = $productArray['ProductNumber'] ?? 'unknown';
                    $this->log->error(
                        "Product {$productNumber} skipped: missing required attribute '{$requiredAttributeCode}' (spec: '{$specName}') in Specs",
                    );
                    $missingRequired = true;
                    break;
                }
            }

            if ($missingRequired) {
                continue;
            }

            if ($hasOneVariant) {
                // Simple product: single variation
                foreach ($this->createSimpleProduct($productArray) as $sku => $product) {
                    $items[$sku] = $product;
                }
            } else {
                // Configurable product: multiple variations
                foreach ($this->createConfigurableProduct($productArray) as $sku => $product) {
                    $items[$sku] = $product;
                }
            }
        }

        return $items;
    }

    public function createSimpleProduct(array $productArray): array
    {
        $sku = (string) $productArray['ProductVariations']['ProductVariation']['ProductNumber'];
        if (empty($sku)) {
            return [];
        }

        return [$sku => $productArray];
    }

    public function createConfigurableProduct(array $productArray): array
    {
        $configurableSku = (string) $productArray['ProductNumber'];
        if (empty($configurableSku)) {
            return [];
        }

        $items = [];

        // Create the configurable parent product
        $configurableParent = $productArray;
        $configurableParent['_is_configurable_parent'] = true;
        unset($configurableParent['ProductVariations']);
        $items[$configurableSku] = $configurableParent;

        // Create a simple product for each variant
        foreach ($productArray['ProductVariations']['ProductVariation'] as $variant) {
            $sku = (string) $variant['ProductNumber'];
            if (empty($sku)) {
                continue;
            }

            $simpleProduct = $productArray;
            $simpleProduct['ProductVariations']['ProductVariation'] = $variant;
            $simpleProduct['_configurable_parent_sku'] = $configurableSku;

            $items[$sku] = $simpleProduct;
        }

        return $items;
    }

    /**
     * Save mapped items as json.
     * Every item is on a single line, with a line number, so we can easily debug import errors,
     * which mention row numbers.
     *
     * @throws FileSystemException
     */
    public function saveProcessedItems(array $items): void
    {
        $directory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $stream = $directory->openFile('importexport/products_import.json', 'w+');
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

    private function getSpecValue(array $item, string $specName): ?string
    {
        if (!isset($item['Specs']['Spec'])) {
            return null;
        }

        $specs = $item['Specs']['Spec'];

        foreach ($specs as $spec) {
            if (isset($spec['Name']) && $spec['Name'] === $specName) {
                $value = (string) $spec['Value'];

                // Get the Magento attribute code for this spec
                $attributeCode = $this->getAttributeCodeForSpec($specName);

                if ($attributeCode && $this->getAttributeFrontendInput($attributeCode) === 'boolean') {
                    // Translate boolean values from English to Dutch
                    return $value === 'Yes' ? 'Ja' : 'Nee';
                }

                if ($attributeCode && $this->getAttributeFrontendInput($attributeCode) === 'multiselect') {
                    // For multiselect attributes, make sure there is no whitespace around comma's
                    return implode(',', array_map('trim', explode(',', $value)));
                }

                return $value;
            }
        }

        return null;
    }

    private function getAttributeCodeForSpec(string $specName): ?string
    {
        $attributeMapping = $this->config->getAttributeMapping();
        return $attributeMapping[$specName] ?? null;
    }

    private function getAttributeFrontendInput(string $attributeCode): ?string
    {
        // Check cache first
        if (isset($this->attributeTypeCache[$attributeCode])) {
            return $this->attributeTypeCache[$attributeCode];
        }

        try {
            $attribute = $this->attributeRepository->get(Product::ENTITY, $attributeCode);
            $frontendInput = $attribute->getFrontendInput();
            $this->attributeTypeCache[$attributeCode] = $frontendInput;
            return $frontendInput;
        } catch (\Exception $e) {
            // Attribute doesn't exist or error occurred
            $this->attributeTypeCache[$attributeCode] = null;
            return null;
        }
    }
}
