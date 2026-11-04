<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bps:refresh-variable-index')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('bps:check-canonical-indicators')->dailyAt('03:00')->timezone('Asia/Jakarta')->withoutOverlapping();
