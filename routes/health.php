<?php

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    try {
        Event::dispatch(new DiagnosingHealth);
    } catch (Throwable $exception) {
        report($exception);

        return response('Service unavailable', 503, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    return response('OK', 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'no-store',
    ]);
})->name('health');
