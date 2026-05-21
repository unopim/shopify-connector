<?php

namespace Webkul\Shopify\Exceptions;

/**
 * Thrown when Shopify rejects a bulk mutation because another bulk operation
 * for the same app+shop is still running.
 *
 * Shopify permits only one bulk mutation per app+shop at a time. Follow-up
 * phase jobs catch this and release themselves back to the queue, retrying
 * until the bulk slot frees — instead of failing the phase outright.
 */
class BulkMutationInProgressException extends \RuntimeException {}
