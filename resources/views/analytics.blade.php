@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/analytics.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
@endpush
@section('content')
<div class="analytics-container">
    <!-- Header -->
    <div class="analytics-header">
        <div class="header-content">
            <h1>Team Analytics</h1>
            <p>Comprehensive team performance insights</p>
        </div>
        <div class="header-meta">
            <div class="last-updated">
                <span class="date-icon">📅</span>
                <span>Updated: <span>{{ $lastUpdated ? $lastUpdated->format('M d, Y H:i') : 'Never' }}</span></span>
            </div>
        </div>
    </div>
    <!-- Key Performance Metrics -->
    <div class="metrics-grid">
        <div class="metric-card blue">
            <div class="metric-header">
                <div class="metric-icon">📊</div>
                <div class="metric-trend">+12%</div>
            </div>
            <div class="metric-value">{{ $totalTasks }}</div>
            <div class="metric-label">Total Tasks</div>
            <div class="metric-subtitle">Across all projects</div>
        </div>
        <div class="metric-card green">
            <div class="metric-header">
                <div class="metric-icon">🎯</div>
                <div class="metric-trend">+8%</div>
            </div>
            <div class="metric-value">{{ $completionRate }}%</div>
            <div class="metric-label">Completion Rate</div>
            <div class="metric-subtitle">{{ $completedTasks }} of {{ $totalTasks }} completed</div>
        </div>
        <div class="metric-card purple">
            <div class="metric-header">
                <div class="metric-icon">📈</div>
                <div class="metric-trend">+5%</div>
            </div>
            <div class="metric-value">{{ $avgProgress }}%</div>
            <div class="metric-label">Average Progress</div>
            <div class="metric-subtitle">Overall task completion</div>
        </div>
        <div class="metric-card red">
            <div class="metric-header">
                <div class="metric-icon">⚠️</div>
                <div class="metric-trend">-2%</div>
            </div>
            <div class="metric-value">{{ $overdueTasks }}</div>
            <div class="metric-label">Overdue Tasks</div>
            <div class="metric-subtitle">Needs attention</div>
        </div>
    </div>
    <!-- Detailed Analytics -->
    <div class="analytics-grid">
        <!-- Priority Breakdown -->
        <div class="chart-card">
            <h3>📊 Tasks by Priority</h3>
            <div class="priority-chart" id="priorityChart">
                @php $total = $priorityCounts->sum(); @endphp
                @foreach ($priorityCounts as $priority => $count)
                    <div class="priority-item">
                        <div class="priority-indicator {{ strtolower($priority) }}"></div>
                        <span class="priority-label">{{ ucfirst($priority) }} Priority</span>
                        <div class="priority-bar">
                            <div class="priority-fill {{ strtolower($priority) }}" style="width: {{ $total ? round($count / $total * 100) : 0 }}%"></div>
                        </div>
                        <span class="priority-count">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <!-- Productivity Trend -->
        <div class="chart-card">
            <h3>📈 7-Day Productivity Trend</h3>
            <div class="productivity-chart" id="productivityChart">
                @foreach ($productivity as $day => $count)
                    <div class="productivity-day">
                        <span class="day-label">{{ $day }}</span>
                        <div class="productivity-bar">
                            <div class="productivity-fill" style="width: {{ $productivity->max() ? round($count / $productivity->max() * 100) : 0 }}%"></div>
                        </div>
                        <span class="day-count">{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <!-- Team Performance Overview -->
    <div id="teamPerformanceSection" class="team-performance-card">
        <h3>👥 Team Performance Overview</h3>
        <div class="team-performance-grid" id="teamPerformanceGrid">
            @foreach ($teamPerformance as $member)
                <div class="team-member-performance">
                    <div class="member-header">
                        <div class="member-avatar">{{ $member['avatar'] }}</div>
                        <div class="member-info">
                            <h4>{{ $member['name'] }}</h4>
                            <p>{{ $member['completionRate'] }}% completion rate</p>
                        </div>
                    </div>
                    <div class="performance-stats">
                        <div class="stat-row">
                            <span class="stat-label">Total Tasks:</span>
                            <span class="stat-value">{{ $member['total'] }}</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Completed:</span>
                            <span class="stat-value completed">{{ $member['completed'] }}</span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Overdue:</span>
                            <span class="stat-value overdue">{{ $member['overdue'] }}</span>
                        </div>
                    </div>
                    <div class="completion-bar">
                        <div class="completion-fill" style="width: {{ $member['completionRate'] }}%"></div>
                    </div>
                </div>
            @endforeach
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
    <!-- Project Performance -->
    <div class="project-performance-card">
        <h3>📁 Project Performance</h3>
        <div class="project-performance-grid" id="projectPerformanceGrid">
            @foreach ($projectPerformance as $project)
                <div class="project-performance-item">
                    <div class="project-header">
                        <div class="project-color" style="background-color: {{ $project['color'] }};"></div>
                        <div class="project-info">
                            <h4>{{ $project['name'] }}</h4>
                            <p>{{ $project['progress'] }}% complete</p>
                        </div>
                    </div>
                    <div class="project-stats">
                        <div class="project-stat">
                            <span class="stat-label">Tasks:</span>
                            <span class="stat-value">{{ $project['total'] }}</span>
                        </div>
                        <div class="project-stat">
                            <span class="stat-label">Done:</span>
                            <span class="stat-value">{{ $project['done'] }}</span>
                        </div>
                    </div>
                    <div class="project-progress-bar">
                        <div class="project-progress-fill" style="width: {{ $project['progress'] }}%; background-color: {{ $project['color'] }};"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection 