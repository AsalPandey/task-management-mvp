<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TaskAnalyticsService;
use App\Services\TaskReadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly TaskReadService $taskReads,
        private readonly TaskAnalyticsService $analytics,
    ) {}

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
            $writeRow = fn (array $row) => fputcsv(
                $out,
                array_map($this->safeCsvCell(...), $row),
            );

            $writeRow(['Metric', 'Value']);
            $writeRow(['Active Tasks', $data['totalActiveTasks']]);
            $writeRow(['Completed Tasks', $data['totalCompletedTasks']]);
            $writeRow(['Active Execution', $data['executionTasks']->count()]);
            $writeRow(['Review Queue', $data['reviewQueueTasks']->count()]);
            $writeRow(['Cancelled Tasks', $data['cancelledTasks']]);
            $writeRow(['In Progress Tasks', $data['inProgressTasks']]);
            $writeRow(['Overdue Tasks', $data['overdueTasks']]);
            $writeRow(['Completion Rate', $data['completionRate'].'%']);
            $writeRow(['Tasks Created', $data['tasksCreated']]);
            $writeRow(['Completion Events', $data['completionEvents']]);
            $writeRow(['Cancellation Events', $data['cancellationEvents']]);
            $writeRow(['Reopen Events', $data['reopenEvents']]);
            $writeRow([]);
            $writeRow(['Team Member', 'Total', 'Completed', 'Overdue', 'Completion Rate']);
            foreach ($data['teamPerformance'] as $member) {
                $writeRow([
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

    private function safeCsvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/u', $value) === 1
            ? "'".$value
            : $value;
    }

    private function analyticsData(Request $request): array
    {
        $validated = $request->validate([
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'assignee' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
        ]);
        $dateFrom = $validated['dateFrom'] ?? now(config('app.timezone'))->subDays(7)->toDateString();
        $dateTo = $validated['dateTo'] ?? now(config('app.timezone'))->toDateString();
        $report = $this->analytics->report(
            $request->user(),
            $dateFrom,
            $dateTo,
            isset($validated['assignee']) ? (int) $validated['assignee'] : null,
            isset($validated['project']) ? (int) $validated['project'] : null,
        );
        $days = collect(range(0, 29))->map(fn ($i) => now(config('app.timezone'))->subDays(29 - $i));
        $overdueTrend = $days->mapWithKeys(fn ($date) => [
            $date->format('M d') => $report['activeTasks']
                ->filter(fn ($task) => $task->activeDeadline()?->lt($date) ?? false)->count(),
        ]);
        $users = $this->visibleUsers()->with('role')->get();
        $selectedAssignee = isset($validated['assignee']) ? (int) $validated['assignee'] : null;
        $performanceByUser = $report['teamPerformance']->keyBy('id');
        $teamPerformance = $users
            ->when($selectedAssignee, fn ($members) => $members->where('id', $selectedAssignee))
            ->map(function (User $user) use ($performanceByUser): array {
                return $performanceByUser->get($user->id, [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => strtoupper(substr($user->name, 0, 2)),
                    'total' => 0,
                    'completed' => 0,
                    'overdue' => 0,
                    'completionRate' => 0.0,
                ]);
            })->values();

        return array_merge($report, [
            'productivity' => $report['completionTrend'],
            'overdueTrend' => $overdueTrend,
            'teamPerformance' => $teamPerformance,
            'achievements' => [
                'Created '.$report['tasksCreated'].' tasks in selected period',
                'Recorded '.$report['completionEvents'].' completion event(s)',
                'Cohort completion rate: '.$report['completionRate'].'%',
            ],
            'improvements' => [
                'Address '.$report['overdueTasks'].' overdue task(s)',
                'Balance high-priority task distribution',
                'Monitor team productivity trends',
            ],
            'lastUpdated' => $this->taskReads->activeVisibleTo($request->user())->latest('updated_at')->value('updated_at'),
            'users' => $users,
        ]);
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
