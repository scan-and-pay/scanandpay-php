<?php

declare(strict_types=1);

namespace ScanAndPay\Exceptions;

/**
 * Thrown when the SDK rejects input *before* hitting the network — bad
 * amount, missing payId, unknown source, etc. Mirrors `ValidationError`
 * in the Node SDK: extends the SDK base so callers can `catch
 * (ScanAndPayException $e)` to handle every SDK-thrown error in one place.
 */
class ValidationException extends ScanAndPayException
{
}
