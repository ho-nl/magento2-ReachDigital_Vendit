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
        private readonly ImportProductsXmlFactory $productImporterFactory,
        private readonly ImportStockXmlFactory $stockImporterFactory,
        private readonly ImportCategoriesXmlFactory $categoryImporterFactory,
        private readonly ImportCustomersXmlFactory $customerImporterFactory,
        private readonly ImportOrderStatusXmlFactory $orderImporterFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process files in processing subdirectory for given type
     *
     * @param string $type Import type (product, stock, category, customer, order)
     * @param int|null $limit Maximum number of files to process, or null for all
     * @return int Number of files processed
     */
    public function process(string $type, ?int $limit = null): int
    {
        $processingDir = $this->getProcessingDirectory($type);

        if (!is_dir($processingDir)) {
            return 0;
        }

        // Get all XML files matching the prefix for this type
        $files = $this->getMatchingFiles($processingDir, $type);

        if (empty($files)) {
            return 0;
        }

        if ($limit !== null) {
            $files = array_slice($files, 0, $limit);
        }

        $processed = 0;

        foreach ($files as $file) {
            try {
                $this->processFile($file, $type);
                $this->moveToProcessed($file, $type);
                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Failed to process %s import file %s: %s', $type, $file, $e->getMessage()),
                    [
                        'exception' => $e,
                    ],
                );
                $this->moveToFailed($file, $type, $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Get files matching the prefix for the import type
     */
    private function getMatchingFiles(string $directory, string $type): array
    {
        $prefix = $this->config->getFilePrefix($type);

        if (!$prefix) {
            // If no prefix configured, return all XML files
            $files = glob($directory . DIRECTORY_SEPARATOR . '*.xml') ?: [];
        } else {
            // Get files matching the prefix
            $files = glob($directory . DIRECTORY_SEPARATOR . $prefix . '*.xml') ?: [];
        }

        // Sort files to ensure correct processing order for batched imports
        return $this->sortImportFiles($files);
    }

    /**
     * Process single import file
     */
    private function processFile(string $filePath, string $type): void
    {
        $configuredPath = $this->getConfiguredImportPath($type, $filePath);

        // Remove existing file if present
        if ($this->ioFile->fileExists($configuredPath)) {
            $this->ioFile->rm($configuredPath);
        }

        // Copy file to configured location where importer expects it
        $this->ioFile->cp($filePath, $configuredPath);

        try {
            // Validate XML before processing
            $this->validateXmlFile($configuredPath);

            // Run the appropriate importer
            $importer = $this->getImporter($type);
            $result = $importer->run();

            // Check if import failed (Ho\Import returns CLI exit codes)
            if ($result !== \Magento\Framework\Console\Cli::RETURN_SUCCESS) {
                $errorMessage =
                    method_exists($importer, 'getException') && $importer->getException()
                        ? $importer->getException()->getMessage()
                        : 'Import failed with unknown error';
                throw new \Exception($errorMessage);
            }
        } finally {
            // Clean up the temporary file
            if ($this->ioFile->fileExists($configuredPath)) {
                $this->ioFile->rm($configuredPath);
            }
        }
    }

    /**
     * Validate XML file is well-formed before processing
     */
    private function validateXmlFile(string $filePath): void
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMessages = array_map(fn($e) => trim($e->message), $errors);
            throw new \Exception('Invalid XML file: ' . implode(', ', $errorMessages));
        }
    }

    /**
     * Create a fresh importer instance for type.
     * A new instance is required per file to avoid stale state in Ho\Import's ImportProfile
     * (error aggregator, row counters, attribute caches) carrying over between imports.
     */
    private function getImporter(
        string $type,
    ): ImportProductsXml|ImportStockXml|ImportCategoriesXml|ImportCustomersXml|ImportOrderStatusXml {
        return match ($type) {
            Config::TYPE_PRODUCT => $this->productImporterFactory->create(),
            Config::TYPE_STOCK => $this->stockImporterFactory->create(),
            Config::TYPE_CATEGORY => $this->categoryImporterFactory->create(),
            Config::TYPE_CUSTOMER => $this->customerImporterFactory->create(),
            Config::TYPE_ORDER => $this->orderImporterFactory->create(),
            default => throw new \InvalidArgumentException("Unknown import type: {$type}"),
        };
    }

    /**
     * Get processing directory for type
     */
    private function getProcessingDirectory(string $type): string
    {
        $baseDir = $this->getImportDirectory($type);

        return $baseDir . DIRECTORY_SEPARATOR . self::PROCESSING_SUBDIRECTORY;
    }

    /**
     * Get configured import file path (where importer expects the file)
     */
    private function getConfiguredImportPath(string $type, string $sourceFile = ''): string
    {
        $directory = $this->getImportDirectory($type);
        $prefix = $this->config->getFilePrefix($type);

        // Use source filename to create unique temp file
        if (!empty($sourceFile)) {
            $basename = basename($sourceFile);
            return $directory . DIRECTORY_SEPARATOR . $basename;
        }

        // Fallback to generic filename with the prefix
        return $directory . DIRECTORY_SEPARATOR . $prefix . '.xml';
    }

    /**
     * Move file to processed subdirectory
     */
    private function moveToProcessed(string $filePath, string $type): void
    {
        $baseDir = $this->getImportDirectory($type);
        $processedDir = $baseDir . DIRECTORY_SEPARATOR . self::PROCESSED_SUBDIRECTORY;

        if (!is_dir($processedDir)) {
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
        $baseDir = $this->getImportDirectory($type);
        $failedDir = $baseDir . DIRECTORY_SEPARATOR . self::FAILED_SUBDIRECTORY;

        if (!is_dir($failedDir)) {
            $this->ioFile->mkdir($failedDir, 0775);
        }

        $destination = $failedDir . DIRECTORY_SEPARATOR . basename($filePath);
        $this->ioFile->mv($filePath, $destination);

        // Write error log file
        $errorLogPath = $destination . '.error.log';
        file_put_contents($errorLogPath, sprintf("Error: %s\nTimestamp: %s\n", $errorMessage, date('Y-m-d H:i:s')));
    }

    /**
     * Sort import files to ensure correct processing order
     * Files without batch numbers come first, then files sorted by batch number
     * Example: Products_timestamp.xml, Products_1_timestamp.xml, Products_2_timestamp.xml
     */
    private function sortImportFiles(array $files): array
    {
        usort($files, function ($a, $b) {
            $filenameA = basename($a);
            $filenameB = basename($b);

            // Extract batch number from filename (e.g., Products_1_timestamp.xml -> 1)
            // Pattern: Prefix_BatchNumber_Timestamp.xml or Prefix_Timestamp.xml
            preg_match('/_(\d+)_\d+\.xml$/', $filenameA, $matchesA);
            preg_match('/_(\d+)_\d+\.xml$/', $filenameB, $matchesB);

            $batchA = isset($matchesA[1]) ? (int) $matchesA[1] : 0;
            $batchB = isset($matchesB[1]) ? (int) $matchesB[1] : 0;

            // Files without batch numbers (batch 0) come first
            if ($batchA === $batchB) {
                // If same batch number, sort alphabetically
                return strcmp($filenameA, $filenameB);
            }

            return $batchA <=> $batchB;
        });

        return $files;
    }

    /**
     * Get import directory for type
     */
    private function getImportDirectory(string $type): string
    {
        return match ($type) {
            Config::TYPE_ORDER => $this->config->getOrderImportDirectory(),
            default => $this->config->getImportFilesDirectory(),
        };
    }
}
