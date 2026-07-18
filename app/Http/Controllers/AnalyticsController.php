<?php

namespace App\Http\Controllers;

use App\Models\CompletedTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['manager', 'project_manager']), 403);

        return view('analytics', $this->analyticsData($request));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->hasAnyRole(['manager', 'project_manager']), 403);
        $data = $this->analyticsData($request);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Active Tasks', $data['totalActiveTasks']]);
            fputcsv($out, ['Completed Tasks', $data['totalCompletedTasks']]);
            fputcsv($out, ['In Progress Tasks', $data['inProgressTasks']]);
            fputcsv($out, ['Overdue Tasks', $data['overdueTasks']]);
            fputcsv($out, ['Completion Rate', $data['completionRate'].'%']);
            fputcsv($out, []);
            fputcsv($out, ['Team Member', 'Total', 'Completed', 'Overdue', 'Completion Rate']);
            foreach ($data['teamPerformance'] as $member) {
                fputcsv($out, [
                    $member['name'],
                    $member['total'],
                    $member['completed'],
                    $member['overdue'],
                    $member['completionRate'].'%',
                ]);
            }
            fclose($out);
        }, 'task-management-analytics.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['manager', 'project_manager']), 403);
        $data = $this->analyticsData($request);

        return response()->view('analytics-export', $data);
    }

    private function analyticsData(Request $request): array
    {
        $dateFrom = $request->input('dateFrom') ?: now()->subDays(7)->format('Y-m-d');
        $dateTo = $request->input('dateTo') ?: now()->format('Y-m-d');
        $assigneeId = $request->input('assignee');

        $activeTasksQuery = $this->visibleTaskQuery()
            ->when($assigneeId, fn ($query) => $query->where('assignee_id', $assigneeId))
            ->when($dateFrom && $dateTo, fn ($query) => $query->whereBetween('created_at', [$dateFrom, $dateTo.' 23:59:59']));
        $completedTasksQuery = $this->visibleCompletedTaskQuery()
            ->when($assigneeId, fn ($query) => $query->where('assignee_id', $assigneeId))
            ->when($dateFrom && $dateTo, fn ($query) => $query->whereBetween('completed_at', [$dateFrom, $dateTo.' 23:59:59']));

        $activeTasks = $activeTasksQuery->with('assignee')->get();
        $completedTasks = $completedTasksQuery->with('assignee')->get();
        $totalActiveTasks = $activeTasks->count();
        $totalCompletedTasks = $completedTasks->count();
        $totalTasksForRate = $totalActiveTasks + $totalCompletedTasks;
        $inProgressTasks = $activeTasks->where('status', 'In Progress')->count();
        $overdueTasks = $activeTasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $completionRate = $totalTasksForRate ? round($totalCompletedTasks / $totalTasksForRate * 100) : 0;
        $avgProgress = $activeTasks->count() ? round($activeTasks->avg('progress')) : 0;
        $priorityCounts = $activeTasks->groupBy('priority')->map->count();
        $statusCounts = $activeTasks->groupBy('status')->map->count();

        $days = collect(range(0, 29))->map(fn ($i) => now()->subDays(29 - $i));
        $productivity = $days->mapWithKeys(fn ($date) => [
            $date->format('M d') => (clone $completedTasksQuery)->whereDate('completed_at', $date)->count(),
        ]);
        $overdueTrend = $days->mapWithKeys(fn ($date) => [
            $date->format('M d') => (clone $activeTasksQuery)->where('due_date', '<', $date)->where('status', '!=', 'Completed')->count(),
        ]);

        $users = $this->visibleUsers()->with(['role', 'tasks'])->get();
        $teamPerformance = $users->map(function ($user) use ($dateFrom, $dateTo, $assigneeId) {
            if ($assigneeId && (int) $user->id !== (int) $assigneeId) {
                return null;
            }

            $active = $this->visibleTaskQuery()
                ->where('assignee_id', $user->id)
                ->when($dateFrom && $dateTo, fn ($query) => $query->whereBetween('created_at', [$dateFrom, $dateTo.' 23:59:59']))
                ->get();
            $completed = $this->visibleCompletedTaskQuery()
                ->where('assignee_id', $user->id)
                ->when($dateFrom && $dateTo, fn ($query) => $query->whereBetween('completed_at', [$dateFrom, $dateTo.' 23:59:59']))
                ->get();
            $total = $active->count() + $completed->count();
            $completedCount = $completed->count();

            return [
                'name' => $user->name,
                'avatar' => strtoupper(substr($user->name, 0, 2)),
                'total' => $total,
                'completed' => $completedCount,
                'overdue' => $active->where('due_date', '<', now())->where('status', '!=', 'Completed')->count(),
                'completionRate' => $total ? round($completedCount / $total * 100) : 0,
            ];
        })->filter()->values();

        return [
            'activeTasks' => $activeTasks,
            'completedTasks' => $completedTasks,
            'totalActiveTasks' => $totalActiveTasks,
            'totalCompletedTasks' => $totalCompletedTasks,
            'inProgressTasks' => $inProgressTasks,
            'overdueTasks' => $overdueTasks,
            'completionRate' => $completionRate,
            'avgProgress' => $avgProgress,
            'priorityCounts' => $priorityCounts,
            'statusCounts' => $statusCounts,
            'productivity' => $productivity,
            'overdueTrend' => $overdueTrend,
            'teamPerformance' => $teamPerformance,
            'achievements' => [
                'Team completed '.$totalCompletedTasks.' tasks in selected period',
                'Maintaining '.$avgProgress.'% average progress rate',
                'Overall completion rate: '.$completionRate.'%',
            ],
            'improvements' => [
                'Address '.$overdueTasks.' overdue task(s)',
                'Balance high-priority task distribution',
                'Monitor team productivity trends',
            ],
            'lastUpdated' => (clone $this->visibleTaskQuery())->latest('updated_at')->value('updated_at'),
            'users' => $users,
        ];
    }

    private function visibleTaskQuery()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return Task::query();
        }

        return Task::query()->whereHas('project', fn ($query) => $query->where('project_manager_id', $user->id));
    }

    private function visibleCompletedTaskQuery()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return CompletedTask::query();
        }

        return CompletedTask::query()->whereHas('project', fn ($query) => $query->where('project_manager_id', $user->id));
    }

    private function visibleUsers()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return User::query()
                ->where('active', true)
                ->whereHas('role', fn ($query) => $query->whereIn('name', ['project_manager', 'team_member']));
        }

        return User::query()
            ->where('active', true)
            ->whereHas('projects', fn ($query) => $query->where('project_manager_id', $user->id));
    }
}
