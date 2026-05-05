<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReachDigital\Vendit\Model\Config;

class StockSkuSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::STOCK_SKU_SOURCE_PRODUCT_ID, 'label' => __('ProductId')],
            ['value' => Config::STOCK_SKU_SOURCE_BARCODE, 'label' => __('Barcode')],
        ];
    }
}
