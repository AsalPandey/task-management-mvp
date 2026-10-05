<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class AccountAdministrationWriter
{
    public function write(User $actor, ?User $target, Closure $mutation): mixed
    {
        return $this->execute($actor, $target, $mutation, false);
    }

    public function deleteSelf(User $actor, Closure $mutation): mixed
    {
        return $this->execute($actor, $actor, $mutation, true);
    }

    private function execute(User $actor, ?User $target, Closure $mutation, bool $selfService): mixed
    {
        return DB::transaction(function () use ($actor, $target, $mutation, $selfService) {
            // Manager sentinel -> all current Managers, actor and target in ID order
            // -> dependent projects -> tasks. Other writers use accounts -> project -> task.
            // Prelocking Managers prevents last-Manager checks reversing account order.
            $managerRole = Role::query()->where('name', 'manager')->lockForUpdate()->first();
            abort_unless($selfService || $managerRole, 403, 'Your account is no longer authorized.');
            $accounts = User::query()->with('role')
                ->where(fn ($query) => $query->whereIn('id', array_filter([$actor->id, $target?->id]))
                    ->orWhereHas('role', fn ($roles) => $roles->where('name', 'manager')))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $fresh = $accounts->get($actor->id);
            abort_unless($fresh && $fresh->isActive() && ($selfService || $fresh->hasRole('manager'))
                && $fresh->security_stamp === $actor->security_stamp, 403, 'Your account is no longer authorized.');
            $lockedTarget = $target ? $accounts->get($target->id) : null;
            abort_if($target && (! $lockedTarget || (! $selfService && $fresh->is($lockedTarget))), 403,
                'Use the self-service profile and password routes for your own account.');

            return $mutation($fresh, $lockedTarget);
        }, 3);
    }
}
