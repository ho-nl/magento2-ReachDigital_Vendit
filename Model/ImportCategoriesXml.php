<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Ho\Import\Logger\Log;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList as AppDirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;
use Opengento\CategoryImportExport\Model\Import\Categories as OpenGentoImporter;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Stopwatch\Stopwatch;

class ImportCategoriesXml
{
    private bool $dryRun = true;
    private array $categoryTree = [];
    private array $existingCategories = [];
    private int $importedCount = 0;

    public function __construct(
        private readonly Stopwatch $stopwatch,
        private readonly ConsoleOutput $consoleOutput,
        private readonly Log $log,
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly OpenGentoImporter $openGentoImporter,
    ) {
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getCategoryTree(): array
    {
        return $this->categoryTree;
    }

    public function run(): int
    {
        if ($this->dryRun) {
            $this->log->info('DRY RUN MODE - No categories will be imported');
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        }

        $this->stopwatch->start('import');
        $this->consoleOutput->writeln('Getting item data');

        // Flatten the tree and convert to OpenGento format
        $flatCategories = $this->flattenCategoryTree($this->categoryTree);
        $data = $this->convertToOpenGentoFormat($flatCategories);

        $this->consoleOutput->writeln('Mapping item information');

        // Sort data so parents are imported before children
        // Categories with parent_id (root level) should go first
        // Then sort by dependency (parent_code)
        $data = $this->sortByDependency($data);

        // Use OpenGento importer - import one by one to handle dependencies
        try {
            foreach ($data as $row) {
                $this->openGentoImporter->execute([$row]);
                $this->importedCount++;
            }
        } catch (\Exception $e) {
            $this->log->error('Category import failed: ' . $e->getMessage());
            throw $e;
        }

        $event = $this->stopwatch->stop('import');
        $message = sprintf(
            '%d items processed in %s sec, %s items / sec (%smb used)',
            $this->importedCount,
            round($event->getDuration() / 1000, 1),
            $this->importedCount > 0 ? round($this->importedCount / ($event->getDuration() / 1000), 1) : 0,
            round($event->getMemory() / 1024 / 1024, 1)
        );

        $this->consoleOutput->writeln($message);
        $this->log->info($message);

        return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
    }

    /**
     * Sort categories so parents are imported before children
     */
    private function sortByDependency(array $data): array
    {
        $sorted = [];
        $remaining = $data;
        $maxIterations = count($data) * 2; // Prevent infinite loops
        $iteration = 0;

        // Categories pointing to root_catalog_default_category can go first
        while (!empty($remaining) && $iteration < $maxIterations) {
            $addedThisRound = false;

            foreach ($remaining as $key => $category) {
                // If parent_code is the root category, add it immediately
                if ($category['parent_code'] === 'root_catalog_default_category') {
                    $sorted[] = $category;
                    unset($remaining[$key]);
                    $addedThisRound = true;
                    continue;
                }

                // Check if parent already in sorted
                $parentExists = false;
                foreach ($sorted as $sortedCat) {
                    if ($sortedCat['category_code'] === $category['parent_code']) {
                        $parentExists = true;
                        break;
                    }
                }

                if ($parentExists) {
                    $sorted[] = $category;
                    unset($remaining[$key]);
                    $addedThisRound = true;
                }
            }

            if (!$addedThisRound) {
                // No progress made, break to avoid infinite loop
                // Add remaining categories anyway (orphaned)
                $sorted = array_merge($sorted, $remaining);
                break;
            }

            $iteration++;
        }

        return $sorted;
    }

    /**
     * Convert category data to OpenGento format
     */
    private function convertToOpenGentoFormat(array $flatCategories): array
    {
        $data = [];
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $rootCategoryId = $defaultStore->getRootCategoryId();

        foreach ($flatCategories as $category) {
            $row = [
                'category_code' => $category['guid'],
                'name' => $category['name'],
                'is_active' => true,
                'include_in_menu' => true,
                'position' => $category['position'] ?? 0,
            ];

            // Add parent if not root level
            if (!empty($category['parent_guid']) && $category['parent_guid'] !== '00000000-0000-0000-0000-000000000000') {
                $row['parent_code'] = $category['parent_guid'];
            } else {
                // Root level categories go under the store's root category
                // Use parent_code to reference the default category
                $row['parent_code'] = 'root_catalog_default_category';
            }

            // Add optional fields
            if (!empty($category['description'])) {
                $row['description'] = $category['description'];
            }
            if (!empty($category['meta_title'])) {
                $row['meta_title'] = $category['meta_title'];
            }
            if (!empty($category['meta_keywords'])) {
                $row['meta_keywords'] = $category['meta_keywords'];
            }
            if (!empty($category['meta_description'])) {
                $row['meta_description'] = $category['meta_description'];
            }
            if (!empty($category['url_key']) && !isset($this->existingCategories[$category['path']])) {
                $row['url_key'] = $category['url_key'];
            }

            $data[] = $row;
        }

        return $data;
    }

    public function loadCategories(): array
    {
        $xmlFilePath = $this->config->getCategoryImportFilePath();
        if (!file_exists($xmlFilePath)) {
            $this->log->error("Categories XML file not found: {$xmlFilePath}");
            throw new \Exception("Categories XML file not found: {$xmlFilePath}");
        }

        // Read file content
        $content = file_get_contents($xmlFilePath);
        if ($content === false) {
            throw new \Exception('Failed to read categories XML file');
        }

        // Remove UTF-8 BOM if present (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = sprintf('Line %d: %s', $error->line, trim($error->message));
            }
            libxml_clear_errors();
            throw new \Exception('Failed to parse categories XML file: ' . implode('; ', $errorMessages));
        }
        libxml_use_internal_errors(false);

        $tree = [];
        foreach ($xml->Groups->Group as $groupNode) {
            $group = $this->parseGroup($groupNode);
            if ($group) {
                $tree[] = $group;
            }
        }

        // Load existing Magento categories for comparison
        $this->loadExistingCategories();

        // Store tree for later use
        $this->categoryTree = $tree;

        return $tree;
    }

    private function loadExistingCategories(): void
    {
        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'position', 'is_active']);

        // First pass: build a map of category ID to category for lookups
        $categoriesById = [];
        foreach ($collection as $category) {
            $categoriesById[$category->getId()] = $category;
        }

        // Second pass: build paths
        $this->existingCategories = [];
        foreach ($collection as $category) {
            $pathIds = explode('/', $category->getPath());

            // Skip only the root catalog (ID 1, level 0)
            if (count($pathIds) < 2) {
                continue;
            }

            // Build path from category names, skipping only root catalog (ID 1)
            $pathNames = [];
            foreach ($pathIds as $pathId) {
                if ($pathId == 1) {
                    // Skip root catalog
                    continue;
                }
                if (isset($categoriesById[$pathId])) {
                    $pathNames[] = $categoriesById[$pathId]->getName();
                }
            }

            if (empty($pathNames)) {
                continue;
            }

            $categoryPath = implode('/', $pathNames);
            $this->existingCategories[$categoryPath] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'path' => $categoryPath,
                'url_key' => $category->getUrlKey(),
                'position' => (int) $category->getPosition(),
                'is_active' => (bool) $category->getIsActive(),
            ];
        }
    }

    private function parseGroup(\SimpleXMLElement $groupNode, string $parentPath = ''): ?array
    {
        $name = (string) $groupNode->GroupName;
        if (empty($name)) {
            return null;
        }

        $guid = (string) $groupNode->GroupGuid;
        $parentGuid = (string) ($groupNode->Parent_Guid ?? '00000000-0000-0000-0000-000000000000');

        // Build category path
        $urlName = (string) ($groupNode->GroupUrlName ?? $name);
        $urlKey = $this->sanitizeUrlKey($urlName);
        $path = $parentPath ? $parentPath . '/' . $name : $name;

        $category = [
            'guid' => $guid,
            'parent_guid' => $parentGuid,
            'name' => $name,
            'path' => $path,
            'description' => (string) ($groupNode->GroupDescription ?? ''),
            'meta_title' => (string) ($groupNode->GroupMetaTitle ?? ''),
            'meta_keywords' => (string) ($groupNode->GroupMetaKeywords ?? ''),
            'meta_description' => (string) ($groupNode->GroupMetaDescription ?? ''),
            'url_key' => $urlKey,
            'position' => (int) ($groupNode->ItemOrder ?? 0),
            'children' => [],
        ];

        // Recursively parse sub-groups
        if (isset($groupNode->SubGroups->Group)) {
            foreach ($groupNode->SubGroups->Group as $subGroupNode) {
                $child = $this->parseGroup($subGroupNode, $path);
                if ($child) {
                    $category['children'][] = $child;
                }
            }

            // Sort children by position
            usort($category['children'], function ($a, $b) {
                return $a['position'] <=> $b['position'];
            });
        }

        return $category;
    }

    private function flattenCategoryTree(array $tree, array &$flat = []): array
    {
        foreach ($tree as $category) {
            $children = $category['children'];
            unset($category['children']);

            $flat[] = $category;

            if (!empty($children)) {
                $this->flattenCategoryTree($children, $flat);
            }
        }

        return $flat;
    }

    private function sanitizeUrlKey(string $name): string
    {
        // Convert to lowercase
        $urlKey = mb_strtolower($name);

        // Replace spaces and special characters with dashes
        $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);

        // Remove leading/trailing dashes
        $urlKey = trim($urlKey, '-');

        return $urlKey;
    }

    /**
     * Save mapped items as json for debugging.
     *
     * @throws FileSystemException
     */
    public function saveProcessedItems(array $items): void
    {
        $directory = $this->filesystem->getDirectoryWrite(AppDirectoryList::VAR_DIR);
        $stream = $directory->openFile('importexport/categories_import.json', 'w+');
        $stream->lock();
        $stream->write("{\n");

        $i = 1;
        foreach ($items as $item) {
            $stream->write("\"$i\":" . json_encode($item) . (count($items) == $i ? '' : ',') . "\n");
            $i++;
        }

        $stream->write("}\n");
        $stream->unlock();
    }

    /**
     * Display category tree in CLI format with comparison indicators
     */
    public function displayCategoryTree(array $tree, int $level = 0): string
    {
        $output = '';
        foreach ($tree as $category) {
            $indent = str_repeat('  ', $level);
            $path = $category['path'];
            $status = $this->getCategoryStatus($path, $category);

            $output .= $indent . '    ├─ ';

            // Add color coding based on status
            switch ($status['type']) {
                case 'new':
                    $output .= '<fg=green>[NEW]</> ' . $category['name'];
                    break;
                case 'changed':
                    $output .= '<fg=yellow>[MODIFIED]</> ' . $category['name'];
                    $output .= ' ' . $status['changes'];
                    break;
                case 'unchanged':
                    $output .= $category['name'];
                    break;
            }

            $output .= ' (position: ' . $category['position'] . ')';
            $output .= "\n";

            if (!empty($category['children'])) {
                $output .= $this->displayCategoryTree($category['children'], $level + 1);
            }
        }
        return $output;
    }

    /**
     * Get category status by comparing with existing categories
     */
    private function getCategoryStatus(string $path, array $category): array
    {
        if (!isset($this->existingCategories[$path])) {
            return ['type' => 'new'];
        }

        $existing = $this->existingCategories[$path];
        $changes = [];

        if ($category['position'] != $existing['position']) {
            $changes[] = "pos: {$existing['position']} → {$category['position']}";
        }

        // Don't compare URL keys - we preserve existing ones and don't update them

        if (!empty($changes)) {
            return [
                'type' => 'changed',
                'changes' => '<fg=cyan>(' . implode(', ', $changes) . ')</>',
            ];
        }

        return ['type' => 'unchanged'];
    }

    /**
     * Get categories that exist in Magento but not in XML (will be orphaned)
     */
    public function getOrphanedCategories(array $tree): array
    {
        $xmlPaths = $this->collectAllPaths($tree);
        $orphaned = [];

        foreach ($this->existingCategories as $path => $existing) {
            // Exclude Default Category (the root under which all categories are imported)
            if ($path === 'Default Category') {
                continue;
            }

            if (!in_array($path, $xmlPaths)) {
                $orphaned[] = $existing;
            }
        }

        return $orphaned;
    }

    /**
     * Recursively collect all category paths from tree
     */
    private function collectAllPaths(array $tree): array
    {
        $paths = [];
        foreach ($tree as $category) {
            $paths[] = $category['path'];
            if (!empty($category['children'])) {
                $paths = array_merge($paths, $this->collectAllPaths($category['children']));
            }
        }
        return $paths;
    }
}
