<?php

declare(strict_types=1);

namespace ScanAndPay\Render;

use ScanAndPay\PaymentSession;

/**
 * Renders a standard Scan & Pay checkout component.
 *
 * This helper generates a responsive container with:
 * 1. The PayID QR code.
 * 2. A "Waiting for payment" spinner.
 * 3. Automatic polling logic that redirects or fires a callback on success.
 */
function checkout(
    PaymentSession $session,
    string $pollUrl,
    ?string $successUrl = null,
    string $theme = 'light'
): string {
    $qrUrl = $session->qrUrl;
    $payUrl = $session->payUrl;
    $sessionId = $session->sessionId;
    // PaymentSession::$amount is integer cents — divide for display only.
    $amount = number_format($session->amount / 100, 2);
    $currency = $session->currency;
    $merchantName = $session->merchantName ?? 'Scan & Pay';

    $bgColor = $theme === 'dark' ? '#0A0118' : '#ffffff';
    $textColor = $theme === 'dark' ? '#ffffff' : '#0A0118';
    $accentColor = '#008080'; // Scan & Pay Teal

    ob_start();
    ?>
    <div id="scanpay-container-<?= htmlspecialchars($sessionId) ?>" 
         style="font-family: system-ui, -apple-system, sans-serif; max-width: 400px; margin: 20px auto; padding: 30px; border-radius: 16px; background: <?= $bgColor ?>; color: <?= $textColor ?>; text-align: center; border: 1px solid rgba(0,0,0,0.1); box-shadow: 0 4px 20px rgba(0,0,0,0.05);">
        
        <div style="margin-bottom: 20px;">
            <div style="font-size: 14px; opacity: 0.7; margin-bottom: 4px;">Pay to</div>
            <div style="font-weight: 600; font-size: 18px;"><?= htmlspecialchars($merchantName) ?></div>
        </div>

        <div style="background: #fff; padding: 15px; border-radius: 12px; display: inline-block; margin-bottom: 20px; border: 1px solid #eee;">
            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="Scan to pay" style="display: block; width: 220px; height: 220px;">
        </div>

        <div style="margin-bottom: 25px;">
            <div style="font-size: 24px; font-weight: 700; color: <?= $accentColor ?>;">
                <?= htmlspecialchars($currency) ?> $<?= $amount ?>
            </div>
            <div style="font-size: 12px; opacity: 0.6; margin-top: 8px;">
                Scan with any Australian bank app to pay via PayTo / PayID
            </div>
        </div>

        <div id="scanpay-status-<?= htmlspecialchars($sessionId) ?>" style="display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 14px; font-weight: 500;">
            <div class="scanpay-spinner" style="width: 18px; height: 18px; border: 2px solid <?= $accentColor ?>; border-top-color: transparent; border-radius: 50%; animation: scanpay-spin 0.8s linear infinite;"></div>
            Waiting for payment...
        </div>

        <style>
            @keyframes scanpay-spin { to { transform: rotate(360deg); } }
        </style>

        <script>
            (function() {
                const sessionId = <?= json_encode($sessionId) ?>;
                const pollUrl = <?= json_encode($pollUrl) ?>;
                const successUrl = <?= json_encode($successUrl) ?>;
                const container = document.getElementById('scanpay-container-' + sessionId);
                const statusEl = document.getElementById('scanpay-status-' + sessionId);

                let pollInterval = setInterval(async () => {
                    try {
                        const response = await fetch(pollUrl + '?sessionId=' + sessionId);
                        const data = await response.json();

                        if (data.status === 'PAID') {
                            clearInterval(pollInterval);
                            statusEl.innerHTML = '<span style="color: #2bc48a;">✓ Payment Successful</span>';
                            
                            if (successUrl) {
                                setTimeout(() => window.location.href = successUrl, 1500);
                            }
                        } else if (data.status === 'EXPIRED' || data.status === 'FAILED') {
                            clearInterval(pollInterval);
                            statusEl.innerHTML = '<span style="color: #ff4d4d;">✕ Payment ' + data.status + '</span>';
                        }
                    } catch (e) {
                        console.error('Scan & Pay Polling Error:', e);
                    }
                }, 3000);
            })();
        </script>
    </div>
    <?php
    return ob_get_clean();
}

