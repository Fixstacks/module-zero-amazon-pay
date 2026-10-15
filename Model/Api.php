<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Model;

use Amazon\Pay\API\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Amazon Pay's Checkout v2 API through Amazon's own SDK
 * (amzn/amazon-pay-api-sdk-php: it signs every request with the private
 * key). Answers come back as arrays; a refusal is a LocalizedException with
 * Amazon's reason code.
 */
class Api
{
    private const PATH = 'payment/zero_amazonpay/';

    /** @var array<int, Client> by store */
    private array $clients = [];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isConfigured(?int $storeId): bool
    {
        foreach (['merchant_id', 'store_id', 'public_key_id'] as $field) {
            if ($this->value($field, $storeId) === '') {
                return false;
            }
        }

        return $this->privateKey($storeId) !== '';
    }

    public function value(string $field, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH . $field, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * @throws LocalizedException
     */
    public function createCheckoutSession(array $payload, string $idempotencyKey, ?int $storeId): array
    {
        $answer = $this->call($storeId, 'createCheckoutSession', [$payload, ['x-amz-pay-idempotency-key' => $idempotencyKey]]);
        if (empty($answer['checkoutSessionId']) || empty($answer['webCheckoutDetails']['amazonPayRedirectUrl'])) {
            throw new LocalizedException(__('Amazon Pay didn\'t start the checkout. Please try again or choose another payment method.'));
        }

        return $answer;
    }

    /**
     * @throws LocalizedException
     */
    public function getCheckoutSession(string $id, ?int $storeId): array
    {
        return $this->call($storeId, 'getCheckoutSession', [$id]);
    }

    /**
     * @throws LocalizedException
     */
    public function completeCheckoutSession(string $id, array $chargeAmount, ?int $storeId): array
    {
        return $this->call($storeId, 'completeCheckoutSession', [$id, ['chargeAmount' => $chargeAmount]]);
    }

    /**
     * @throws LocalizedException
     */
    public function getCharge(string $chargeId, ?int $storeId): array
    {
        return $this->call($storeId, 'getCharge', [$chargeId]);
    }

    /**
     * @throws LocalizedException
     */
    public function captureCharge(string $chargeId, array $amount, string $idempotencyKey, ?int $storeId): array
    {
        return $this->call($storeId, 'captureCharge', [$chargeId, ['captureAmount' => $amount], ['x-amz-pay-idempotency-key' => $idempotencyKey]]);
    }

    /**
     * @throws LocalizedException
     */
    public function cancelCharge(string $chargeId, string $reason, ?int $storeId): array
    {
        return $this->call($storeId, 'cancelCharge', [$chargeId, ['cancellationReason' => mb_substr($reason, 0, 255)]]);
    }

    /**
     * @throws LocalizedException
     */
    public function createRefund(string $chargeId, array $amount, string $idempotencyKey, ?int $storeId): array
    {
        return $this->call($storeId, 'createRefund', [
            ['chargeId' => $chargeId, 'refundAmount' => $amount],
            ['x-amz-pay-idempotency-key' => $idempotencyKey],
        ]);
    }

    /**
     * @param array<int, mixed> $arguments
     * @throws LocalizedException
     */
    private function call(?int $storeId, string $method, array $arguments): array
    {
        $client = $this->client($storeId);
        try {
            $result = $client->$method(...$arguments);
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Amazon Pay: ' . $method . ': ' . $e->getMessage());
            throw new LocalizedException(__('Amazon Pay is not answering. Please try again in a minute.'), $e);
        }
        $status = (int) ($result['status'] ?? 0);
        $answer = json_decode((string) ($result['response'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($answer)) {
            $reason = is_array($answer) ? (string) ($answer['reasonCode'] ?? $answer['message'] ?? '') : '';
            $this->logger->warning(sprintf('Zero Amazon Pay: %s: HTTP %d %s', $method, $status, $reason));
            throw new LocalizedException(__('Amazon Pay refused the request: %1', $reason !== '' ? $reason : 'HTTP ' . $status));
        }

        return $answer;
    }

    /**
     * @throws LocalizedException
     */
    private function client(?int $storeId): Client
    {
        $key = (int) $storeId;
        if (!isset($this->clients[$key])) {
            if (!$this->isConfigured($storeId)) {
                throw new LocalizedException(__('Amazon Pay is not set up: the merchant ID, store ID or keys are missing.'));
            }
            $this->clients[$key] = new Client([
                'public_key_id' => $this->value('public_key_id', $storeId),
                'private_key' => $this->privateKey($storeId),
                'region' => $this->value('region', $storeId) ?: 'eu',
                'sandbox' => $this->scopeConfig->isSetFlag(self::PATH . 'sandbox', ScopeInterface::SCOPE_STORE, $storeId),
            ]);
        }

        return $this->clients[$key];
    }

    private function privateKey(?int $storeId): string
    {
        $value = $this->value('private_key', $storeId);

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
