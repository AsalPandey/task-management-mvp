<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-task-deadline-reminders')
    ->dailyAt('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

Schedule::command('app:send-overdue-task-notifications')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('backup:run --only-db')
    ->dailyAt('01:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

Schedule::command('backup:clean')
    ->dailyAt('02:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

Schedule::command('backup:monitor')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
