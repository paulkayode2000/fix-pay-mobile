<?php

namespace App\Services\Fixpay;

use App\Models\AppUser;

/**
 * Resolves Fixpay consumers (AppUser) by phone within the calling tenant's scope.
 *
 * Kept deliberately thin so the portal controller stays a pure HTTP adapter and
 * the tenant-scoping rule lives in exactly one place.
 */
class ConsumerOpsService
{
    public function resolve(?string $tenantId, string $phone): ?AppUser
    {
        return AppUser::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('phone', $phone)
            ->first();
    }

    public function resolveOrFail(?string $tenantId, string $phone): AppUser
    {
        $user = $this->resolve($tenantId, $phone);

        abort_if($user === null, 404, 'Consumer not found.');

        return $user;
    }
}
