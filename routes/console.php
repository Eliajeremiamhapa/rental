<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled rental jobs
|--------------------------------------------------------------------------
|
| Times are Africa/Dar_es_Salaam (EAT, UTC+3) regardless of the server's own
| clock, so due dates and reminders always fire at the local time an owner
| expects. withoutOverlapping() prevents a slow run from stacking on top of
| itself, and onOneServer() stops a second container from double-sending SMS.
|
*/

Schedule::command('rental:generate-charges')
    ->dailyAt('06:00')
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('rental:check-expiry')
    ->dailyAt('06:00')
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('rental:check-overdue')
    ->dailyAt('06:00')
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('rental:notify-expiry')
    ->dailyAt('06:00')
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('rental:notify-overdue')
    ->dailyAt('06:05')
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping()
    ->onOneServer();

// Housekeeping: drop revoked/expired Sanctum tokens and stale password resets.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping();

Schedule::command('auth:clear-resets')
    ->daily()
    ->timezone('Africa/Dar_es_Salaam')
    ->withoutOverlapping();
