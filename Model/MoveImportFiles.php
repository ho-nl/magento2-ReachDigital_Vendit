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

        // Define all import types
        $importTypes = [
            Config::TYPE_PRODUCT,
            Config::TYPE_STOCK,
            Config::TYPE_CATEGORY,
            Config::TYPE_CUSTOMER,
            Config::TYPE_ORDER,
        ];

        foreach ($importTypes as $type) {
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
