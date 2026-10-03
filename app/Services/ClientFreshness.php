<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Opaque, authorization-scoped read snapshots; no business contents leave this service. */
final class ClientFreshness
{
    public function version(User $user): string
    {
        $hash = hash_init('sha256', HASH_HMAC, (string) config('app.key'));
        $add = static fn ($value) => hash_update($hash, json_encode($value, JSON_THROW_ON_ERROR));
        $add([$user->id, $user->name, $user->email, $user->role_id, $user->notification_preferences]);

        // Every supported task mutation advances lock_version; count/id cover creation/removal/scope changes.
        $add(app(TaskReadService::class)->visibleTo($user)->toBase()
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(id), 0) AS identities, COALESCE(SUM(lock_version), 0) AS revisions, MAX(updated_at) AS latest')
            ->first());

        $projects = Project::query();
        if ($user->hasRole('project_manager')) {
            $projects->where('project_manager_id', $user->id);
        } elseif (! $user->hasRole('manager')) {
            $projects->whereHas('members', fn (Builder $members) => $members->whereKey($user->id));
        }
        foreach ((clone $projects)->orderBy('id')->toBase()->get([
            'id', 'name', 'description', 'project_manager_id', 'status', 'color', 'start_date', 'end_date',
        ]) as $project) {
            $add($project);
        }
        foreach (DB::table('project_user')->whereIn('project_id', (clone $projects)->select('id'))
            ->orderBy('project_id')->orderBy('user_id')->get(['project_id', 'user_id']) as $membership) {
            $add($membership);
        }
        foreach (User::query()->whereHas('projects', fn (Builder $memberProjects) => $memberProjects->whereIn('projects.id', (clone $projects)->select('id')))
            ->orderBy('id')->toBase()->get(['id', 'name', 'role_id', 'active']) as $projectMember) {
            $add($projectMember);
        }

        // Match the authorized Team roster. Members see only their own profile.
        $roster = User::query()->whereKeyNot($user->id);
        if ($user->hasRole('project_manager')) {
            $roster->whereHas('role', fn (Builder $roles) => $roles->where('name', 'team_member'))
                ->whereHas('projects', fn (Builder $owned) => $owned->where('project_manager_id', $user->id));
        } elseif (! $user->hasRole('manager')) {
            $roster->whereRaw('1 = 0');
        }
        foreach ($roster->orderBy('id')->toBase()->get(['id', 'name', 'email', 'role_id', 'active']) as $member) {
            $add($member);
        }
        $add($user->notifications()->reorder()->toBase()->selectRaw('COUNT(*) AS total, MAX(created_at) AS latest, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread')->first());

        return hash_final($hash);
    }
}
