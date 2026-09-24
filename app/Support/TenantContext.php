<?php

namespace App\Support;

class TenantContext
{
    private ?int $tenantId = null;

    private bool $impersonating = false;

    public function currentId(): ?int
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function setTenantId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function impersonating(): bool
    {
        return $this->impersonating;
    }

    public function setImpersonating(bool $impersonating): void
    {
        $this->impersonating = $impersonating;
    }

    public function reset(): void
    {
        $this->tenantId = null;
        $this->impersonating = false;
    }
}
