<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\Filesystem\Io\File as IoFile;

class MoveImportFiles
{
    private const PROCESSING_SUBDIRECTORY = 'processing';

    public function __construct(private readonly Config $config, private readonly IoFile $ioFile)
    {
    }

    /**
     * Move all import files to processing subdirectory
     *
     * @return array List of moved files
     * @throws \Exception
     */
    public function execute(): array
    {
        $movedFiles = [];

        // Define all import types with their enabled check methods
        $importTypes = [
            Config::TYPE_PRODUCT => 'isProductImportEnabled',
            Config::TYPE_STOCK => 'isStockImportEnabled',
            Config::TYPE_CATEGORY => 'isCategoryImportEnabled',
            Config::TYPE_CUSTOMER => 'isCustomerImportEnabled',
            Config::TYPE_ORDER => 'isOrderUpdateImportEnabled',
        ];

        foreach ($importTypes as $type => $enabledMethod) {
            // Only move files if the integration is enabled
            if (!$this->config->$enabledMethod()) {
                continue;
            }

            $moved = $this->moveImportFiles($type);
            $movedFiles = array_merge($movedFiles, $moved);
        }

        return $movedFiles;
    }

    /**
     * Move all import files for specific type to processing subdirectory
     *
     * @param string $type
     * @return array Paths to moved files
     * @throws \Exception
     */
    private function moveImportFiles(string $type): array
    {
        $movedFiles = [];
        $sourceDir = $this->getImportDirectory($type);
        $prefix = $this->config->getFilePrefix($type);

        // Get all matching files
        if ($prefix) {
            $files = glob($sourceDir . DIRECTORY_SEPARATOR . $prefix . '*.xml') ?: [];
        } else {
            $files = glob($sourceDir . DIRECTORY_SEPARATOR . '*.xml') ?: [];
        }

        if (empty($files)) {
            return [];
        }

        // Sort files to ensure correct processing order for batched imports
        // Files with batch numbers (e.g., Products_1_timestamp.xml) should be sorted by batch number
        $files = $this->sortImportFiles($files);

        // Create processing subdirectory if it doesn't exist
        $processingDir = $sourceDir . DIRECTORY_SEPARATOR . self::PROCESSING_SUBDIRECTORY;

        if (!is_dir($processingDir)) {
            $this->ioFile->mkdir($processingDir, 0775);
        }

        foreach ($files as $sourcePath) {
            // Generate unique filename with timestamp
            $filename = basename($sourcePath);
            $timestamp = date('YmdHis');
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $newFilename = sprintf('%s_%s.%s', $nameWithoutExt, $timestamp, $extension);

            $destinationPath = $processingDir . DIRECTORY_SEPARATOR . $newFilename;

            // Move file
            if (!$this->ioFile->mv($sourcePath, $destinationPath)) {
                throw new \Exception(sprintf('Failed to move file from %s to %s', $sourcePath, $destinationPath));
            }

            $movedFiles[] = $destinationPath;
        }

        return $movedFiles;
    }

    /**
     * Sort import files to ensure correct processing order
     * Files without batch numbers come first, then files sorted by batch number
     * Example: Products.xml, Products_1.xml, Products_2.xml
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
     * Get import directory for specific type
     */
    private function getImportDirectory(string $type): string
    {
        return match ($type) {
            Config::TYPE_ORDER => $this->config->getOrderImportDirectory(),
            default => $this->config->getImportFilesDirectory(),
        };
    }
}
