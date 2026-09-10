<?php

use App\Http\Controllers\Api\V1\ReceptionSyncController;
use App\Http\Middleware\AuthenticateReceptionSyncToken;
use App\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/recepcion-pollo-vivo/sync')
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class, Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->middleware(['throttle:120,1', AuthenticateReceptionSyncToken::class, 'throttle:api'])
    ->group(function (): void {
        Route::get('/status', [ReceptionSyncController::class, 'status']);
        Route::get('/snapshots', [ReceptionSyncController::class, 'snapshots']);
        Route::post('/snapshots', [ReceptionSyncController::class, 'snapshot'])->middleware('throttle:10,1');
        Route::get('/snapshots/{snapshot}', [ReceptionSyncController::class, 'snapshotPage'])->whereUuid('snapshot');
        Route::delete('/snapshots/{snapshot}', [ReceptionSyncController::class, 'releaseSnapshot'])->whereUuid('snapshot');
        Route::post('/push', [ReceptionSyncController::class, 'push']);
        Route::get('/records', [ReceptionSyncController::class, 'records']);
        Route::get('/records/{record}', [ReceptionSyncController::class, 'record'])->whereUuid('record');
        Route::get('/reports', [ReceptionSyncController::class, 'report']);
    });
