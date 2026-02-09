<?php

use Illuminate\Support\Facades\Route;
use LaraGrep\Http\Controllers\QueryController;
use LaraGrep\Http\Controllers\RecipeController;

Route::group([
    'prefix' => config('laragrep.route.prefix', 'laragrep'),
    'middleware' => config('laragrep.route.middleware', []),
], function () {
    if (config('laragrep.recipes.enabled', false)) {
        Route::get('/recipes/{id}', [RecipeController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('laragrep.recipes.show');

        Route::post('/recipes/{id}/dispatch', [RecipeController::class, 'dispatch'])
            ->where('id', '[0-9]+')
            ->name('laragrep.recipes.dispatch');
    }

    Route::post('/{scope?}', QueryController::class)->name('laragrep.query');
});
