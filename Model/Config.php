<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Serialize\SerializerInterface;

class Config
{
    const DIR_MAPPING_CONFIG_PATH = 'vendit/directory_mapping';

    const XML_PATH_ATTRIBUTE_MAPPING = 'vendit/attribute_mapping/attributes';
    const XML_PATH_REQUIRED_ATTRIBUTES = 'vendit/attribute_mapping/required_attributes';
    const XML_PATH_SIZE_ATTRIBUTE = 'vendit/attribute_mapping/size_attribute';
    const XML_PATH_BARCODE_ATTRIBUTE = 'vendit/attribute_mapping/barcode_attribute';

    const TYPE_CATEGORY = 'category';
    const TYPE_CUSTOMER = 'customer';
    const TYPE_ORDER = 'order';
    const TYPE_PRODUCT = 'product';
    const TYPE_STOCK = 'stock';

    const EXPORT = 'export';
    const IMPORT = 'import';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly IoFile $ioFile,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function getCategoryImportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_CATEGORY, self::IMPORT);
    }

    public function getCustomerImportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_CUSTOMER, self::IMPORT);
    }

    public function getCustomerExportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_CUSTOMER, self::EXPORT);
    }

    public function getOrderImportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_ORDER, self::IMPORT);
    }

    public function getOrderExportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_ORDER, self::EXPORT);
    }

    public function getProductImportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_PRODUCT, self::IMPORT);
    }

    public function getStockImportFilePath(): string
    {
        return $this->getFilePath(self::TYPE_STOCK, self::IMPORT);
    }

    protected function getFilePath(string $entityType, string $type): string
    {
        $path = trim(
            $this->scopeConfig->getValue(sprintf(self::DIR_MAPPING_CONFIG_PATH . '/%s/%s_path', $type, $entityType)),
            '/',
        );
        $filename = trim(
            $this->scopeConfig->getValue(sprintf(self::DIR_MAPPING_CONFIG_PATH . '/%s/%s_file', $type, $entityType)),
            '/',
        );

        $directory = $this->directoryList->getPath('var') . DIRECTORY_SEPARATOR . $path;

        if (!$this->ioFile->fileExists($directory)) {
            $this->ioFile->mkdir($directory, 0775);
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Get attribute mapping (spec name => attribute code)
     */
    public function getAttributeMapping(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTE_MAPPING);

        if (!$value) {
            return [];
        }

        try {
            $mapping = $this->serializer->unserialize($value);

            if (!is_array($mapping)) {
                return [];
            }

            // Convert to spec_name => attribute_code format
            $result = [];
            foreach ($mapping as $attribute) {
                if (
                    isset($attribute['spec_name']) &&
                    isset($attribute['attribute_code']) &&
                    !empty($attribute['spec_name']) &&
                    !empty($attribute['attribute_code'])
                ) {
                    $result[$attribute['spec_name']] = $attribute['attribute_code'];
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function isMapped(string $specName): bool
    {
        $mapping = $this->getAttributeMapping();
        return isset($mapping[$specName]);
    }

    public function getAttributeCode(string $specName): ?string
    {
        $mapping = $this->getAttributeMapping();
        return $mapping[$specName] ?? null;
    }

    public function getRequiredAttributes(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_REQUIRED_ATTRIBUTES);

        if (!$value) {
            return [];
        }

        // Split by comma and trim whitespace
        $attributes = array_map('trim', explode(',', $value));

        // Remove empty values
        return array_filter($attributes);
    }

    public function isRequired(string $attributeCode): bool
    {
        return in_array($attributeCode, $this->getRequiredAttributes(), true);
    }

    public function getSizeAttribute(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_SIZE_ATTRIBUTE);
    }

    public function getBarcodeAttribute(): ?string
    {
        return $this->scopeConfig->getValue(self::XML_PATH_BARCODE_ATTRIBUTE);
    }
}
