<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskPaginationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $projectManager;

    private User $otherProjectManager;

    private User $member;

    private User $otherMember;

    private Project $managedProject;

    private Project $otherProject;

    private int $taskSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->manager = $this->userWithRole('manager', ['name' => 'Company Manager']);
        $this->projectManager = $this->userWithRole('project_manager', ['name' => 'Managed Project Lead']);
        $this->otherProjectManager = $this->userWithRole('project_manager', ['name' => 'Other Project Lead']);
        $this->member = $this->userWithRole('team_member', ['name' => 'Authorized Assignee']);
        $this->otherMember = $this->userWithRole('team_member', ['name' => 'Outside Assignee']);

        $this->managedProject = Project::factory()->create([
            'name' => 'Authorized Project',
            'project_manager_id' => $this->projectManager->id,
        ]);
        $this->otherProject = Project::factory()->create([
            'name' => 'Outside Project',
            'project_manager_id' => $this->otherProjectManager->id,
        ]);

        $this->managedProject->members()->attach([$this->member->id, $this->otherMember->id]);
        $this->otherProject->members()->attach($this->otherMember->id);
    }

    public function test_first_and_second_pages_are_reachable_with_stable_complete_results(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $created = $this->createTasks(30, ['created_at' => $timestamp]);

        $firstResponse = $this->actingAs($this->manager)->get(route('tasks'));
        $firstPage = $this->paginator($firstResponse);

        $firstResponse
            ->assertOk()
            ->assertSee('aria-label="Pagination Navigation"', false)
            ->assertSee('page=2', false)
            ->assertSee('Showing 1&ndash;24 of 30 tasks', false);
        $this->assertSame(24, $firstPage->perPage());
        $this->assertCount(24, $firstPage->items());
        $this->assertSame(30, $firstPage->total());

        $secondResponse = $this->actingAs($this->manager)->get(route('tasks', ['page' => 2]));
        $secondPage = $this->paginator($secondResponse);

        $secondResponse
            ->assertOk()
            ->assertSee('Showing 25&ndash;30 of 30 tasks', false);
        $this->assertCount(6, $secondPage->items());

        $expectedIds = $created->pluck('id')->sortDesc()->values()->all();
        $actualIds = $firstPage->getCollection()
            ->concat($secondPage->getCollection())
            ->pluck('id')
            ->all();

        $this->assertSame($expectedIds, $actualIds);
        $this->assertCount(30, array_unique($actualIds));
    }

    public function test_task_beyond_page_one_is_searchable_across_the_full_dataset(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $target = $this->createTask([
            'title' => 'Deep backlog release needle',
            'created_at' => $timestamp,
        ]);
        $this->createTasks(29, ['created_at' => $timestamp]);

        $unfiltered = $this->actingAs($this->manager)->get(route('tasks'));

        $this->assertFalse($this->paginator($unfiltered)->getCollection()->contains('id', $target->id));

        $filtered = $this->actingAs($this->manager)->get(route('tasks', [
            'search' => 'release needle',
        ]));

        $filtered->assertOk()->assertSee($target->title);
        $this->assertSame([$target->id], $this->paginator($filtered)->getCollection()->pluck('id')->all());
    }

    public function test_pagination_links_preserve_all_active_filters(): void
    {
        $this->createTasks(30, [
            'status' => 'In Progress',
            'priority' => 'High',
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
        ]);

        $filters = [
            'search' => 'Task',
            'status' => 'In Progress',
            'priority' => 'High',
            'project' => $this->managedProject->id,
            'assignee' => $this->member->id,
        ];
        $response = $this->actingAs($this->manager)->get(route('tasks', $filters));
        $nextPageUrl = $this->paginator($response)->nextPageUrl();

        $this->assertNotNull($nextPageUrl);
        parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $query);

        $this->assertSame('2', (string) $query['page']);
        $this->assertSame('Task', $query['search']);
        $this->assertSame(TaskState::InProgress->value, $query['status']);
        $this->assertSame('High', $query['priority']);
        $this->assertSame((string) $this->managedProject->id, (string) $query['project']);
        $this->assertSame((string) $this->member->id, (string) $query['assignee']);
        $response->assertSee(e($nextPageUrl), false);
    }

    public function test_status_filter_applies_before_pagination(): void
    {
        $this->assertFilterAcrossPages(
            ['status' => 'In Progress'],
            ['status' => 'In Progress'],
            ['status' => 'Not Started']
        );
    }

    public function test_priority_filter_applies_before_pagination(): void
    {
        $this->assertFilterAcrossPages(
            ['priority' => 'High'],
            ['priority' => 'High'],
            ['priority' => 'Low']
        );
    }

    public function test_project_filter_applies_before_pagination(): void
    {
        $this->assertFilterAcrossPages(
            ['project' => $this->managedProject->id],
            ['project_id' => $this->managedProject->id],
            ['project_id' => $this->otherProject->id]
        );
    }

    public function test_assignee_filter_applies_before_pagination(): void
    {
        $this->assertFilterAcrossPages(
            ['assignee' => $this->member->id],
            ['assignee_id' => $this->member->id],
            ['assignee_id' => $this->otherMember->id]
        );
    }

    public function test_combined_filter_categories_use_and_semantics(): void
    {
        $matching = $this->createTask([
            'title' => 'Combined exact result',
            'status' => 'In Progress',
            'priority' => 'High',
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
        ]);
        $this->createTask(['title' => 'Wrong status', 'status' => 'On Hold', 'priority' => 'High']);
        $this->createTask(['title' => 'Wrong priority', 'status' => 'In Progress', 'priority' => 'Low']);
        $this->createTask([
            'title' => 'Wrong project',
            'status' => 'In Progress',
            'priority' => 'High',
            'project_id' => $this->otherProject->id,
        ]);
        $this->createTask([
            'title' => 'Wrong assignee',
            'status' => 'In Progress',
            'priority' => 'High',
            'assignee_id' => $this->otherMember->id,
        ]);

        $response = $this->actingAs($this->manager)->get(route('tasks', [
            'search' => 'result',
            'status' => 'In Progress',
            'priority' => 'High',
            'project' => $this->managedProject->id,
            'assignee' => $this->member->id,
        ]));

        $this->assertSame([$matching->id], $this->paginator($response)->getCollection()->pluck('id')->all());
    }

    public function test_search_covers_description_project_and_assignee_fields(): void
    {
        $descriptionTask = $this->createTask([
            'title' => 'Ordinary title',
            'description' => 'Contains description-token for lookup',
        ]);
        $projectTask = $this->createTask([
            'title' => 'Another ordinary title',
            'project_id' => $this->otherProject->id,
            'assignee_id' => $this->otherMember->id,
        ]);
        $namedAssignee = $this->userWithRole('team_member', ['name' => 'Unique Search Person']);
        $this->managedProject->members()->attach($namedAssignee->id);
        $assigneeTask = $this->createTask([
            'title' => 'Third ordinary title',
            'assignee_id' => $namedAssignee->id,
        ]);

        $this->assertSearchReturns('description-token', [$descriptionTask->id]);
        $this->assertSearchReturns('Outside Project', [$projectTask->id]);
        $this->assertSearchReturns('Unique Search Person', [$assigneeTask->id]);
    }

    public function test_search_or_clauses_remain_grouped_inside_project_manager_scope(): void
    {
        $authorized = $this->createTask([
            'title' => 'Authorized ordinary task',
            'description' => 'Contains grouped-needle',
        ]);
        $outside = $this->createTask([
            'title' => 'grouped-needle outside task',
            'project_id' => $this->otherProject->id,
            'assignee_id' => $this->otherMember->id,
        ]);

        $response = $this->actingAs($this->projectManager)->get(route('tasks', [
            'search' => 'grouped-needle',
        ]));
        $ids = $this->paginator($response)->getCollection()->pluck('id')->all();

        $this->assertSame([$authorized->id], $ids);
        $this->assertNotContains($outside->id, $ids);
    }

    public function test_search_treats_like_wildcards_as_literal_characters(): void
    {
        $literal = $this->createTask(['title' => 'Budget 100%_done!']);
        $this->createTask(['title' => 'Budget 100Xdone']);

        $response = $this->actingAs($this->manager)->get(route('tasks', [
            'search' => '%_',
        ]));

        $this->assertSame([$literal->id], $this->paginator($response)->getCollection()->pluck('id')->all());
    }

    public function test_filter_values_are_retained_and_clearing_restores_all_authorized_tasks(): void
    {
        $this->createTask(['status' => 'In Progress']);
        $this->createTask(['status' => 'Not Started']);

        $filtered = $this->actingAs($this->manager)->get(route('tasks', [
            'status' => 'In Progress',
        ]));

        $filtered
            ->assertOk()
            ->assertViewHas('filters', ['status' => TaskState::InProgress->value])
            ->assertViewHas('hasActiveFilters', true)
            ->assertSee('value="in_progress" selected', false)
            ->assertSee('Clear filters');
        $this->assertSame(1, $this->paginator($filtered)->total());

        $cleared = $this->actingAs($this->manager)->get(route('tasks'));

        $cleared
            ->assertOk()
            ->assertViewHas('hasActiveFilters', false)
            ->assertDontSee('Clear filters');
        $this->assertSame(2, $this->paginator($cleared)->total());
    }

    public function test_invalid_filters_and_overlong_search_fail_validation_safely(): void
    {
        $response = $this->actingAs($this->manager)
            ->from(route('tasks'))
            ->get(route('tasks', [
                'search' => str_repeat('x', 201),
                'status' => 'Injected',
                'priority' => 'Urgent',
                'project' => 'not-an-id',
                'assignee' => -1,
            ]));

        $response
            ->assertRedirect(route('tasks'))
            ->assertSessionHasErrors(['search', 'status', 'priority', 'project', 'assignee']);
    }

    public function test_crafted_inaccessible_project_and_assignee_filters_cannot_expand_access(): void
    {
        $this->createTask([
            'title' => 'Authorized project task',
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
        ]);
        $outsideTask = $this->createTask([
            'title' => 'Outside secret task',
            'project_id' => $this->otherProject->id,
            'assignee_id' => $this->otherMember->id,
        ]);

        $projectResponse = $this->actingAs($this->projectManager)->get(route('tasks', [
            'project' => $this->otherProject->id,
        ]));
        $assigneeResponse = $this->actingAs($this->projectManager)->get(route('tasks', [
            'assignee' => $this->otherMember->id,
        ]));

        $this->assertSame(0, $this->paginator($projectResponse)->total());
        $this->assertSame(0, $this->paginator($assigneeResponse)->total());
        $projectResponse->assertDontSee($outsideTask->title);
        $assigneeResponse->assertDontSee($outsideTask->title);
        $this->assertNotContains(
            $this->otherProject->id,
            $projectResponse->viewData('filterProjects')->pluck('id')->all()
        );
        $this->assertNotContains(
            $this->otherMember->id,
            $assigneeResponse->viewData('assignees')->pluck('id')->all()
        );
    }

    public function test_company_manager_project_manager_and_team_member_visibility_is_preserved(): void
    {
        $managedForMember = $this->createTask([
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
        ]);
        $managedForOther = $this->createTask([
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->otherMember->id,
        ]);
        $outsideForMember = $this->createTask([
            'project_id' => $this->otherProject->id,
            'assignee_id' => $this->member->id,
        ]);

        $managerIds = $this->taskIdsFor($this->manager);
        $projectManagerIds = $this->taskIdsFor($this->projectManager);
        $memberIds = $this->taskIdsFor($this->member);

        $this->assertEqualsCanonicalizing(
            [$managedForMember->id, $managedForOther->id, $outsideForMember->id],
            $managerIds
        );
        $this->assertEqualsCanonicalizing(
            [$managedForMember->id, $managedForOther->id],
            $projectManagerIds
        );
        $this->assertEqualsCanonicalizing(
            [$managedForMember->id, $outsideForMember->id],
            $memberIds
        );
    }

    public function test_soft_deleted_and_completed_history_records_are_excluded_from_live_tasks(): void
    {
        $live = $this->createTask(['title' => 'Live task']);
        $deleted = $this->createTask(['title' => 'Soft deleted task']);
        $deleted->delete();
        DB::table('completed_tasks')->insert([
            'title' => 'Completed history task',
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $this->manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)->get(route('tasks'));

        $this->assertSame([$live->id], $this->paginator($response)->getCollection()->pluck('id')->all());
        $response
            ->assertSee('Live task')
            ->assertDontSee('Soft deleted task')
            ->assertDontSee('Completed history task');
    }

    public function test_empty_states_distinguish_no_available_tasks_from_no_filter_matches(): void
    {
        $empty = $this->actingAs($this->manager)->get(route('tasks'));

        $empty
            ->assertOk()
            ->assertSee('No tasks available')
            ->assertDontSee('No tasks match your filters');

        $this->createTask(['title' => 'Existing task']);
        $noMatches = $this->actingAs($this->manager)->get(route('tasks', [
            'search' => 'does-not-exist',
        ]));

        $noMatches
            ->assertOk()
            ->assertSee('No tasks match your filters')
            ->assertSee('clear all filters')
            ->assertDontSee('No tasks available');
    }

    public function test_excessively_high_page_number_uses_normal_empty_paginator_behavior(): void
    {
        $this->createTasks(30);

        $response = $this->actingAs($this->manager)->get(route('tasks', ['page' => 999]));
        $paginator = $this->paginator($response);

        $response->assertOk();
        $this->assertSame(999, $paginator->currentPage());
        $this->assertSame(30, $paginator->total());
        $this->assertCount(0, $paginator->items());
    }

    public function test_task_relationships_are_eager_loaded_for_the_rendered_page(): void
    {
        $this->createTasks(24);

        $response = $this->actingAs($this->manager)->get(route('tasks'));

        $this->assertTrue($this->paginator($response)->getCollection()->every(
            fn (Task $task) => $task->relationLoaded('project')
                && $task->relationLoaded('assignee')
                && $task->relationLoaded('creator')
        ));
    }

    public function test_view_uses_get_filters_and_has_no_authoritative_dom_filtering(): void
    {
        $source = file_get_contents(resource_path('views/tasks.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('<form method="GET"', $source);
        $this->assertStringContainsString('name="search"', $source);
        $this->assertStringContainsString('name="project"', $source);
        $this->assertStringNotContainsString('function filterTasks()', $source);
        $this->assertStringNotContainsString("card.style.display = visible ? '' : 'none'", $source);
        $this->assertStringNotContainsString("row.style.display = visible ? '' : 'none'", $source);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $matchingAttributes
     * @param  array<string, mixed>  $nonMatchingAttributes
     */
    private function assertFilterAcrossPages(
        array $query,
        array $matchingAttributes,
        array $nonMatchingAttributes
    ): void {
        $this->createTasks(26, $matchingAttributes);
        $this->createTasks(5, $nonMatchingAttributes);

        $firstResponse = $this->actingAs($this->manager)->get(route('tasks', $query));
        $firstPage = $this->paginator($firstResponse);
        $secondPage = $this->paginator(
            $this->actingAs($this->manager)->get(route('tasks', [...$query, 'page' => 2]))
        );

        $this->assertSame(26, $firstPage->total());
        $this->assertCount(24, $firstPage->items());
        $this->assertCount(2, $secondPage->items());
    }

    private function assertSearchReturns(string $search, array $expectedIds): void
    {
        $response = $this->actingAs($this->manager)->get(route('tasks', ['search' => $search]));

        $this->assertEqualsCanonicalizing(
            $expectedIds,
            $this->paginator($response)->getCollection()->pluck('id')->all()
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Task>
     */
    private function createTasks(int $count, array $attributes = []): Collection
    {
        return collect(range(1, $count))->map(fn () => $this->createTask($attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTask(array $overrides = []): Task
    {
        $this->taskSequence++;
        $createdAt = $overrides['created_at'] ?? now()->startOfSecond();
        unset($overrides['created_at']);

        $task = new Task(array_merge([
            'title' => "Task {$this->taskSequence}",
            'description' => "Description {$this->taskSequence}",
            'project_id' => $this->managedProject->id,
            'assignee_id' => $this->member->id,
            'created_by' => $this->manager->id,
            'assigned_by' => $this->manager->id,
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
        ], $overrides));
        $task->created_at = $createdAt;
        $task->updated_at = $createdAt;
        $task->save();

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ], $attributes));
    }

    /**
     * @return list<int>
     */
    private function taskIdsFor(User $user): array
    {
        $response = $this->actingAs($user)->get(route('tasks'));

        return $this->paginator($response)->getCollection()->pluck('id')->all();
    }

    private function paginator(TestResponse $response): LengthAwarePaginator
    {
        $response->assertOk();
        $paginator = $response->viewData('tasks');

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);

        return $paginator;
    }
}
