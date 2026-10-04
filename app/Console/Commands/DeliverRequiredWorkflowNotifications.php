<?php

namespace App\Console\Commands;

use App\Models\WorkflowNotificationIntent;
use App\Services\RequiredWorkflowNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverRequiredWorkflowNotifications extends Command
{
    protected $signature = 'app:deliver-required-workflow-notifications {--limit=100} {--prune-days=30}';

    protected $description = 'Retry bounded pending mandatory in-app workflow notices and prune finished intents.';

    public function handle(RequiredWorkflowNotifications $delivery): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = WorkflowNotificationIntent::where('status', 'pending')->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $delivery->deliver($id);
            } catch (Throwable $exception) {
                Log::warning('Required workflow notification retry deferred.', ['intent_id' => $id, 'exception_type' => get_class($exception)]);
            }
        }
        $finished = WorkflowNotificationIntent::whereIn('status', ['delivered', 'discarded'])
            ->where('finished_at', '<', now()->subDays(max(1, (int) $this->option('prune-days'))))
            ->orderBy('finished_at')->limit($limit)->pluck('id');
        WorkflowNotificationIntent::whereIn('id', $finished)->delete();

        $this->info('Processed '.$ids->count().' pending intents; pruned '.$finished->count().' finished intents.');

        return self::SUCCESS;
    }
}
