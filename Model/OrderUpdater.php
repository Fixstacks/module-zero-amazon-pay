<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Settles an order when the customer comes back from Amazon: the checkout
 * session is completed for the order's amount, which creates the charge —
 * captured (Authorize and Capture) or reserved (Authorize). A session the
 * buyer didn't confirm (came back by the browser) is nobody's payment: the
 * order is cancelled. A session Amazon declined cancels the order too.
 */
class OrderUpdater
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment
    ) {
    }

    /**
     * @param string $sessionId The amazonCheckoutSessionId Amazon put on the return URL
     * @return string A ReturnResult state
     * @throws LocalizedException When Amazon doesn't answer
     */
    public function update(Order $order, string $sessionId): string
    {
        $payment = $order->getPayment();
        $own = (string) $payment?->getAdditionalInformation(Payment::SESSION_ID);
        if ($payment?->getMethod() !== Payment::CODE || $own === '' || $sessionId !== $own) {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $storeId = (int) $order->getStoreId();
        $session = $this->api->getCheckoutSession($own, $storeId);
        $state = (string) ($session['statusDetails']['state'] ?? '');
        if ($state === 'Open') {
            // Confirmed on Amazon's page, not yet charged: completing it charges.
            if (!empty($session['constraints'])) {
                return ReturnResult::NOT_STARTED;
            }
            try {
                $session = $this->api->completeCheckoutSession($own, Payment::amount($order, (float) $order->getGrandTotal()), $storeId);
            } catch (LocalizedException $e) {
                return $this->failed($order, $e->getMessage());
            }
            $state = (string) ($session['statusDetails']['state'] ?? '');
        }

        return match ($state) {
            'Completed' => $this->completed($order, $session),
            'Canceled' => $this->failed($order, (string) ($session['statusDetails']['reasonCode'] ?? 'Canceled')),
            default => ReturnResult::PENDING,
        };
    }

    /**
     * Nobody paid: the order is cancelled (the session expires on its own).
     */
    public function abandon(Order $order): void
    {
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    private function completed(Order $order, array $session): string
    {
        $chargeId = (string) ($session['chargeId'] ?? '');
        if ($chargeId === '') {
            return ReturnResult::PENDING;
        }
        $payment = $order->getPayment();
        $payment->setAdditionalInformation(Payment::CHARGE_ID, $chargeId);
        $payment->setAdditionalInformation(Payment::CHARGE_PERMISSION_ID, (string) ($session['chargePermissionId'] ?? ''));
        $charge = $this->api->getCharge($chargeId, (int) $order->getStoreId());
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if (($charge['chargeAmount'] ?? null) != $expected) {
            $this->orderPayment->flag(
                $order,
                (string) __('Amazon Pay reports a charge of a different amount; check it in Seller Central.'),
                sprintf(
                    'Amazon Pay: %s %s, expected %s %s',
                    $charge['chargeAmount']['amount'] ?? '?',
                    $charge['chargeAmount']['currencyCode'] ?? '?',
                    $expected['amount'],
                    $expected['currencyCode']
                )
            );

            return ReturnResult::PENDING;
        }
        if (($charge['statusDetails']['state'] ?? '') === 'Captured') {
            $this->orderPayment->capture($order, $chargeId);
        } else {
            $this->orderPayment->authorize($order, $chargeId);
        }

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Amazon Pay: %1', $reason));

        return ReturnResult::FAILED;
    }
}
