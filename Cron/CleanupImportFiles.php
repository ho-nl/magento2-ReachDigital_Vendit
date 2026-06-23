<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Cron;

use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\Config;

class CleanupImportFiles
{
    private const PROCESSED_SUBDIRECTORY = 'processed';
    private const FAILED_SUBDIRECTORY = 'failed';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $processedDays = $this->config->getImportProcessedRetentionDays();
        $failedDays = $this->config->getImportFailedRetentionDays();

        if ($processedDays === null && $failedDays === null) {
            return;
        }

        $importDirectories = array_unique([
            $this->config->getImportFilesDirectory(),
            $this->config->getOrderImportDirectory(),
        ]);

        foreach ($importDirectories as $baseDir) {
            if ($processedDays !== null) {
                $this->cleanupDirectory(
                    $baseDir . DIRECTORY_SEPARATOR . self::PROCESSED_SUBDIRECTORY,
                    $processedDays,
                );
            }

            if ($failedDays !== null) {
                $this->cleanupDirectory(
                    $baseDir . DIRECTORY_SEPARATOR . self::FAILED_SUBDIRECTORY,
                    $failedDays,
                );
            }
        }
    }

    private function cleanupDirectory(string $directory, int $retentionDays): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $deleted = 0;

        $files = glob($directory . DIRECTORY_SEPARATOR . '*') ?: [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                } else {
                    $this->logger->warning(sprintf('Vendit import cleanup: could not delete file %s', $file));
                }
            }
        }

        if ($deleted > 0) {
            $this->logger->info(
                sprintf('Vendit import cleanup: deleted %d file(s) older than %d day(s) from %s', $deleted, $retentionDays, $directory),
            );
        }
    }
}
