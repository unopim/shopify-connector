<?php

namespace Webkul\Shopify\Exceptions;

use Exception;
use Throwable;

/**
 * Class InvalidCredential
 *
 * Exception thrown when an invalid credential is provided.
 * This may occur if the credential is disabled or incorrect.
 */
class InvalidCredential extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            $message !== '' ? $message : trans('shopify::app.shopify.credential.errors.invalid-credential'),
            $code,
            $previous
        );
    }
}
