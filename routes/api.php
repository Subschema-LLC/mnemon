<?php

use App\Http\Controllers\Api\DocumentUploadController;
use Illuminate\Support\Facades\Route;

// File uploads for scripts and the plugin's /mnemon:upload. The same OAuth
// tokens and wing restrictions as /mcp.
Route::post('/documents', DocumentUploadController::class)
    ->middleware(['auth:api', 'throttle:documents'])
    ->name('api.documents.store');
