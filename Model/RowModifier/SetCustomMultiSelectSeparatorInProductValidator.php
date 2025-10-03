<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\RowModifier;

use Magento\CatalogImportExport\Model\Import\Product;

class SetCustomMultiSelectSeparatorInProductValidator
{
    const DELIMITER = '~';

    public function beforeParseMultiselectValues(Product $subject, string $values, string $delimiter = ''): array
    {
        $delimiter = self::DELIMITER;

        return [$values, $delimiter];
    }
}
