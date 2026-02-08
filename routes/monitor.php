<?php

use Illuminate\Support\Facades\Route;
use LaraGrep\Http\Controllers\MonitorController;

Route::group([
    'prefix' => config('laragrep.route.prefix', 'laragrep') . '/monitor',
    'middleware' => array_merge(
        ['web'],
        config('laragrep.monitor.middleware', [])
    ),
], function () {
    Route::get('/', [MonitorController::class, 'list'])->name('laragrep.monitor.list');
    Route::get('/overview', [MonitorController::class, 'overview'])->name('laragrep.monitor.overview');
    Route::get('/{id}', [MonitorController::class, 'detail'])->name('laragrep.monitor.detail')
        ->where('id', '[0-9]+');
});
