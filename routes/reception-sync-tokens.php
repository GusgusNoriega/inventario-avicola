<?php

use App\Http\Controllers\Web\ReceptionSyncTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'password.changed', 'module:MODULO_RECEPCION_POLLO_VIVO', 'cache.headers:no_store;private'])
    ->prefix('recepcion-pollo-vivo/dispositivos')->name('reception-sync-tokens.')
    ->group(function (): void {
        Route::get('/', [ReceptionSyncTokenController::class, 'index'])->name('index');
        Route::get('/documentacion', [ReceptionSyncTokenController::class, 'documentation'])->name('documentation');
        Route::get('/openapi', [ReceptionSyncTokenController::class, 'openapi'])->name('openapi');
        Route::post('/', [ReceptionSyncTokenController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::delete('/{token}', [ReceptionSyncTokenController::class, 'destroy'])->whereNumber('token')->name('destroy');
    });
