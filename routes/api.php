<?php
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFileController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'audit'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Reference data
        Route::get('document-types', [DocumentTypeController::class, 'index'])->middleware('permission:documents.view');
        Route::post('document-types', [DocumentTypeController::class, 'store'])->middleware('permission:settings.manage');

        // Document registry
        Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:documents.view');
        Route::post('documents', [DocumentController::class, 'store'])->middleware(['permission:documents.create', 'idempotent']);
        Route::get('documents/{id}', [DocumentController::class, 'show'])->whereNumber('id')->middleware('permission:documents.view');
        Route::patch('documents/{id}', [DocumentController::class, 'update'])->whereNumber('id')->middleware('permission:documents.update');
        Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id')->middleware('permission:documents.void');
        Route::get('documents/{id}/history', [DocumentController::class, 'history'])->whereNumber('id')->middleware('permission:documents.view');

        // Files
        Route::post('documents/{id}/files', [DocumentFileController::class, 'store'])->whereNumber('id')->middleware('permission:files.upload');
        Route::get('documents/{id}/files/{fileId}/download', [DocumentFileController::class, 'download'])
            ->whereNumber('id')->whereNumber('fileId')->middleware('permission:files.download');
    });
});
