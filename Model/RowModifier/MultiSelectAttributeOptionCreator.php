<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\RowModifier;

use Ho\Import\RowModifier\AttributeOptionCreator;

class MultiSelectAttributeOptionCreator extends AttributeOptionCreator
{
    protected function getNonExistingAttributes(string $attribute): array
    {
        $uniqueValues = [];
        foreach ($this->items as $identifier => $item) {
            if (empty($item[$attribute])) {
                continue;
            }

            if (!\is_string($item[$attribute])) {
                $this->consoleOutput->writeln(
                    "<error>AttributeOptionCreator: Invalid value for {$attribute} {$identifier}</error>",
                );
                $this->log->error("AttributeOptionCreator: Invalid value for {$attribute} {$identifier}");

                $item[$attribute] = '';
                continue;
            }

            $values = explode(SetCustomMultiSelectSeparatorInProductValidator::DELIMITER, $item[$attribute]);

            foreach ($values as $value) {
                $uniqueValues[$value] = $value;
            }
        }

        return $uniqueValues;
    }
}
