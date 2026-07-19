<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\User;
use App\ValueObjects\TaskOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TaskCorrelationIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(EnsureTaskCorrelationId::class)
            ->get('/__testing/task-correlation', function (Request $request) {
                return response()->json([
                    'correlation_id' => $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE),
                ]);
            });
    }

    public function test_a_valid_incoming_correlation_id_is_preserved_on_request_and_response(): void
    {
        $correlationId = 'client-request_123:retry.2';

        $this->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->getJson('/__testing/task-correlation')
            ->assertOk()
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->assertJson(['correlation_id' => $correlationId]);
    }

    public function test_invalid_and_oversized_correlation_ids_are_replaced_with_ulids(): void
    {
        foreach (['invalid correlation', str_repeat('a', 65), '<script>alert(1)</script>'] as $invalid) {
            $response = $this->withHeader(EnsureTaskCorrelationId::HEADER, $invalid)
                ->getJson('/__testing/task-correlation')
                ->assertOk();
            $generated = $response->headers->get(EnsureTaskCorrelationId::HEADER);

            $this->assertNotSame($invalid, $generated);
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generated);
            $this->assertSame($generated, $response->json('correlation_id'));
        }
    }

    public function test_a_missing_correlation_id_is_generated_and_returned(): void
    {
        $response = $this->getJson('/__testing/task-correlation')->assertOk();
        $generated = $response->headers->get(EnsureTaskCorrelationId::HEADER);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generated);
        $this->assertSame($generated, $response->json('correlation_id'));
    }

    public function test_task_mutation_routes_apply_correlation_middleware(): void
    {
        foreach ([
            'tasks.store',
            'tasks.update',
            'tasks.destroy',
            'tasks.bulk-delete',
            'tasks.bulk-complete',
            'tasks.reopen',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)->gatherMiddleware();

            $this->assertContains(EnsureTaskCorrelationId::class, $middleware, $routeName);
        }
    }

    public function test_operation_context_factories_work_without_an_http_request(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-19T12:34:56+05:45');
        $user = new User;
        $user->id = 42;

        $web = TaskOperationContext::web($user, 'web-request-1', $occurredAt);
        $system = TaskOperationContext::system(null, 'system-run-1', $occurredAt);
        $console = TaskOperationContext::console(42, 'console-run-1', $occurredAt);
        $test = TaskOperationContext::test(42, 'test-run-1', $occurredAt);

        $this->assertSame([42, 'web', 'web-request-1'], [$web->actorId, $web->source, $web->correlationId]);
        $this->assertSame([null, 'system', 'system-run-1'], [$system->actorId, $system->source, $system->correlationId]);
        $this->assertSame([42, 'console', 'console-run-1'], [$console->actorId, $console->source, $console->correlationId]);
        $this->assertSame([42, 'test', 'test-run-1'], [$test->actorId, $test->source, $test->correlationId]);
        $this->assertTrue($occurredAt->equalTo($test->occurredAt));
    }
}
