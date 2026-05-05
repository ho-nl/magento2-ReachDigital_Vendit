<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Controller\Webhook;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\Config;
use ReachDigital\Vendit\Model\MoveImportFiles;

class Import implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly MoveImportFiles $moveImportFiles,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setHttpResponseCode(503)->setData([
                'success' => false,
                'message' => 'Vendit integration is disabled',
            ]);
        }

        try {
            $movedFiles = $this->moveImportFiles->execute();

            if (empty($movedFiles)) {
                return $result->setData([
                    'success' => true,
                    'message' => 'No files to move',
                ]);
            }

            $this->logger->info('Vendit webhook: moved import files', ['files' => $movedFiles]);

            return $result->setData([
                'success' => true,
                'message' => sprintf('Successfully moved %d file(s) to processing queue', count($movedFiles)),
                'files' => $movedFiles,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Vendit webhook error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

}
