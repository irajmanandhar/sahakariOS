<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For models with a `cooperative_id` column.
 *
 * Reads: every query is limited to the current cooperative. With no tenant
 * context the query throws (fail closed) unless explicitly bypassed via
 * TenantContext::withoutTenantScope().
 * Writes: a new row gets the current cooperative; a row for a different
 * cooperative is refused. Without context the caller must set cooperative_id
 * itself (enforced by NOT NULL + foreign keys).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenant = app(TenantContext::class);

            if ($tenant->isBypassed()) {
                return;
            }

            $query->where($query->qualifyColumn('cooperative_id'), $tenant->cooperativeId());
        });

        static::creating(function (Model $model) {
            $tenant = app(TenantContext::class);

            if (! $tenant->has() || $tenant->isBypassed()) {
                return;
            }

            $model->cooperative_id ??= $tenant->cooperativeId();

            if ((int) $model->cooperative_id !== $tenant->cooperativeId()) {
                throw new LogicException('Refusing to create a row for another cooperative.');
            }
        });
    }
}
