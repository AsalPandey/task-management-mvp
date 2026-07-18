<?php

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->seed();
});

dataset('stored xss payloads', [
    'script element' => ['<script>alert(1)</script>'],
    'image error handler' => ['<img src=x onerror=alert(1)>'],
    'attribute breakout with svg' => ['"><svg onload=alert(1)>'],
    'unicode and punctuation' => ['नेपाली कार्य "सुरक्षित", ठीक छ!'],
]);

test('manager dashboard renders stored task titles as text', function (string $payload) {
    $manager = User::factory()->create([
        'role_id' => Role::query()->where('name', 'manager')->value('id'),
    ]);
    $project = Project::factory()->create(['project_manager_id' => $manager->id]);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => $payload,
        'assignee_id' => $manager->id,
        'priority' => 'Medium',
        'status' => 'In Progress',
        'progress' => 25,
    ]);

    $response = $this->actingAs($manager)->get('/manager');

    $response->assertOk()
        ->assertSee($payload)
        ->assertDontSee($payload, false);
    expect($task->fresh()->title)->toBe($payload);
})->with('stored xss payloads');

test('team dashboard renders stored task titles as text', function (string $payload) {
    $manager = User::factory()->create([
        'role_id' => Role::query()->where('name', 'manager')->value('id'),
    ]);
    $member = User::factory()->create([
        'role_id' => Role::query()->where('name', 'team_member')->value('id'),
    ]);
    $project = Project::factory()->create(['project_manager_id' => $manager->id]);
    $project->members()->attach($member->id);
    $task = Task::query()->create([
        'project_id' => $project->id,
        'title' => $payload,
        'assignee_id' => $member->id,
        'priority' => 'High',
        'status' => 'In Progress',
        'progress' => 50,
    ]);

    $response = $this->actingAs($member)->get('/team-dashboard');

    $response->assertOk()
        ->assertSee($payload)
        ->assertDontSee($payload, false);
    expect($task->fresh()->title)->toBe($payload);
})->with('stored xss payloads');

test('team management keeps stored member names out of html-capable SweetAlert properties', function (string $payload) {
    $manager = User::factory()->create([
        'role_id' => Role::query()->where('name', 'manager')->value('id'),
    ]);
    $member = User::factory()->create([
        'name' => $payload,
        'role_id' => Role::query()->where('name', 'team_member')->value('id'),
    ]);

    $response = $this->actingAs($manager)->get('/team-management');

    $response->assertOk()
        ->assertSee($payload)
        ->assertDontSee($payload, false)
        ->assertSee("title: 'Remove team member?'", false)
        ->assertSee('text: `Remove ${memberName}? This action cannot be undone.`', false)
        ->assertDontSee('title: `Remove ${memberName}?`', false);
    expect($member->fresh()->name)->toBe($payload);
})->with('stored xss payloads');
