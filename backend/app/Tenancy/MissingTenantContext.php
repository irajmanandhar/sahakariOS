<?php

namespace App\Tenancy;

use LogicException;

/**
 * A tenant-owned model was queried with no cooperative selected. This is a
 * programming error (a route without the `tenant` middleware, or a job that
 * did not set its cooperative), so it fails loudly instead of returning
 * every cooperative's rows.
 */
class MissingTenantContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('No tenant context: tenant-owned data was queried without a selected cooperative.');
    }
}
