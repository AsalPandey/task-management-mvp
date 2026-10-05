@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/tasks.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
.completed-table-wrapper { width: 100%; max-width: 100%; margin-top: 2rem; overflow-x: auto; border-radius: 10px; box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06); -webkit-overflow-scrolling: touch; }
.completed-table { width: 100%; min-width: 668px; border-collapse: collapse; background: #fff; }
.completed-table th, .completed-table td { padding: 0.7rem 1rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
.completed-table th { background: #f8fafc; font-weight: 600; color: #22223b; }
.completed-table tr:last-child td { border-bottom: none; }
@media (max-width: 768px) {
    .completed-table .empty-state-cell { text-align: left !important; }
}
.btn-small { font-size: 0.95rem; padding: 0.2rem 0.7rem; border-radius: 6px; border: none; cursor: pointer; margin-right: 0.3rem; }
.btn-small.btn-primary { background: #4f8cff; color: #fff; }
.btn-small.btn-danger { background: #ff6b6b; color: #fff; }
</style>
@endpush
@push('scripts')
@vite('resources/js/task-features.js')
@php
    $reopenReviewerCandidates = $reviewerCandidates->map(function ($reviewer) {
        return [
            'id' => $reviewer->id,
            'name' => $reviewer->name,
            'role' => $reviewer->role?->name,
        ];
    })->values();
@endphp
<script>
document.addEventListener('DOMContentLoaded', function() {
    const reviewerCandidates = @json($reopenReviewerCandidates);
    window.TaskFeatures.initTimelineActions(message => Swal.fire('Error', message, 'error'));
    window.TaskFeatures.initReopenActions(reviewerCandidates);
});
</script>
@endpush
@section('content')
<div class="tasks-container">
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <h1>Completed Tasks History</h1>
    <div class="completed-table-wrapper" role="region" aria-label="Completed task history" tabindex="0">
    <table class="completed-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Assignee</th>
                <th>Project</th>
                <th>Due Date</th>
                <th>Completed At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($completed as $task)
                <tr
                    data-description="{{ htmlspecialchars($task->description ?? '', ENT_QUOTES) }}"
                    data-comments="{{ htmlspecialchars($task->comments ?? '', ENT_QUOTES) }}"
                >
                    <td>{{ $task->title }}</td>
                    <td><span class="status-badge status-completed">Completed</span></td>
                    <td><span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span></td>
                    <td>{{ $task->assignee ? $task->assignee->name : '-' }}</td>
                    <td>{{ $task->project ? $task->project->name : '-' }}</td>
                    <td>{{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</td>
                    <td>{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('m/d/Y') : '-' }}</td>
                    <td>
                        <button type="button" class="btn-small btn-secondary timeline-btn"
                            data-url="{{ route('tasks.timeline', $task) }}">Timeline</button>
                        @if($task->legacy_completion_provenance && !$task->approval)
                            <span>Historical completion: approval evidence unavailable. Retained for audit; create separately reviewed follow-up work.</span>
                        @else
                        @can('reopen', $task)
                            <button class="btn-small btn-primary reopen-revision-btn"
                                data-task-version="{{ $task->lock_version }}" data-url="{{ route('tasks.reopen', $task) }}"
                                data-reviewer-id="{{ $task->reviewer?->isActive() ? $task->reviewer_id : '' }}">Reopen for Revision</button>
                        @else
                            <span aria-label="Reopen unavailable">&mdash;</span>
                        @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="empty-state-cell" colspan="8" style="text-align:center; color:#888;">No completed tasks yet</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
    @if(method_exists($completed, 'links'))
        <div class="pagination-wrapper" style="margin-top:2rem; text-align:center;">
            {{ $completed->links() }}
        </div>
    @endif
</div>
@endsection
