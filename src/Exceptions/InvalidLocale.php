<?php

namespace Webkul\Shopify\Exceptions;

use Exception;
use Throwable;

class InvalidLocale extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            $message !== '' ? $message : trans('shopify::app.shopify.export.errors.invalid-locale'),
            $code,
            $previous
        );
    }
}
