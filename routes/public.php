<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

Route::get('health', HealthCheckResultsController::class)->middleware('auth')->name('application.health');
Route::get('health/json', HealthCheckJsonResultsController::class)->middleware('auth')->name('application.health.json');
