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

    const XML_PATH_ATTRIBUTE_MAPPING = 'vendit_mapping/attribute_mapping/attributes';
    const XML_PATH_REQUIRED_ATTRIBUTES = 'vendit_mapping/attribute_mapping/required_attributes';
    const XML_PATH_SIZE_ATTRIBUTE = 'vendit_mapping/attribute_mapping/size_attribute';
    const XML_PATH_BARCODE_ATTRIBUTE = 'vendit_mapping/attribute_mapping/barcode_attribute';
    const XML_PATH_ORDER_STATUS_MAPPING = 'vendit_mapping/order_status_mapping/status_mapping';

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

    public function getImportFilesDirectory(): string
    {
        $path = trim($this->scopeConfig->getValue(self::DIR_MAPPING_CONFIG_PATH . '/import/file_path'), '/');

        $directory = $this->directoryList->getPath('var') . DIRECTORY_SEPARATOR . $path;

        if (!is_dir($directory)) {
            $this->ioFile->mkdir($directory, 0775);
        }

        return $directory;
    }

    public function getProductImportFilePath(): string
    {
        return $this->getImportFilePathForType(self::TYPE_PRODUCT);
    }

    public function getStockImportFilePath(): string
    {
        return $this->getImportFilePathForType(self::TYPE_STOCK);
    }

    public function getCategoryImportFilePath(): string
    {
        return $this->getImportFilePathForType(self::TYPE_CATEGORY);
    }

    public function getCustomerImportFilePath(): string
    {
        return $this->getImportFilePathForType(self::TYPE_CUSTOMER);
    }

    public function getOrderImportFilePath(): string
    {
        $directory = $this->getOrderImportDirectory();
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.xml');

        // Return first XML file found, or construct path with generic name
        if (!empty($files)) {
            return $files[0];
        }

        return $directory . DIRECTORY_SEPARATOR . 'Orderstatus.xml';
    }

    private function getImportFilePathForType(string $type): string
    {
        $directory = $this->getImportFilesDirectory();
        $prefix = $this->getFilePrefix($type);

        if (!$prefix) {
            return $directory . DIRECTORY_SEPARATOR . ucfirst($type) . '.xml';
        }

        // Check if there are any files matching the prefix
        $files = glob($directory . DIRECTORY_SEPARATOR . $prefix . '*.xml');

        // Return first matching file, or construct path with prefix
        if (!empty($files)) {
            return $files[0];
        }

        return $directory . DIRECTORY_SEPARATOR . $prefix . '.xml';
    }

    public function getOrderImportDirectory(): string
    {
        $path = trim($this->scopeConfig->getValue(self::DIR_MAPPING_CONFIG_PATH . '/import/order_path'), '/');

        $directory = $this->directoryList->getPath('var') . DIRECTORY_SEPARATOR . $path;

        if (!is_dir($directory)) {
            $this->ioFile->mkdir($directory, 0775);
        }

        return $directory;
    }

    public function getFilePrefix(string $entityType): ?string
    {
        return $this->scopeConfig->getValue(
            sprintf(self::DIR_MAPPING_CONFIG_PATH . '/import/%s_file_prefix', $entityType),
        );
    }

    public function getCustomerExportFilePath(): string
    {
        return $this->getExportFilePath(self::TYPE_CUSTOMER);
    }

    public function getOrderExportFilePath(): string
    {
        return $this->getExportFilePath(self::TYPE_ORDER);
    }

    protected function getExportFilePath(string $entityType): string
    {
        $path = trim(
            $this->scopeConfig->getValue(sprintf(self::DIR_MAPPING_CONFIG_PATH . '/export/%s_path', $entityType)),
            '/',
        );
        $filename = trim(
            $this->scopeConfig->getValue(sprintf(self::DIR_MAPPING_CONFIG_PATH . '/export/%s_file', $entityType)),
            '/',
        );

        $directory = $this->directoryList->getPath('var') . DIRECTORY_SEPARATOR . $path;

        if (!is_dir($directory)) {
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

    /**
     * Get order status mapping (Vendit Status ID => Magento Status)
     */
    public function getOrderStatusMapping(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ORDER_STATUS_MAPPING);

        if (!$value) {
            return [];
        }

        try {
            $mapping = $this->serializer->unserialize($value);

            if (!is_array($mapping)) {
                return [];
            }

            // Convert to vendit_status_id => magento_status format
            $result = [];
            foreach ($mapping as $statusMap) {
                if (
                    isset($statusMap['vendit_status_id']) &&
                    isset($statusMap['magento_status']) &&
                    !empty($statusMap['vendit_status_id']) &&
                    !empty($statusMap['magento_status'])
                ) {
                    $result[$statusMap['vendit_status_id']] = $statusMap['magento_status'];
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
}
