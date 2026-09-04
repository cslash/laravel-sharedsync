<?php

use Illuminate\Support\Facades\Route;
use Cslash\SharedSync\Http\Controllers\SharedSyncController;
use Cslash\SharedSync\Http\Controllers\MigrateController;
use Cslash\SharedSync\Http\Middleware\AuthenticateSharedSync;

Route::middleware(AuthenticateSharedSync::class)->group(function () {
    Route::post('/sharedsync', SharedSyncController::class);
});

Route::get('/sharedsync/migrate', MigrateController::class)->name('sharedsync.migrate');
