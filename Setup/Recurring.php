<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Setup;

use Magento\Framework\App\Config\Initial as InitialConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class Recurring implements InstallSchemaInterface
{
    private const CONFIG_PATH = 'vendit/directory_mapping';
    private const BASE_DIRECTORY = 'vendit';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly IoFile $ioFile,
        private readonly InitialConfig $initialConfig,
    ) {
    }

    /**
     * Create import/export directories during module installation
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $varPath = $this->directoryList->getPath('var');
        $directories = $this->getDirectoriesFromConfig();

        foreach ($directories as $directory) {
            // Prepend base directory to all paths
            $fullPath = $varPath . DIRECTORY_SEPARATOR . self::BASE_DIRECTORY . DIRECTORY_SEPARATOR . $directory;

            if (!$this->ioFile->fileExists($fullPath)) {
                $this->ioFile->mkdir($fullPath, 0775);
            }
        }
    }

    private function getDirectoriesFromConfig(): array
    {
        $directories = [];
        $config = $this->initialConfig->getData('default');

        // Navigate through the config path
        $pathParts = explode('/', self::CONFIG_PATH);
        $directoryMapping = $config;

        foreach ($pathParts as $part) {
            if (!isset($directoryMapping[$part])) {
                return $directories;
            }
            $directoryMapping = $directoryMapping[$part];
        }

        // Process import and export sections
        foreach (['import', 'export'] as $type) {
            if (!isset($directoryMapping[$type])) {
                continue;
            }

            foreach ($directoryMapping[$type] as $key => $value) {
                // Extract only *_path entries (not *_file entries)
                if (str_ends_with($key, '_path') && is_string($value)) {
                    $directories[] = trim($value, '/');
                }
            }
        }

        return array_unique($directories);
    }
}
