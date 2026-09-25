<?php

use Illuminate\Support\Facades\Route;

// API-only backend: no Blade pages. Web routes stay empty on purpose.
Route::get('/', fn () => response()->json(['service' => 'cms-core-backend', 'api' => '/api/v1']));
