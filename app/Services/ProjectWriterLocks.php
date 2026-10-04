<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class ProjectWriterLocks
{
    // Related writers acquire sorted account rows before projects, then tasks.
    public function accounts(array $ids): Collection
    {
        return User::query()->with('role')->whereIn('id', array_unique($ids))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function actor(User $actor, array $otherIds = []): User
    {
        $fresh = $this->accounts([$actor->id, ...$otherIds])->get($actor->id);
        if (! $fresh || ! $fresh->isActive()) {
            throw new AuthorizationException('Your account is no longer authorized.');
        }

        return $fresh;
    }
}
