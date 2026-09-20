<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

/**
 * These load after routes/web.php, so registering them on `health` would
 * replace the public uptime endpoint defined there with an auth-gated page --
 * a monitor pointed at /health would follow a redirect to the login form and
 * never see a database outage. They live under health/dashboard instead.
 */
Route::get('health/dashboard', HealthCheckResultsController::class)->middleware('auth')->name('application.health');
Route::get('health/dashboard/json', HealthCheckJsonResultsController::class)->middleware('auth')->name('application.health.json');
