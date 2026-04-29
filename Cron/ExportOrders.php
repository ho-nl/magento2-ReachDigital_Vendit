<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Cron;

use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\Config;
use ReachDigital\Vendit\Model\ExportOrdersXml;

class ExportOrders
{
    public function __construct(
        private readonly ExportOrdersXml $exporter,
        private readonly State $state,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOrderExportEnabled()) {
            return;
        }

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        try {
            $exported = $this->exporter->execute();

            if ($exported) {
                $this->logger->info(sprintf('Vendit cron: exported %d order(s)', $exported));
            }
        } catch (\Exception $e) {
            $this->logger->error('Vendit order export cron error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
