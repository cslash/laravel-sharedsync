<?php

use Illuminate\Support\Facades\Route;
use Cslash\SharedSync\Http\Controllers\SharedSyncController;
use Cslash\SharedSync\Http\Controllers\MigrateController;

Route::post('/sharedsync', SharedSyncController::class);
Route::get('/sharedsync/migrate', MigrateController::class)->name('sharedsync.migrate');
