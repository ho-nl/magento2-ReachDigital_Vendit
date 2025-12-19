<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Cron;

use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\MoveImportFiles;
use ReachDigital\Vendit\Model\ProcessImportFiles;
use ReachDigital\Vendit\Model\Config;

class ProcessCustomerImport
{
    public function __construct(
        private readonly MoveImportFiles $moveImportFiles,
        private readonly ProcessImportFiles $processImportFiles,
        private readonly State $state,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        try {
            // First, move any new files to processing queue
            $this->moveImportFiles->execute();

            // Then process files from the processing queue
            $processed = $this->processImportFiles->process(Config::TYPE_CUSTOMER);

            if ($processed) {
                $this->logger->info(sprintf('Vendit cron: processed %d customer import file(s)', $processed));
            }
        } catch (\Exception $e) {
            $this->logger->error('Vendit customer import cron error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
