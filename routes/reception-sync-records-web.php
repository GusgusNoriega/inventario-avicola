<?php

use App\Http\Controllers\Web\ReceptionSyncRecordsWebController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'password.changed', 'module:MODULO_RECEPCION_POLLO_VIVO'])->group(function (): void {
    Route::get('/recepcion-pollo-vivo/sincronizados', [ReceptionSyncRecordsWebController::class, 'index'])
        ->name('reception-sync-records.index');
});
