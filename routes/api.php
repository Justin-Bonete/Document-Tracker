<?php
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFileController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\DocumentWorkflowController;
use App\Http\Controllers\Api\V1\RouteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'audit'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Reference data
        Route::get('document-types', [DocumentTypeController::class, 'index'])->middleware('permission:documents.view');
        Route::post('document-types', [DocumentTypeController::class, 'store'])->middleware('permission:settings.manage');

        // Document registry (Phase 2)
        Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:documents.view');
        Route::post('documents', [DocumentController::class, 'store'])->middleware(['permission:documents.create', 'idempotent']);
        Route::get('documents/{id}', [DocumentController::class, 'show'])->whereNumber('id')->middleware('permission:documents.view');
        Route::patch('documents/{id}', [DocumentController::class, 'update'])->whereNumber('id')->middleware('permission:documents.update');
        Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id')->middleware('permission:documents.void');
        Route::get('documents/{id}/history', [DocumentController::class, 'history'])->whereNumber('id')->middleware('permission:documents.view');

        Route::post('documents/{id}/files', [DocumentFileController::class, 'store'])->whereNumber('id')->middleware('permission:files.upload');
        Route::get('documents/{id}/files/{fileId}/download', [DocumentFileController::class, 'download'])
            ->whereNumber('id')->whereNumber('fileId')->middleware('permission:files.download');

        // Routing & custody (Phase 3)
        Route::get('routes', [RouteController::class, 'index'])->middleware('permission:documents.view');
        Route::get('documents/{id}/routes', [RouteController::class, 'forDocument'])->whereNumber('id')->middleware('permission:documents.view');
        Route::post('documents/{id}/routes', [RouteController::class, 'store'])->whereNumber('id')->middleware(['permission:routes.create', 'idempotent']);
        Route::post('routes/{routeId}/acknowledge', [RouteController::class, 'acknowledge'])->whereNumber('routeId')->middleware('permission:routes.acknowledge');
        Route::post('routes/{routeId}/complete', [RouteController::class, 'complete'])->whereNumber('routeId')->middleware('permission:routes.complete');
        Route::post('routes/{routeId}/return', [RouteController::class, 'returnRoute'])->whereNumber('routeId')->middleware('permission:routes.complete');
        Route::post('routes/{routeId}/forward', [RouteController::class, 'forward'])->whereNumber('routeId')->middleware(['permission:routes.create,routes.complete', 'idempotent']);
        Route::post('routes/{routeId}/cancel', [RouteController::class, 'cancel'])->whereNumber('routeId')->middleware('permission:routes.create');

        // Decisions & lifecycle
        Route::post('documents/{id}/decisions', [DocumentWorkflowController::class, 'decide'])->whereNumber('id')->middleware(['permission:decisions.create', 'idempotent']);
        Route::post('documents/{id}/release', [DocumentWorkflowController::class, 'release'])->whereNumber('id')->middleware('permission:documents.release');
        Route::post('documents/{id}/close', [DocumentWorkflowController::class, 'close'])->whereNumber('id')->middleware('permission:documents.close');
        Route::post('documents/{id}/reopen', [DocumentWorkflowController::class, 'reopen'])->whereNumber('id')->middleware('permission:documents.reopen');

        // Comments
        Route::get('documents/{id}/comments', [CommentController::class, 'index'])->whereNumber('id')->middleware('permission:documents.view');
        Route::post('documents/{id}/comments', [CommentController::class, 'store'])->whereNumber('id')->middleware('permission:comments.create');
    });
});
