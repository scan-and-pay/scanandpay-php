<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown after the HTTP transport exhausts its retry budget on transport
 * failures (DNS, connect, TLS, timeout, TCP reset). Distinguishes a
 * connectivity issue from an API-level rejection.
 */
class NetworkException extends ScanAndPayException
{
}
