<?php

use Illuminate\Support\Facades\Route;
use Cslash\SharedSync\Http\Controllers\SharedSyncController;

Route::post('/sharedsync', SharedSyncController::class);
