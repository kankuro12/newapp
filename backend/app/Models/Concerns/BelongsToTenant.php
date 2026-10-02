<?php

namespace App\Models\Concerns;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where($query->getModel()->qualifyColumn('tenant_id'), app(CurrentTenant::class)->id()));
        static::creating(function ($model) {
            $model->tenant_id = app(CurrentTenant::class)->id();
        });
        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new \LogicException('Tenant ownership cannot change.');
            }
        });
    }
}
