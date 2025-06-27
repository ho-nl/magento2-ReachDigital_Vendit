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
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ExportProductsXml
{
    const FILENAME = 'products.xml';

    // @todo configurable? from default country/tax rule?
    const TAX_PERCENTAGE = 21;

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
        public Config $venditConfig
    ) {
    }

    public function execute(bool $debug = false): bool|int
    {
        $doc = new DOMDocument('1.0', 'utf-16');
        $doc->formatOutput = true;

        $root = $doc->createElement('ProductExport');
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            'http://www.w3.org/2001/XMLSchema-instance'
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
        // @todo Grouped and bundles not supported initially
        $collection->addAttributeToFilter('type_id', [
            'in' => [Type::TYPE_SIMPLE, Configurable::TYPE_CODE],
        ]);

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

                $guid = $this->getGuid((string) $product->getId());
                $elements = [
                    'EcommerceProductGuid' => $guid,
                    'ProductNumber' => $product->getSku(),
                    'Description' => $product->getName(),
                    'Type' => $product->getTypeId(),
                    'Brand' => $product->getAttributeText('brand2'),
                    'Occasion' => 'False', // All products are new
                    'CompositeProduct' => 'False', // Bundle/grouped products are not supported yet
                    'FrontPage' => 'False',
                    'PageTitle' => $product->getName(),
                    'MetaKeywords' => $product->getData('meta_keyword') ?? '',
                    'MetaDescription' => $product->getData('meta_description') ?? '',
                    'Visible' => $product->getStatus() == Status::STATUS_ENABLED ? 'True' : 'False',
                    'AvailableFrom' => '2000-01-01T00:00:00',
                    'AvailableUntil' => '9999-12-31T23:59:59',
                    'OrderableFrom' => '2000-01-01T00:00:00',
                    'OrderableUntil' => '9999-12-31T23:59:59',
                    'StockProduct' => 'True',
                    'ShippingCost' => '0.0000',
                    'IsDeleted' => 'False',
                    'LastModified' => $this->dateTime->gmtDate('c', $product->getUpdatedAt()),
                ];

                foreach ($elements as $name => $value) {
                    $productNode->appendChild($doc->createElement($name, htmlspecialchars($value)));
                }

                // Add categories as Groups
                $groupsNode = $doc->createElement('Groups');
                $categoryIds = $product->getCategoryIds();
                $isFirst = true;
                foreach ($categoryIds as $categoryId) {
                    $guid = $this->exportCategoriesXml->getGuid((string) $categoryId);
                    $groupNode = $doc->createElement('Group', $guid);
                    $defaultAttr = $doc->createAttribute('Default');
                    $defaultAttr->value = $isFirst ? 'True' : 'False';
                    $groupNode->appendChild($defaultAttr);
                    $groupsNode->appendChild($groupNode);
                    $isFirst = false;
                }
                $productNode->appendChild($groupsNode);

                // Handle elements with HTML with CDATA
                $cdataElements = [
                    'SmallInfo' => $product->getData('short_description') ?? '',
                    'BigInfo' => $product->getData('description') ?? '',
                ];

                foreach ($cdataElements as $name => $value) {
                    $descriptionNode = $doc->createElement($name);
                    $descriptionCdata = $doc->createCDATASection($value);
                    $descriptionNode->appendChild($descriptionCdata);
                    $productNode->appendChild($descriptionNode);
                }

                // @todo different list of attributes per attribute set, needs to be configurable
                // @todo also append $sizeAttributeCode, see <Attributes> element below
                $searchCriteria = $this->searchCriteriaBuilder->addFilter(
                    'attribute_code',
                    ['color', 'populair', 'topmodel', 'brand2', 'lengte_armbanden'],
                    'in'
                );
                $attributes = $this->productAttributeRepository->getList($searchCriteria->create())->getItems();
                $attributeIds = array_map(function (Attribute $attribute) {
                    return $attribute->getId();
                }, $attributes);
                //

                switch ($product->getTypeId()) {
                    case Configurable::TYPE_CODE:
                        /** @var Configurable $productTypeInstance */
                        $productTypeInstance = $product->getTypeInstance();

                        $variations = $productTypeInstance->getUsedProducts($product, $attributeIds);
                        break;
                    case Type::TYPE_SIMPLE:
                        $variations = [$product];
                        break;
                    default:
                        throw new \Exception(
                            sprintf('Export for product type \'%s\' not implemented', $product->getTypeId())
                        );
                }

                $variationsNode = $doc->createElement('ProductVariations');
                foreach ($variations as $childProduct) {
                    $node = $doc->createElement('ProductVariation');

                    $this->append($doc, $node, 'ProductId', $childProduct->getId());
                    // Generate EcommerceProductVariationGuid using local getGuid method
                    $variationGuid = $this->getGuid((string) $childProduct->getId());
                    $this->append($doc, $node, 'EcommerceProductVariationGuid', $variationGuid);
                    $this->append($doc, $node, 'Size', $childProduct->getAttributeText('maten'));
                    $this->append($doc, $node, 'Color', $childProduct->getAttributeText('color'));
                    $this->append($doc, $node, 'TaxPercentage', self::TAX_PERCENTAGE, self::NODE_TYPE_NUMBER);
                    $priceExcludingTax = $childProduct->getFinalPrice() / (1 + self::TAX_PERCENTAGE / 100);
                    $priceIncludingTax = $childProduct->getFinalPrice();
                    $this->append($doc, $node, 'SalesPriceEx', $priceExcludingTax, self::NODE_TYPE_NUMBER);
                    $this->append($doc, $node, 'SalesPriceInc', $priceIncludingTax, self::NODE_TYPE_NUMBER);
                    $this->append($doc, $node, 'ShopSalesPriceEx', $priceExcludingTax, self::NODE_TYPE_NUMBER);
                    $this->append($doc, $node, 'ShopSalesPriceInc', $priceIncludingTax, self::NODE_TYPE_NUMBER);

                    // @todo make size attribute code configurable, based on attribute set
                    $sizeAttributeCode = 'lengte_armbanden';

                    $frontendInputType = $this->productAttributeRepository->get($sizeAttributeCode)->getFrontendInput();
                    $sizeValue = match ($frontendInputType) {
                        'text' => $childProduct->getData($sizeAttributeCode),
                        'select' => $childProduct->getAttributeText($sizeAttributeCode),
                        default => throw new \Exception(
                            sprintf(
                                'Unsupported frontend input type (%s) for size attribute: %s',
                                $frontendInputType,
                                $sizeAttributeCode
                            )
                        )
                    };

                    $attributesNode = $doc->createElement('Attributes');
                    if ($sizeValue) {
                        $attributeNode = $doc->createElement('Attribute');
                        $attributeNode->appendChild($doc->createElement('Name', 'Size'));
                        $attributeNode->appendChild($doc->createElement('SortOrder', '0'));
                        $attributeNode->appendChild($doc->createElement('Substitute'));
                        $attributeNode->appendChild($doc->createElement('Value', $sizeValue));
                        $attributesNode->appendChild($attributeNode);
                    }
                    $node->appendChild($attributesNode);

                    $stockItem = $this->stockRegistry->getStockItem($childProduct->getId());
                    $this->append(
                        $doc,
                        $node,
                        'AvailabilityStatus',
                        $stockItem->getIsInStock() ? 'Leverbaar' : 'Uit assortiment'
                    );

                    $barcodes = $doc->createElement('Barcodes');
                    $barcode = $doc->createElement('Barcode', $childProduct->getData('codeeanbarcode4') ?? '');
                    $domAttribute = $doc->createAttribute('BarcodeOrder');
                    $domAttribute->value = '1';
                    $barcode->appendChild($domAttribute);
                    $barcodes->appendChild($barcode);
                    $this->append($doc, $node, 'Barcodes', $barcodes);

                    // Get media gallery images for the child product
                    $imagesNode = $doc->createElement('Images');
                    $mediaGallery = $childProduct->getMediaGalleryImages();
                    if ($mediaGallery) {
                        $imageOrder = 0;
                        foreach ($mediaGallery as $image) {
                            $imageNode = $doc->createElement('Image', basename($image->getFile()));
                            $imageOrderAttr = $doc->createAttribute('ImageOrder');
                            $imageOrderAttr->value = (string) $imageOrder;
                            $imageNode->appendChild($imageOrderAttr);
                            $imagesNode->appendChild($imageNode);
                            $imageOrder++;
                        }
                    }
                    $node->appendChild($imagesNode);

                    $updatedAt = $this->dateTime->gmtDate('c', $childProduct->getUpdatedAt());
                    $this->append($doc, $node, 'ProductNumber', $childProduct->getSku());
                    $this->append($doc, $node, 'SupplierProductNumber', $childProduct->getSku());
                    $this->append($doc, $node, 'LastModified', $updatedAt);
                    $this->append(
                        $doc,
                        $node,
                        'ProductDescription',
                        htmlspecialchars($childProduct->getShortDescription() ?? '')
                    );
                    $this->append(
                        $doc,
                        $node,
                        'ProductSubDescription',
                        htmlspecialchars($childProduct->getDescription() ?? '')
                    );

                    $variationsNode->appendChild($node);
                }
                $productNode->appendChild($variationsNode);

                // Specs
                $specsNode = $doc->createElement('Specs');
                $i = 0;
                foreach ($product->getCustomAttributes() as $attribute) {
                    if (is_array($attribute->getValue())) {
                        continue;
                    }

                    $spec = $doc->createElement('Spec');
                    $spec->appendChild($doc->createElement('Name', htmlspecialchars($attribute->getAttributeCode())));
                    $spec->appendChild($doc->createElement('Value', htmlspecialchars($attribute->getValue())));
                    $spec->appendChild($doc->createElement('Important', 'False'));
                    $spec->appendChild($doc->createElement('ItemOrder', (string) $i));

                    $specsNode->appendChild($spec);
                    $i++;
                }
                $productNode->appendChild($specsNode);

                $productNode->appendChild($doc->createElement('LastModified', $product->getUpdatedAt()));
                $productsNode->appendChild($productNode);
            }

            $collection->clear(); // Clear memory
            $page++;
            $p++;
        } while ($page <= $collection->getLastPageNumber());

        if ($debug) {
            echo sprintf('Export duration: %ss', number_format(microtime(true) - $s, 4)) . PHP_EOL;
        }

        $root->appendChild($productsNode);

        return $doc->save($this->getFilePath());
    }

    public function getFilename(): string
    {
        return self::FILENAME;
    }

    public function getFilePath(): string
    {
        return $this->venditConfig->getFilePath($this->getFilename());
    }

    public function append(
        DOMDocument $doc,
        DOMElement $node,
        string $name,
        mixed $value,
        $type = self::NODE_TYPE_STRING
    ): void {
        switch ($type) {
            case self::NODE_TYPE_NUMBER:
                $value = number_format($value, 4, '.', '');
                break;
        }

        if ($value instanceof DOMElement) {
            $node->appendChild($value);
        } else {
            $node->appendChild($doc->createElement($name, (string) $value ?? ''));
        }
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
