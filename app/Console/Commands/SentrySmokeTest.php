<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;

use function Sentry\captureException;

class SentrySmokeTest extends Command
{
    protected $signature = 'app:sentry-smoke-test';

    protected $description = 'Send a test exception to Sentry to verify centralized error tracking.';

    public function handle(): int
    {
        if (! config('sentry.dsn')) {
            $this->error('Sentry DSN is not configured.');

            return self::FAILURE;
        }

        captureException(new Exception('Task Management Sentry smoke test'));
        $this->info('Sentry smoke test exception captured.');

        return self::SUCCESS;
    }
}
