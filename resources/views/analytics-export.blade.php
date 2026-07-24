<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Analytics Export</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111827; margin: 24px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
    </style>
</head>
<body>
    <h1>Task Management Analytics</h1>
    <p>Generated {{ now()->format('Y-m-d H:i') }}</p>
    <table>
        <tr><th>Metric</th><th>Value</th></tr>
        <tr><td>Active Tasks</td><td>{{ $totalActiveTasks }}</td></tr>
        <tr><td>Completed Tasks</td><td>{{ $totalCompletedTasks }}</td></tr>
        <tr><td>Active Execution</td><td>{{ $executionTasks->count() }}</td></tr>
        <tr><td>Review Queue</td><td>{{ $reviewQueueTasks->count() }}</td></tr>
        <tr><td>Cancelled Tasks</td><td>{{ $cancelledTasks }}</td></tr>
        <tr><td>In Progress</td><td>{{ $inProgressTasks }}</td></tr>
        <tr><td>Overdue</td><td>{{ $overdueTasks }}</td></tr>
        <tr><td>Completion Rate</td><td>{{ $completionRate }}%</td></tr>
    </table>
    <h2>Team Performance</h2>
    <table>
        <tr><th>Member</th><th>Total</th><th>Completed</th><th>Overdue</th><th>Rate</th></tr>
        @foreach($teamPerformance as $member)
            <tr>
                <td>{{ $member['name'] }}</td>
                <td>{{ $member['total'] }}</td>
                <td>{{ $member['completed'] }}</td>
                <td>{{ $member['overdue'] }}</td>
                <td>{{ $member['completionRate'] }}%</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
