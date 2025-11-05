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

    public function __construct(
        private readonly Config $config,
        private readonly IoFile $ioFile,
    ) {
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
            $moved = $this->moveImportFile($type);
            if ($moved) {
                $movedFiles[] = $moved;
            }
        }

        return $movedFiles;
    }

    /**
     * Move import file for specific type to processing subdirectory
     *
     * @param string $type
     * @return string|null Path to moved file, or null if no file to move
     * @throws \Exception
     */
    private function moveImportFile(string $type): ?string
    {
        $sourcePath = $this->getImportFilePath($type);

        if (!$this->ioFile->fileExists($sourcePath)) {
            return null;
        }

        // Create processing subdirectory if it doesn't exist
        $sourceDir = dirname($sourcePath);
        $processingDir = $sourceDir . DIRECTORY_SEPARATOR . self::PROCESSING_SUBDIRECTORY;

        if (!$this->ioFile->fileExists($processingDir)) {
            $this->ioFile->mkdir($processingDir, 0775);
        }

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

        return $destinationPath;
    }

    /**
     * Get import file path for specific type
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
