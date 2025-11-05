<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Psr\Log\LoggerInterface;
use ReachDigital\Vendit\Model\MoveImportFiles;

class Import implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly MoveImportFiles $moveImportFiles,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

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

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
