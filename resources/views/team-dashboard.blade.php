@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/team.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.app-container { max-width: 1000px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.page-header h2 { font-size: 1.7rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.page-header p { color: #888; font-size: 1.05rem; }
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.metric-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.1rem 1rem; display: flex; flex-direction: column; align-items: center; min-width: 0; }
.metric-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
.metric-value { font-size: 1.7rem; font-weight: 600; color: #22223b; }
.metric-label { font-size: 1rem; color: #6c757d; margin-bottom: 0.2rem; }
.progress-card, .insights-card, .notifications-card, .deadlines-card, .overdue-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin-bottom: 2rem; }
.progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.progress-percentage { font-size: 1.2rem; font-weight: 500; color: #4f8cff; }
.progress-bar {
    width: 100%;
    height: 10px;
    background: #f1f3f6;
    border-radius: 6px;
    margin-bottom: 0.5rem;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #4f8cff 60%, #38b6ff 100%);
    border-radius: 6px;
    transition: width 0.4s;
}
.charts-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}
.chart-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 1rem 1rem 0.5rem 1rem;
    box-shadow: none;
    min-width: 0;
}
.status-chart, .priority-chart, .productivity-chart {
    margin-top: 0.7rem;
}
.status-item, .priority-item, .productivity-day {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin-bottom: 0.5rem;
}
.status-indicator.completed { background: #4f8cff; }
.status-indicator.in-progress { background: #ffb347; }
.status-indicator.overdue { background: #ff6b6b; }
.status-indicator {
    width: 12px; height: 12px; border-radius: 50%; display: inline-block;
}
.priority-indicator.high { background: #ff6b6b; }
.priority-indicator.medium { background: #ffb347; }
.priority-indicator.low { background: #4f8cff; }
.priority-indicator {
    width: 12px; height: 12px; border-radius: 50%; display: inline-block;
}
.status-bar, .priority-bar, .productivity-bar {
    flex: 1;
    height: 7px;
    background: #e5e7eb;
    border-radius: 4px;
    margin: 0 0.5rem;
    overflow: hidden;
}
.status-fill, .priority-fill, .productivity-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.4s;
}
.status-fill.completed { background: #4f8cff; }
.status-fill.in-progress { background: #ffb347; }
.status-fill.overdue { background: #ff6b6b; }
.priority-fill.high { background: #ff6b6b; }
.priority-fill.medium { background: #ffb347; }
.priority-fill.low { background: #4f8cff; }
.status-fill, .priority-fill { background: #4f8cff; }
.productivity-fill { background: #38b6ff; }
.overdue-card, .deadlines-card {
    border-left: 4px solid #ff6b6b;
}
.notifications-card {
    border-left: 4px solid #4f8cff;
    margin-bottom: 1.5rem;
}
.notification-list {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    margin-top: 0.7rem;
}
.notification-item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    background: #f1f3f6;
    border-radius: 8px;
    padding: 0.7rem 1rem;
    font-size: 1rem;
    color: #22223b;
}
.notification-icon {
    font-size: 1.2rem;
    color: #4f8cff;
}
.deadlines-card {
    border-left: 4px solid #ffb347;
}
.deadlines-list {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    margin-top: 0.7rem;
}
.deadline-task {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f1f3f6;
    border-radius: 8px;
    padding: 0.7rem 1rem;
}
.insights-card {
    border-left: 4px solid #38b6ff;
}
.insights-grid {
    display: flex;
    gap: 2rem;
    margin-top: 1rem;
}
.insight-section {
    flex: 1;
}
.achievement-item, .improvement-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #f8fafc;
    border-radius: 6px;
    padding: 0.5rem 0.8rem;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}
.achievement-icon, .improvement-icon {
    font-size: 1.1rem;
}
@media (max-width: 900px) { .metrics-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .metrics-grid { grid-template-columns: 1fr; } .app-container { padding: 1rem 0.2rem; } }
</style>
@endpush
@section('content')
<div class="app-container">
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <!-- Header -->
    <main class="main-content">
        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div class="page-header">
                <h2>My Dashboard</h2>
                <p>Welcome back, <span id="welcomeName">{{ $user->name }}</span></p>
            </div>
            <!-- Notifications (reference: settings) -->
            <div class="notifications-card">
                <h3>🔔 Notifications</h3>
                <div class="notification-list">
                    @forelse($notifications as $note)
                        <div class="notification-item">
                            <span class="notification-icon">
                                @if($note['type'] === 'completed')✅@elseif($note['type'] === 'overdue')⏰@else📥@endif
                            </span>
                            <span>{{ $note['text'] }}</span>
                        </div>
                    @empty
                        <div class="notification-item">No recent notifications.</div>
                    @endforelse
                </div>
            </div>
            <!-- Key Metrics -->
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-icon">📊</div>
                    <div class="metric-value" id="myTotalTasks">{{ $todayTotalTasks }}</div>
                    <div class="metric-label">Today's Tasks</div>
                    <div class="metric-subtitle">Assigned to you</div>
                </div>
                <div class="metric-card green">
                    <div class="metric-icon">✅</div>
                    <div class="metric-value" id="myCompletedTasks">{{ $todayCompletedTasks }}</div>
                    <div class="metric-label">Completed</div>
                    <div class="metric-subtitle">Today</div>
                </div>
                <div class="metric-card purple">
                    <div class="metric-icon">📈</div>
                    <div class="metric-value" id="myInProgressTasks">{{ $todayInProgressTasks }}</div>
                    <div class="metric-label">In Progress</div>
                </div>
                <div class="metric-card red">
                    <div class="metric-icon">⚠️</div>
                    <div class="metric-value" id="myOverdueTasks">{{ $todayOverdueTasks }}</div>
                    <div class="metric-label">Overdue</div>
                </div>
            </div>
            <!-- My Tasks Overview -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>📊 My Tasks by Status</h3>
                    <div class="status-chart">
                        <div class="status-item">
                            <div class="status-indicator not-started" style="background:#b0b3b8;"></div>
                            <span>Not Started</span>
                            <div class="status-bar">
                                <div class="status-fill" style="background:#b0b3b8; width: {{ $todayTotalTasks ? round($todayStatusCounts['Not Started'] / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayStatusCounts['Not Started'] }}</span>
                        </div>
                        <div class="status-item">
                            <div class="status-indicator in-progress"></div>
                            <span>In Progress</span>
                            <div class="status-bar">
                                <div class="status-fill" style="width: {{ $todayTotalTasks ? round($todayInProgressTasks / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayInProgressTasks }}</span>
                        </div>
                        <div class="status-item">
                            <div class="status-indicator overdue"></div>
                            <span>Overdue</span>
                            <div class="status-bar">
                                <div class="status-fill" style="width: {{ $todayTotalTasks ? round($todayOverdueTasks / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayOverdueTasks }}</span>
                        </div>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>⚠️ My Tasks by Priority</h3>
                    <div class="priority-chart">
                        <div class="priority-item">
                            <div class="priority-indicator high"></div>
                            <span>High</span>
                            <div class="priority-bar">
                                <div class="priority-fill" style="width: {{ $todayTotalTasks ? round($todayPriorityCounts['High'] / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayPriorityCounts['High'] }}</span>
                        </div>
                        <div class="priority-item">
                            <div class="priority-indicator medium"></div>
                            <span>Medium</span>
                            <div class="priority-bar">
                                <div class="priority-fill" style="width: {{ $todayTotalTasks ? round($todayPriorityCounts['Medium'] / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayPriorityCounts['Medium'] }}</span>
                        </div>
                        <div class="priority-item">
                            <div class="priority-indicator low"></div>
                            <span>Low</span>
                            <div class="priority-bar">
                                <div class="priority-fill" style="width: {{ $todayTotalTasks ? round($todayPriorityCounts['Low'] / $todayTotalTasks * 100) : 0 }}%"></div>
                            </div>
                            <span>{{ $todayPriorityCounts['Low'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="chart-card">
                    <h3>📈 7-Day Productivity</h3>
                    <div class="productivity-chart">
                        @foreach ($productivity as $day => $count)
                            <div class="productivity-day">
                                <span>{{ $day }}</span>
                                <div class="productivity-bar">
                                    <div class="productivity-fill" style="width: {{ $productivity->max() ? round($count / $productivity->max() * 100) : 0 }}%"></div>
                                </div>
                                <span>{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <!-- My Overdue Tasks -->
            <div class="overdue-card full-width">
                <h3>🕐 My Overdue Tasks ({{ $todayOverdueList->count() }})</h3>
                <div class="overdue-tasks">
                    @forelse($todayOverdueList as $task)
                        <div class="overdue-task" data-task-id="{{ $task->id }}">
                            <div class="task-info">
                                <h4>{{ $task->title }}</h4>
                                <p>Project: {{ $task->project ? $task->project->name : '-' }}</p>
                            </div>
                            <div class="task-meta">
                                <p>Due: {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</p>
                                <p class="priority">{{ $task->priority }} priority</p>
                            </div>
                            <div class="task-actions">
                                <a href="{{ route('tasks') }}" class="btn-small btn-primary">Open Task</a>
                            </div>
                        </div>
                    @empty
                        <p>No overdue tasks!</p>
                    @endforelse
                </div>
            </div>
            <!-- Upcoming Deadlines -->
            <div class="deadlines-card full-width">
                <h3>📅 Upcoming Deadlines</h3>
                <div class="deadlines-list">
                    @php
                        $upcoming = $todayTasks->where('due_date', '>=', now()->toDateString())->where('due_date', '<=', now()->addDays(7)->toDateString())->sortBy('due_date');
                    @endphp
                    @forelse($upcoming as $task)
                        <div class="deadline-task">
                            <div class="task-info">
                                <h4>{{ $task->title }}</h4>
                                <p>Project: {{ $task->project ? $task->project->name : '-' }}</p>
                            </div>
                            <div class="task-meta">
                                <p>Due: {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</p>
                                <p class="priority">{{ $task->priority }} priority</p>
                            </div>
                        </div>
                    @empty
                        <p>No upcoming deadlines in the next 7 days!</p>
                    @endforelse
                </div>
            </div>
            <!-- Performance Insights -->
            <div class="insights-card">
                <h3>✨ Performance Insights</h3>
                <div class="insights-grid">
                    <div class="insight-section">
                        <h4>Key Achievements</h4>
                        <div class="achievements" id="achievements">
                            @foreach ($achievements as $item)
                                <div class="achievement-item">{{ $item }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="insight-section">
                        <h4>Areas for Improvement</h4>
                        <div class="improvements" id="improvements">
                            @foreach ($improvements as $item)
                                <div class="improvement-item">{{ $item }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
<!-- Completed Tasks History (last 7 days) -->
@if($recentCompletedHistory->count())
<div class="completed-history-card" style="margin: 3rem auto 0 auto; max-width: 700px; background: #fff; border-radius: 14px; box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06); padding: 1.5rem 1.2rem;">
    <h3 style="font-weight:600; color:#4f8cff; display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
        <span style="font-size:1.3rem;">✅</span> Completed Tasks (Last 7 Days)
    </h3>
    <table class="tasks-table" style="margin-bottom:0;">
        <thead style="background:#f4f8ff;">
            <tr>
                <th style="color:#4f8cff;">Title</th>
                <th style="color:#4f8cff;">Project</th>
                <th style="color:#4f8cff;">Completed At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentCompletedHistory as $task)
                <tr>
                    <td><span style="font-size:1.1rem;">{{ $task->title }}</span></td>
                    <td><span style="color:#888;">{{ $task->project ? $task->project->name : '-' }}</span></td>
                    <td><span style="color:#38b6ff; font-weight:500;">{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('m/d/Y') : '-' }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@else
<div class="completed-history-card" style="margin: 3rem auto 0 auto; max-width: 700px; background: #fff; border-radius: 14px; box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06); padding: 1.5rem 1.2rem; text-align:center; color:#888;">
    <h3 style="font-weight:600; color:#4f8cff; display:flex; align-items:center; gap:0.5rem; justify-content:center;">
        <span style="font-size:1.3rem;">✅</span> Completed Tasks (Last 7 Days)
    </h3>
    <div style="margin-top:1.5rem; font-size:1.1rem;">No completed tasks in the last 7 days.</div>
</div>
@endif
<!-- My Overall Progress Bar at the very bottom -->
<div class="progress-card" style="margin: 3rem auto 0 auto; max-width: 600px;">
    <div class="progress-header">
        <h3>My Overall Progress</h3>
        <span class="progress-percentage">{{ $todayAvgProgress }}%</span>
    </div>
    <div class="progress-bar-container">
        <div class="progress-bar">
            <div class="progress-fill" style="width: {{ $todayAvgProgress }}%"></div>
        </div>
    </div>
    <p id="progressDescription">Average completion across {{ $todayTotalTasks }} tasks</p>
</div>
</div>
@endsection
