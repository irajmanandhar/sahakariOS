<?php

namespace App\Tenancy;

use LogicException;

/**
 * The cooperative (and home branch) the current request acts for.
 *
 * Set only by the `tenant` middleware from server-side session state that it
 * re-verifies against the user's active membership. Never set it from
 * request input. Registered as a scoped singleton (reset per request/job).
 */
final class TenantContext
{
    private ?int $cooperativeId = null;

    private ?int $branchId = null;

    private int $bypassDepth = 0;

    public function set(int $cooperativeId, int $branchId): void
    {
        if ($this->cooperativeId !== null) {
            throw new LogicException('Tenant context is already set for this request.');
        }

        $this->cooperativeId = $cooperativeId;
        $this->branchId = $branchId;
    }

    /**
     * Run code for one cooperative outside an HTTP request (seeders, queued
     * jobs, commands). The caller is responsible for having authorized this.
     */
    public function runAs(int $cooperativeId, int $branchId, callable $callback): mixed
    {
        $this->set($cooperativeId, $branchId);

        try {
            return $callback();
        } finally {
            $this->clear();
        }
    }

    public function clear(): void
    {
        $this->cooperativeId = null;
        $this->branchId = null;
    }

    public function has(): bool
    {
        return $this->cooperativeId !== null;
    }

    public function cooperativeId(): int
    {
        return $this->cooperativeId ?? throw new MissingTenantContext;
    }

    public function branchId(): int
    {
        return $this->branchId ?? throw new MissingTenantContext;
    }

    /**
     * Run code that must see all cooperatives' rows (platform administration,
     * seeders). Every use is a deliberate, reviewable exception to isolation.
     */
    public function withoutTenantScope(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
