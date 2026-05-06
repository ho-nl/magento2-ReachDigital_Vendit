<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Cron;

use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\Config;
use ReachDigital\Vendit\Model\ResourceModel\ExportedOrder as ExportedOrderResource;

class CleanupExportXml
{
    public function __construct(
        private readonly Config $config,
        private readonly ExportedOrderResource $exportedOrderResource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $months = $this->config->getExportXmlRetentionMonths();

        if ($months === null) {
            return;
        }

        try {
            $affected = $this->exportedOrderResource->clearOldExportXml($months);

            if ($affected > 0) {
                $this->logger->info(
                    sprintf('Vendit cron: cleared export XML for %d order(s) older than %d month(s)', $affected, $months),
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Vendit export XML cleanup cron error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
