<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Zero\AmazonPay\Model\Payment;

/**
 * What completing the checkout session does: take the money, or reserve it
 * until the order is invoiced.
 */
class PaymentIntent implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Payment::INTENT_CAPTURE, 'label' => __('Authorize and Capture')],
            ['value' => Payment::INTENT_AUTHORIZE, 'label' => __('Authorize')],
        ];
    }
}
