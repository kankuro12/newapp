<?php

namespace App\Support;

use App\Models\Tenant;

final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function id(): int
    {
        return $this->tenant?->id ?? throw new \LogicException('Tenant context required.');
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
