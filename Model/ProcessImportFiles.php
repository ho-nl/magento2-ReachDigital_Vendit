<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\Filesystem\Io\File as IoFile;
use Psr\Log\LoggerInterface;

class ProcessImportFiles
{
    private const PROCESSING_SUBDIRECTORY = 'processing';
    private const PROCESSED_SUBDIRECTORY = 'processed';
    private const FAILED_SUBDIRECTORY = 'failed';

    public function __construct(
        private readonly Config $config,
        private readonly IoFile $ioFile,
        private readonly ImportProductsXml $productImporter,
        private readonly ImportStockXml $stockImporter,
        private readonly ImportCategoriesXml $categoryImporter,
        private readonly ImportCustomersXml $customerImporter,
        private readonly ImportOrderStatusXml $orderImporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process all files in processing subdirectory for given type
     *
     * @param string $type Import type (product, stock, category, customer, order)
     * @return int Number of files processed
     */
    public function process(string $type): int
    {
        $processingDir = $this->getProcessingDirectory($type);

        if (!$this->ioFile->fileExists($processingDir)) {
            return 0;
        }

        // Get all XML files in processing directory
        $files = glob($processingDir . DIRECTORY_SEPARATOR . '*.xml');

        if (empty($files)) {
            return 0;
        }

        $processed = 0;

        foreach ($files as $file) {
            try {
                $this->processFile($file, $type);
                $this->moveToProcessed($file, $type);
                $processed++;
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Failed to process %s import file %s: %s', $type, $file, $e->getMessage()), [
                    'exception' => $e,
                ]);
                $this->moveToFailed($file, $type, $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Process single import file
     */
    private function processFile(string $filePath, string $type): void
    {
        // Temporarily update config to point to the file in processing directory
        $originalPath = $this->getImportFilePath($type);
        $configuredPath = $this->getConfiguredImportPath($type);

        // Create a symlink or copy to the original location
        if ($this->ioFile->fileExists($configuredPath)) {
            $this->ioFile->rm($configuredPath);
        }

        // Copy file to configured location
        $this->ioFile->cp($filePath, $configuredPath);

        try {
            // Run the appropriate importer
            $importer = $this->getImporter($type);
            $importer->run();
        } finally {
            // Clean up the temporary file
            if ($this->ioFile->fileExists($configuredPath)) {
                $this->ioFile->rm($configuredPath);
            }
        }
    }

    /**
     * Get importer instance for type
     */
    private function getImporter(string $type): ImportProductsXml|ImportStockXml|ImportCategoriesXml|ImportCustomersXml|ImportOrderStatusXml
    {
        return match ($type) {
            Config::TYPE_PRODUCT => $this->productImporter,
            Config::TYPE_STOCK => $this->stockImporter,
            Config::TYPE_CATEGORY => $this->categoryImporter,
            Config::TYPE_CUSTOMER => $this->customerImporter,
            Config::TYPE_ORDER => $this->orderImporter,
            default => throw new \InvalidArgumentException("Unknown import type: {$type}"),
        };
    }

    /**
     * Get processing directory for type
     */
    private function getProcessingDirectory(string $type): string
    {
        $importPath = $this->getImportFilePath($type);
        $baseDir = dirname($importPath);

        return $baseDir . DIRECTORY_SEPARATOR . self::PROCESSING_SUBDIRECTORY;
    }

    /**
     * Get configured import file path (where importer expects the file)
     */
    private function getConfiguredImportPath(string $type): string
    {
        return $this->getImportFilePath($type);
    }

    /**
     * Move file to processed subdirectory
     */
    private function moveToProcessed(string $filePath, string $type): void
    {
        $importPath = $this->getImportFilePath($type);
        $baseDir = dirname($importPath);
        $processedDir = $baseDir . DIRECTORY_SEPARATOR . self::PROCESSED_SUBDIRECTORY;

        if (!$this->ioFile->fileExists($processedDir)) {
            $this->ioFile->mkdir($processedDir, 0775);
        }

        $destination = $processedDir . DIRECTORY_SEPARATOR . basename($filePath);
        $this->ioFile->mv($filePath, $destination);
    }

    /**
     * Move file to failed subdirectory with error log
     */
    private function moveToFailed(string $filePath, string $type, string $errorMessage): void
    {
        $importPath = $this->getImportFilePath($type);
        $baseDir = dirname($importPath);
        $failedDir = $baseDir . DIRECTORY_SEPARATOR . self::FAILED_SUBDIRECTORY;

        if (!$this->ioFile->fileExists($failedDir)) {
            $this->ioFile->mkdir($failedDir, 0775);
        }

        $destination = $failedDir . DIRECTORY_SEPARATOR . basename($filePath);
        $this->ioFile->mv($filePath, $destination);

        // Write error log file
        $errorLogPath = $destination . '.error.log';
        file_put_contents($errorLogPath, sprintf(
            "Error: %s\nTimestamp: %s\n",
            $errorMessage,
            date('Y-m-d H:i:s')
        ));
    }

    /**
     * Get import file path for type
     */
    private function getImportFilePath(string $type): string
    {
        return match ($type) {
            Config::TYPE_PRODUCT => $this->config->getProductImportFilePath(),
            Config::TYPE_STOCK => $this->config->getStockImportFilePath(),
            Config::TYPE_CATEGORY => $this->config->getCategoryImportFilePath(),
            Config::TYPE_CUSTOMER => $this->config->getCustomerImportFilePath(),
            Config::TYPE_ORDER => $this->config->getOrderImportFilePath(),
            default => throw new \InvalidArgumentException("Unknown import type: {$type}"),
        };
    }
}
