<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when WebhookVerifier rejects an inbound payload.
 * Always respond 401 to the sender — do not echo the reason.
 */
class WebhookSignatureException extends ScanAndPayException
{
}
