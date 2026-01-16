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
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Registry;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use ReachDigital\Vendit\Model\RowModifier\MultiSelectAttributeOptionCreatorFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class ImportProductsXml extends ImportProfile
{
    private array $attributeTypeCache = [];
    private ?array $categoryGuidMap = null;
    private array $copiedImageFiles = [];
    private array $productsToDelete = [];

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
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Registry $registry,
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

    public function run()
    {
        $result = parent::run();

        // Clean up source images only after successful import
        if ($result === \Magento\Framework\Console\Cli::RETURN_SUCCESS && !$this->getErrors()) {
            $this->cleanupSourceImages();

            // Delete products marked as IsDeleted=True after successful import
            $this->deleteProducts();
        }

        return $result;
    }

    public function getItems(): array
    {
        // Load all products from single XML file
        $items = $this->loadProducts();

        // Copy images to pub/media/import directory before processing
        $this->copyImagesToImportDirectory();

        // Get configured size and color attributes
        $sizeAttribute = $this->config->getSizeAttribute();
        if (empty($sizeAttribute)) {
            throw new \Exception('Size attribute not configured in Vendit configuration');
        }

        $colorAttribute = $this->config->getColorAttribute();
        if (empty($colorAttribute)) {
            throw new \Exception('Color attribute not configured in Vendit configuration');
        }

        $skuResolver = function ($item) {
            // Check if this is a configurable parent marker
            if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                return $item['ProductNumber'] ?? null;
            }

            // For child products, use ProductId as SKU (unique per variation)
            if (isset($item['ProductVariations']['ProductVariation']['ProductId'])) {
                return (string) $item['ProductVariations']['ProductVariation']['ProductId'];
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
            'categories' => function ($item) {
                $categoryMap = $this->getCategoryGuidMap();

                // Extract Groups from the product
                if (!isset($item['Groups']['ProductGroup'])) {
                    return null;
                }

                $groups = $item['Groups']['ProductGroup'];

                // Handle single group (not an array)
                if (!is_array($groups) || isset($groups['Default'])) {
                    $groups = [$groups];
                }

                $categoryNames = [];
                foreach ($groups as $group) {
                    $guid = is_array($group) ? $group[0] ?? null : $group;
                    if ($guid && isset($categoryMap[$guid])) {
                        $categoryNames[] = $categoryMap[$guid];
                    }
                }

                return !empty($categoryNames) ? implode(',', $categoryNames) : null;
            },
            'product_websites' => 'base',
            'name' => function ($item) {
                $name = isset($item['Description']) && !is_array($item['Description'])
                    ? (string) $item['Description']
                    : '';

                // For child products of configurables, append SKU to make name unique
                if (isset($item['_configurable_parent_sku']) && !empty($name)) {
                    $sku = $item['ProductVariations']['ProductVariation']['ProductId'] ?? '';
                    if (!empty($sku)) {
                        $name .= ' - ' . $sku;
                    }
                }

                return $name;
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
            'tax_class_id' => $this->config->getTaxClassId(),
            'visibility' => function ($item) {
                // Configurable parents should be visible
                if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                    $visible = isset($item['Visible']) ? (string) $item['Visible'] : 'False';
                    return $visible === 'True'
                        ? (string) __('Catalog, search')
                        : (string) __('Not visible individually');
                }
                // Simple products under configurables should not be individually visible
                if (isset($item['_configurable_parent_sku'])) {
                    return (string) __('Not visible individually');
                }
                // Standalone simple products
                $visible = isset($item['Visible']) ? (string) $item['Visible'] : 'False';
                return $visible === 'True' ? (string) __('Catalog, search') : (string) __('Not visible individually');
            },
            'status' => function ($item) {
                // Products with IsDeleted=True are filtered out before import, so all products here should be enabled
                // Status is controlled by the Visible field instead
                $visible = isset($item['Visible']) ? (string) $item['Visible'] : 'True';
                return $visible === 'True' ? '1' : '0';
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
            'base_image' => function ($item) {
                return $this->getImageFromVariation($item, 0);
            },
            'small_image' => function ($item) {
                return $this->getImageFromVariation($item, 0);
            },
            'thumbnail_image' => function ($item) {
                return $this->getImageFromVariation($item, 0);
            },
            'additional_images' => function ($item) {
                return $this->getAdditionalImagesFromVariation($item);
            },
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
            if (!$size || is_array($size)) {
                return null;
            }
            // TODO: Normalize case to avoid duplicate option issues - verify this is sufficient for production data
            return $this->normalizeAttributeValue(trim($size));
        };

        // Add color attribute mapping (from Vendit Color field)
        $mapping[$colorAttribute] = function ($item) {
            // Skip for configurable parents
            if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                return null;
            }
            $color = $item['ProductVariations']['ProductVariation']['Color'] ?? null;
            if (!$color || is_array($color)) {
                return null;
            }
            // TODO: Normalize case to avoid duplicate option issues - verify this is sufficient for production data
            return $this->normalizeAttributeValue(trim($color));
        };

        // Add barcode attribute mapping (from Vendit Barcodes/Barcode field)
        $barcodeAttribute = $this->config->getBarcodeAttribute();
        if ($barcodeAttribute) {
            $mapping[$barcodeAttribute] = function ($item) {
                // Skip for configurable parents
                if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
                    return null;
                }

                // Get barcode from ProductVariation
                $barcodes = $item['ProductVariations']['ProductVariation']['Barcodes']['Barcode'] ?? null;

                if (empty($barcodes)) {
                    return null;
                }

                // If it's a single barcode (string), return it
                if (is_string($barcodes)) {
                    return trim($barcodes);
                }

                // If it's an array of barcodes, return the first one
                if (is_array($barcodes)) {
                    $firstBarcode = reset($barcodes);
                    return is_string($firstBarcode) ? trim($firstBarcode) : null;
                }

                return null;
            };
        }

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
        $selectAttributes = [$sizeAttribute, $colorAttribute];
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
        $this->buildConfigurableVariations($items, $sizeAttribute, $colorAttribute);

        // Save processed items for debugging (includes configurable_variations)
        $this->saveProcessedItems($items);

        // Remove internal fields that shouldn't be passed to Magento importer
        foreach ($items as &$item) {
            unset($item['_configurable_parent_sku']);
            unset($item['_is_configurable_parent']);
            unset($item['_product_variants']);
        }

        return $items;
    }

    public function buildConfigurableVariations(array &$items, string $sizeAttribute, string $colorAttribute): void
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

                // Add the color attribute if it exists
                // Use the same value that was set in the child product (already normalized)
                if (!empty($child[$colorAttribute])) {
                    $variationParts[] = $colorAttribute . '=' . trim(mb_strtolower($child[$colorAttribute]));
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

        // Read file content
        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read products XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn($e) => trim($e->message), $errors);
            throw new \Exception('Failed to parse products XML file: ' . implode(', ', $errorMessages));
        }

        $items = [];
        foreach ($xml->Products->Product as $productNode) {
            $productArray = json_decode(json_encode($productNode), true);

            // Determine if product has single or multiple variants
            $hasMultipleVariants = isset($productArray['ProductVariations']['ProductVariation'][0]);
            $hasOneVariant =
                isset($productArray['ProductVariations']['ProductVariation']['ProductId']) && !$hasMultipleVariants;

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

            // Check if product is marked as deleted
            $isDeleted = isset($productArray['IsDeleted']) ? (string) $productArray['IsDeleted'] : 'False';
            if ($isDeleted === 'True') {
                // Track this product for deletion
                $productNumber = $productArray['ProductNumber'] ?? null;

                if ($hasOneVariant) {
                    // Simple product: track the variant SKU
                    $sku = (string) ($productArray['ProductVariations']['ProductVariation']['ProductId'] ?? '');
                    if (!empty($sku)) {
                        $this->productsToDelete[] = $sku;
                        $this->log->info("Product {$sku} marked for deletion (IsDeleted=True)");
                    }
                } else {
                    // Configurable product: track parent and all children SKUs
                    if (!empty($productNumber)) {
                        $this->productsToDelete[] = $productNumber;
                        $this->log->info("Configurable product {$productNumber} marked for deletion (IsDeleted=True)");
                    }
                    // Also track all child variants
                    foreach ($productArray['ProductVariations']['ProductVariation'] as $variant) {
                        $childSku = (string) ($variant['ProductId'] ?? '');
                        if (!empty($childSku)) {
                            $this->productsToDelete[] = $childSku;
                        }
                    }
                }

                // Skip this product from the import
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
        // Use ProductId as SKU (unique per variation)
        $sku = (string) ($productArray['ProductVariations']['ProductVariation']['ProductId'] ?? '');
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
        // Store the original variants array for image processing
        $configurableParent['_product_variants'] = $productArray['ProductVariations']['ProductVariation'];
        unset($configurableParent['ProductVariations']);
        $items[$configurableSku] = $configurableParent;

        // Create a simple product for each variant
        foreach ($productArray['ProductVariations']['ProductVariation'] as $variant) {
            // Use ProductId as SKU (unique per variation)
            $sku = (string) ($variant['ProductId'] ?? '');
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

    /**
     * Get image from product variation by index
     */
    private function getImageFromVariation(array $item, int $index): ?string
    {
        // For configurable parents, use the first variant's first image
        if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
            $variants = $item['_product_variants'] ?? [];
            if (empty($variants)) {
                return null;
            }

            // Get the first variant
            $firstVariant = reset($variants);
            $images = $firstVariant['Images']['Image'] ?? null;

            if (empty($images)) {
                return null;
            }

            // Return the first image
            if (is_string($images)) {
                return $this->buildImagePath($images);
            }

            if (is_array($images) && isset($images[0])) {
                return $this->buildImagePath($images[0]);
            }

            return null;
        }

        $images = $item['ProductVariations']['ProductVariation']['Images']['Image'] ?? null;

        if (empty($images)) {
            return null;
        }

        // If it's a single image (string), return it if index is 0
        if (is_string($images)) {
            return $index === 0 ? $this->buildImagePath($images) : null;
        }

        // If it's an array of images, return the one at the specified index
        if (is_array($images) && isset($images[$index])) {
            return $this->buildImagePath($images[$index]);
        }

        return null;
    }

    /**
     * Get additional images from product variation (all images except the first one)
     */
    private function getAdditionalImagesFromVariation(array $item): ?string
    {
        // For configurable parents, collect all unique images from all variants (except the first image)
        if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
            $variants = $item['_product_variants'] ?? [];
            if (empty($variants)) {
                return null;
            }

            $allImages = [];
            $firstImage = null;

            // Collect all images from all variants
            foreach ($variants as $variant) {
                $images = $variant['Images']['Image'] ?? null;

                if (empty($images)) {
                    continue;
                }

                // Normalize to array
                $imageArray = is_string($images) ? [$images] : $images;

                foreach ($imageArray as $image) {
                    $imagePath = $this->buildImagePath($image);

                    // Track the first image (already used as base_image)
                    if ($firstImage === null) {
                        $firstImage = $imagePath;
                        continue;
                    }

                    // Add unique images only
                    if (!in_array($imagePath, $allImages)) {
                        $allImages[] = $imagePath;
                    }
                }
            }

            return !empty($allImages) ? implode(',', $allImages) : null;
        }

        $images = $item['ProductVariations']['ProductVariation']['Images']['Image'] ?? null;

        if (empty($images)) {
            return null;
        }

        $additionalImages = [];

        // If it's a single image, there are no additional images
        if (is_string($images)) {
            return null;
        }

        // If it's an array of images, get all images starting from index 1
        if (is_array($images)) {
            for ($i = 1; $i < count($images); $i++) {
                if (isset($images[$i])) {
                    $additionalImages[] = $this->buildImagePath($images[$i]);
                }
            }
        }

        return !empty($additionalImages) ? implode(',', $additionalImages) : null;
    }

    /**
     * Build full image path using configured image directory
     */
    private function buildImagePath(string $filename): string
    {
        $imagePath = $this->config->getImagePath();

        if (empty($imagePath)) {
            return $filename;
        }

        return rtrim($imagePath, '/') . '/' . ltrim($filename, '/');
    }

    /**
     * Copy images from source directory to pub/media/import directory
     * This ensures images are available for Magento's import process
     */
    private function copyImagesToImportDirectory(): void
    {
        $imagePath = $this->config->getImagePath();
        if (empty($imagePath)) {
            return;
        }

        // Source: var/vendit/import/images/
        $varDirectory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $sourceDir = $varDirectory->getAbsolutePath(trim($imagePath, '/'));

        // Destination: pub/media/import/vendit/import/images/
        $mediaDirectory = $this->filesystem->getDirectoryWrite(AppDirectoryList::MEDIA);
        $destDir = $mediaDirectory->getAbsolutePath('import/' . trim($imagePath, '/'));

        // Create destination directory if it doesn't exist
        if (!$mediaDirectory->isDirectory('import/' . trim($imagePath, '/'))) {
            $mediaDirectory->create('import/' . trim($imagePath, '/'));
        }

        // Check if source directory exists
        if (!$varDirectory->isDirectory(trim($imagePath, '/'))) {
            $this->log->warning("Source image directory does not exist: {$sourceDir}");
            return;
        }

        // Copy all image files
        $imageFiles = glob($sourceDir . '/*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF}', GLOB_BRACE);
        $copiedCount = 0;

        foreach ($imageFiles as $sourceFile) {
            $filename = basename($sourceFile);
            $sourcePath = trim($imagePath, '/') . '/' . $filename;
            $destPath = 'import/' . trim($imagePath, '/') . '/' . $filename;

            // Copy file
            try {
                $content = $varDirectory->readFile($sourcePath);
                $mediaDirectory->writeFile($destPath, $content);
                $this->copiedImageFiles[] = $sourcePath;
                $copiedCount++;
            } catch (\Exception $e) {
                $this->log->error("Failed to copy image {$filename}: " . $e->getMessage());
            }
        }

        if ($copiedCount > 0) {
            $this->log->info("Copied {$copiedCount} image file(s) to import directory");
        }
    }

    /**
     * Clean up source images after successful import
     * This removes the image files from var/vendit/import/images/ to save disk space
     */
    private function cleanupSourceImages(): void
    {
        if (empty($this->copiedImageFiles)) {
            return;
        }

        $varDirectory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $deletedCount = 0;

        foreach ($this->copiedImageFiles as $sourcePath) {
            try {
                if ($varDirectory->isFile($sourcePath)) {
                    $varDirectory->delete($sourcePath);
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                $this->log->error("Failed to delete source image {$sourcePath}: " . $e->getMessage());
            }
        }

        if ($deletedCount > 0) {
            $this->log->info("Cleaned up {$deletedCount} source image file(s) after successful import");
        }

        // Clear the tracking array
        $this->copiedImageFiles = [];
    }

    /**
     * Delete products marked as IsDeleted=True from Magento
     */
    private function deleteProducts(): void
    {
        if (empty($this->productsToDelete)) {
            $this->log->info('No products to delete');
            return;
        }

        $deletedCount = 0;
        $failedCount = 0;

        // Set registry flag to allow product deletion
        $this->registry->register('isSecureArea', true);

        foreach ($this->productsToDelete as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $this->productRepository->delete($product);
                $deletedCount++;
                $this->log->info("Successfully deleted product: {$sku}");
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // Product doesn't exist, that's fine
                $this->log->info("Product {$sku} not found in Magento (already deleted or never imported)");
            } catch (\Exception $e) {
                $failedCount++;
                $this->log->error("Failed to delete product {$sku}: " . $e->getMessage());
            }
        }

        // Unregister secure area flag
        $this->registry->unregister('isSecureArea');

        $this->log->info("Deletion complete: {$deletedCount} products deleted, {$failedCount} failed");
    }

    /**
     * Normalize attribute option values to avoid case-sensitivity duplicates
     * Converts to lowercase, then capitalizes first letter of each word
     *
     * @param string $value
     * @return string
     */
    private function normalizeAttributeValue(string $value): string
    {
        return ucwords(strtolower($value));
    }

    /**
     * Get SKU from product item for logging purposes
     */
    private function getProductSku(array $item): string
    {
        // Check if this is a configurable parent marker
        if (isset($item['_is_configurable_parent']) && $item['_is_configurable_parent']) {
            return $item['ProductNumber'] ?? 'unknown';
        }

        // For child products, use ProductId as SKU
        if (isset($item['ProductVariations']['ProductVariation']['ProductId'])) {
            return (string) $item['ProductVariations']['ProductVariation']['ProductId'];
        }

        // Fallback to ProductNumber
        return $item['ProductNumber'] ?? 'unknown';
    }

    /**
     * Load category GUID to path mapping from database
     * Uses the category_code attribute which stores the GroupGuid
     */
    private function getCategoryGuidMap(): ?array
    {
        if (!is_null($this->categoryGuidMap)) {
            return $this->categoryGuidMap;
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'category_code']);
        $collection->addAttributeToFilter('category_code', ['notnull' => true]);

        $guidMap = [];
        foreach ($collection as $category) {
            $guid = $category->getData('category_code');
            if ($guid && $guid !== 'root_catalog_default_category') {
                // Get full category path (e.g., "Default Category/Electro/Bruingoed")
                $pathIds = explode('/', $category->getPath());
                $pathNames = [];

                foreach ($pathIds as $pathId) {
                    // Skip root catalog (ID 1)
                    if ($pathId == 1) {
                        continue;
                    }
                    $pathCategory = $collection->getItemById($pathId);
                    if (!$pathCategory) {
                        // Load category if not in collection
                        $pathCategory = $this->categoryCollectionFactory
                            ->create()
                            ->addAttributeToSelect('name')
                            ->addFieldToFilter('entity_id', $pathId)
                            ->getFirstItem();
                    }
                    if ($pathCategory && $pathCategory->getName()) {
                        // Escape forward slashes in category names to prevent them from being treated as path separators
                        $categoryName = str_replace('/', '\/', $pathCategory->getName());
                        $pathNames[] = $categoryName;
                    }
                }

                // Store the full category path
                $guidMap[$guid] = implode('/', $pathNames);
            }
        }

        $this->categoryGuidMap = $guidMap;

        return $this->categoryGuidMap;
    }
}
