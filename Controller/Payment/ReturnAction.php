<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Zero\AmazonPay\Model\OrderUpdater;
use Zero\AmazonPay\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Where Amazon sends the customer back (checkoutResultReturnUrl:
 * `?order=<increment id>&amazonCheckoutSessionId=…`). Only the checkout
 * session's own last order is looked at; the session is completed and the
 * customer lands on the success page, or back in the cart.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $order = $this->returnResult->lastOrder(Payment::CODE, (string) $this->request->getParam('order'));
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        try {
            $state = $this->orderUpdater->update($order, (string) $this->request->getParam('amazonCheckoutSessionId'));
            if ($state === ReturnResult::NOT_STARTED) {
                $this->orderUpdater->abandon($order);
            }
        } catch (LocalizedException) {
            $state = ReturnResult::UNCONFIRMED;
        }

        return $this->returnResult->redirect($state, 'Amazon Pay');
    }
}
