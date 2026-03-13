<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\HoldController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/slots/availability', [AvailabilityController::class, 'index']);

    Route::post('/slots/{slot}/hold', [HoldController::class, 'store'])
        ->whereNumber('slot');

    Route::post('/holds/{hold}/confirm', [HoldController::class, 'confirm'])
        ->whereNumber('hold');

    Route::delete('/holds/{hold}', [HoldController::class, 'destroy'])
        ->whereNumber('hold');
});