<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_AmazonPay: Amazon Pay for Zero Checkout. The order is placed, the
 * customer pays on Amazon's page and comes back (Amazon Pay Checkout v2,
 * the API-created "pay now" session, no Amazon script on the shop's pages).
 * See README.md.
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_AmazonPay', __DIR__);
