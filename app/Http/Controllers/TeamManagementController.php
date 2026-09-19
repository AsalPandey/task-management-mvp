<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountLifecycleException;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountStatusChangedNotification;
use App\Services\AccountLifecycleService;
use App\Services\TaskAnalyticsService;
use App\Support\UserPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamManagementController extends Controller
{
    public function index(Request $request)
    {
        $authUser = auth()->user();
        abort_unless($authUser->hasAnyRole(['manager', 'project_manager']), 403);

        $users = $this->manageableUsers()
            ->with(['role', 'projects:id,name'])
            ->when($request->input('search'), function ($query, string $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $roles = $authUser->hasRole('manager')
            ? Role::query()->orderBy('id')->get()
            : collect();

        return view('team-management', compact('users', 'roles'));
    }

    public function store(Request $request)
    {
        $authUser = auth()->user();
        abort_unless($authUser->hasRole('manager'), 403);
        $this->assertOnlyFields($request, ['name', 'email', 'password', 'role_id']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['active'] = true;
        $user = User::query()->create($data);

        return response()->json(UserPayload::account($user->load('role')));
    }

    public function update(Request $request, User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);
        $this->assertOnlyFields($request, ['name', 'email', 'password', 'role_id']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'role_id' => ['sometimes', 'required', 'exists:roles,id'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data, $authUser) {
            Role::query()->where('name', 'manager')->lockForUpdate()->first();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (isset($data['role_id']) && (int) $data['role_id'] !== (int) $lockedUser->role_id) {
                app(AccountLifecycleService::class)->assertCanChangeRole($lockedUser, (int) $data['role_id'], $authUser);
            }
            $lockedUser->update($data);
        }, 3);

        return response()->json(UserPayload::account($user->refresh()->load('role')));
    }

    public function destroy(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);

        try {
            DB::transaction(function () use ($user, $authUser) {
                Role::query()->where('name', 'manager')->lockForUpdate()->first();
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                app(AccountLifecycleService::class)->assertCanDelete($lockedUser, $authUser);
                $lockedUser->delete();
            }, 3);
        } catch (AccountLifecycleException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json(['success' => true]);
    }

    public function activate(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);
        $user->forceFill(['active' => true])->save();
        $user->notify(new AccountStatusChangedNotification(true, $authUser));

        return response()->json(['success' => true]);
    }

    public function deactivate(User $user)
    {
        $authUser = auth()->user();
        $this->assertCanManageGlobalAccount($authUser, $user);

        try {
            DB::transaction(function () use ($user, $authUser) {
                Role::query()->where('name', 'manager')->lockForUpdate()->first();
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                app(AccountLifecycleService::class)->assertCanDeactivate($lockedUser, $authUser);
                $lockedUser->forceFill(['active' => false])->save();
            }, 3);
        } catch (AccountLifecycleException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        $user->refresh()->notify(new AccountStatusChangedNotification(false, $authUser));

        return response()->json(['success' => true]);
    }

    public function analytics(User $user, TaskAnalyticsService $analytics)
    {
        $authUser = auth()->user();
        if (! $authUser->hasRole('manager') && ! $authUser->hasRole('project_manager') && ! $authUser->is($user)) {
            abort(403, 'Unauthorized.');
        }

        if ($authUser->hasRole('project_manager')) {
            $allowed = $authUser->is($user)
                || $user->projects()->where('project_manager_id', $authUser->id)->exists();
            abort_unless($allowed || $authUser->is($user), 403);
        }

        $report = $analytics->report($authUser, assigneeId: $user->id);
        $allTasks = $report['activeTasks'];
        $allCompletedTasks = $report['completedTasks'];
        $totalTasks = $report['totalActiveTasks'];
        $totalCompletedTasks = $report['totalCompletedTasks'];
        $overallCompletionRate = $report['completionRate'];
        $currentActiveTasks = $allTasks;
        $currentInProgressTasks = $report['inProgressTasks'];
        $currentOverdueTasks = $report['overdueTasks'];
        $currentAvgProgress = $report['avgProgress'];
        $priorityCounts = [
            'High' => $allTasks->where('priority', 'High')->count(),
            'Medium' => $allTasks->where('priority', 'Medium')->count(),
            'Low' => $allTasks->where('priority', 'Low')->count(),
        ];
        $statusCounts = [
            'Not Started' => $allTasks->where('status', 'Not Started')->count(),
            'In Progress' => $allTasks->where('status', 'In Progress')->count(),
            'Completed' => $allCompletedTasks->count(),
        ];
        $dailyCompletionTrend = $report['completionTrend'];
        $dailyTaskCreationTrend = $report['creationTrend'];
        $monthlyPerformance = collect(range(0, 5))->mapWithKeys(function ($i) use ($analytics, $authUser, $user) {
            $start = now()->subMonths(5 - $i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $month = $analytics->report($authUser, $start->toDateString(), $end->toDateString(), $user->id);

            return [$start->format('M Y') => [
                'created' => $month['tasksCreated'],
                'completed' => $month['completionEvents'],
                'rate' => $month['completionRate'],
            ]];
        });
        $recentActivity = $report['cohortTasks']->sortByDesc('updated_at')->take(10)
            ->sortByDesc(fn ($task) => $task->completed_at ?? $task->created_at)
            ->take(10);
        $achievements = [
            'Completed '.$totalCompletedTasks.' tasks overall',
            'Current completion rate: '.$overallCompletionRate.'%',
            'Average progress on active tasks: '.$currentAvgProgress.'%',
        ];
        $improvements = [
            'Address '.$currentOverdueTasks.' overdue task(s)',
            'Focus on high-priority tasks',
            'Maintain consistent daily progress',
        ];
        $teamComparison = null;

        return view('team-member-analytics', compact(
            'user',
            'allTasks',
            'allCompletedTasks',
            'totalTasks',
            'totalCompletedTasks',
            'overallCompletionRate',
            'currentActiveTasks',
            'currentInProgressTasks',
            'currentOverdueTasks',
            'currentAvgProgress',
            'priorityCounts',
            'statusCounts',
            'dailyCompletionTrend',
            'dailyTaskCreationTrend',
            'monthlyPerformance',
            'recentActivity',
            'achievements',
            'improvements',
            'teamComparison',
        ));
    }

    private function manageableUsers()
    {
        $authUser = auth()->user();

        if ($authUser->hasRole('manager')) {
            return User::query()->whereKeyNot($authUser->id);
        }

        return User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'team_member'))
            ->whereHas('projects', fn ($query) => $query->where('project_manager_id', $authUser->id));
    }

    private function assertCanManageGlobalAccount(User $authUser, User $user): void
    {
        abort_unless($authUser->hasRole('manager'), 403);
        abort_if($authUser->is($user), 403, 'Use the self-service profile and password routes for your own account.');
    }

    private function assertOnlyFields(Request $request, array $allowed): void
    {
        $unexpected = collect($request->keys())
            ->reject(fn (string $field) => in_array($field, ['_token', '_method'], true))
            ->diff($allowed)
            ->values()
            ->all();

        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            'fields' => 'Unsupported fields: '.implode(', ', $unexpected).'. Project membership changes must use the project membership endpoints.',
        ]);
    }
}
