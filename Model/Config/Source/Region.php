<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Amazon Pay's regions: where the merchant account lives decides the API endpoint.
 */
class Region implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'eu', 'label' => __('Europe and the UK (EUR, GBP)')],
            ['value' => 'na', 'label' => __('North America (USD)')],
            ['value' => 'jp', 'label' => __('Japan (JPY)')],
        ];
    }
}
