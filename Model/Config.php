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

    public function getImportFilePath(string $filename, $entityType): string
    {
        return $this->getFilePath($filename, self::IMPORT, $entityType);
    }

    public function getExportFilePath(string $filename, string $entityType): string
    {
        return $this->getFilePath($filename, self::EXPORT, $entityType);
    }

    protected function getFilePath(string $filename, string $type, string $entityType): string
    {
        $directory =
            $this->directoryList->getPath('var') .
            DIRECTORY_SEPARATOR .
            trim($this->scopeConfig->getValue(sprintf('vendit/directory_mapping/%s/%s', $type, $entityType)), '/');

        if (!$this->ioFile->fileExists($directory)) {
            $this->ioFile->mkdir($directory, 0775);
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }
}
