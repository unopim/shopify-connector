<?php

namespace Webkul\Shopify\Contracts;

/**
 * An exporter whose batch summaries carry an `updated` count beside the
 * `created` one, which the finished job summary should keep.
 */
interface ReportsUpdatedCount {}
