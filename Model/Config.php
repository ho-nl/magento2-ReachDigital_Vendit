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

class Config
{
    const DIR_MAPPING_CONFIG_PATH = 'vendit/directory_mapping';

    const TYPE_PRODUCT = 'product';
    const TYPE_CATEGORY = 'category';
    const TYPE_STOCK = 'stock';
    const TYPE_CUSTOMER = 'customer';

    const EXPORT = 'export';
    const IMPORT = 'import';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly IoFile $ioFile,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function getImportFilePath($entityType): string
    {
        return $this->getFilePath($entityType, self::IMPORT);
    }

    public function getExportFilePath(string $entityType): string
    {
        return $this->getFilePath($entityType, self::EXPORT);
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
}
