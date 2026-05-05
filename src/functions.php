<?php

declare(strict_types=1);

use ScanAndPay\PaymentSession;

if (!function_exists('scanandpay_checkout')) {
    function scanandpay_checkout(
        PaymentSession $session,
        string $pollUrl,
        ?string $successUrl = null,
        string $theme = 'light'
    ): string {
        return \ScanAndPay\Render\checkout($session, $pollUrl, $successUrl, $theme);
    }
}
