<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Io\File as IoFile;

class Config
{
    const DIRECTORY_MAIN = 'vendit';
    const DIRECTORY_EXPORT = 'export';
    const DIRECTORY_IMPORT = 'import';

    public function __construct(public DirectoryList $directoryList, public IoFile $ioFile)
    {
    }

    public function getFilePath(string $filename, string $type = self::DIRECTORY_EXPORT): string
    {
        $directory =
            $this->directoryList->getPath('var') .
            DIRECTORY_SEPARATOR .
            self::DIRECTORY_MAIN .
            DIRECTORY_SEPARATOR .
            $type;
        if (!$this->ioFile->fileExists($directory)) {
            $this->ioFile->mkdir($directory, 0775);
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }
}
