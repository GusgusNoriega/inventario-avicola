<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerHistoryController;
use App\Http\Controllers\Api\V1\DailyDispatchTicketController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\DispatchTicketController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\FinancialAccountController;
use App\Http\Controllers\Api\V1\FinancialCounterpartyController;
use App\Http\Controllers\Api\V1\FinancialEntityController;
use App\Http\Controllers\Api\V1\FinancialMovementController;
use App\Http\Controllers\Api\V1\FinancialQueryController;
use App\Http\Controllers\Api\V1\JavaControlController;
use App\Http\Controllers\Api\V1\JourneyPlanController;
use App\Http\Controllers\Api\V1\OperationCatalogController;
use App\Http\Controllers\Api\V1\ProviderHistoryController;
use App\Http\Controllers\Api\V1\ProviderVehicleController;
use App\Http\Controllers\Api\V1\RetailDispatchController;
use App\Http\Controllers\Api\V1\TicketWeighingManagementController;
use App\Http\Controllers\Api\V1\TruckController;
use App\Models\TerceroRole;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'sistema-pollos-api',
        'timestamp' => now()->toISOString(),
    ]));

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::prefix('finanzas')->middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::middleware('permission:FINANZAS_VER')->group(function (): void {
            Route::get('/entidades', [FinancialEntityController::class, 'index']);
            Route::get('/catalogo', [FinancialQueryController::class, 'catalog']);
            Route::get('/cartera', [FinancialQueryController::class, 'portfolio']);
            Route::get('/saldos', [FinancialQueryController::class, 'balances']);
            Route::get('/trazabilidad', [FinancialQueryController::class, 'trace']);
            Route::get('/movimientos', [FinancialMovementController::class, 'index']);
            Route::get('/movimientos/{movimiento}', [FinancialMovementController::class, 'show'])
                ->whereNumber('movimiento');
            Route::get('/clientes/{tercero}/resumen', [FinancialCounterpartyController::class, 'customer'])
                ->whereNumber('tercero');
            Route::get('/proveedores/{tercero}/resumen', [FinancialCounterpartyController::class, 'provider'])
                ->whereNumber('tercero');
        });

        Route::middleware('permission:CUENTAS_FINANCIERAS_GESTIONAR')->group(function (): void {
            Route::post('/entidades', [FinancialEntityController::class, 'store']);
            Route::put('/entidades/{entidad}', [FinancialEntityController::class, 'update'])
                ->whereNumber('entidad');
            Route::delete('/entidades/{entidad}', [FinancialEntityController::class, 'destroy'])
                ->whereNumber('entidad');
            Route::post('/entidades/{entidad}/cuentas', [FinancialAccountController::class, 'store'])
                ->whereNumber('entidad');
            Route::put('/cuentas/{cuenta}', [FinancialAccountController::class, 'update'])
                ->whereNumber('cuenta');
            Route::delete('/cuentas/{cuenta}', [FinancialAccountController::class, 'destroy'])
                ->whereNumber('cuenta');
        });

        Route::post('/movimientos', [FinancialMovementController::class, 'store'])
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/movimientos/{movimiento}/anular', [FinancialMovementController::class, 'void'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_ANULAR');
    });

    $directoryMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:TERCEROS_GESTIONAR'];
    $operationCatalogMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:DESPACHOS_VER'];
    $dailyTicketsMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:TICKETS_DIA_VER'];
    $operationWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:DESPACHOS_CREAR'];
    $weighingManagementMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:PESADAS_GESTIONAR'];
    $journeyWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:DESPACHOS_CREAR', 'permission:PRECIOS_GESTIONAR'];
    $javaControlReadMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:DESPACHOS_VER'];
    $javaControlWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'permission:DESPACHOS_CREAR'];
    $priceMiddleware = config('directory.public_access')
        ? []
        : ['permission:PRECIOS_GESTIONAR'];

    Route::middleware($operationCatalogMiddleware)->group(function (): void {
        Route::get('/operacion/catalogo', [OperationCatalogController::class, 'index']);
        Route::get('/operacion/jornada', [JourneyPlanController::class, 'show']);

        foreach ([
            'clientes' => TerceroRole::CLIENT,
            'proveedores' => TerceroRole::PROVIDER,
        ] as $path => $role) {
            Route::get("/operacion/{$path}", [DirectoryController::class, 'index'])
                ->defaults('directory_role', $role);
        }
    });
    Route::get('/operacion/tickets-dia', [DailyDispatchTicketController::class, 'index'])
        ->middleware($dailyTicketsMiddleware);
    Route::post('/operacion/tickets', [DispatchTicketController::class, 'store'])
        ->middleware($operationWriteMiddleware);
    Route::get('/despacho-minorista/catalogo', [RetailDispatchController::class, 'catalog'])
        ->middleware($operationCatalogMiddleware);
    Route::put('/despacho-minorista/configuracion', [RetailDispatchController::class, 'updateConfiguration'])
        ->middleware($operationWriteMiddleware);
    Route::post('/despacho-minorista/tickets', [RetailDispatchController::class, 'store'])
        ->middleware($operationWriteMiddleware);
    Route::middleware($weighingManagementMiddleware)->group(function (): void {
        Route::get('/operacion/gestion-pesadas', [TicketWeighingManagementController::class, 'index']);
        Route::get('/operacion/tickets/{ticket}/pesadas', [TicketWeighingManagementController::class, 'show'])
            ->whereNumber('ticket');
        Route::put('/operacion/tickets/{ticket}/transporte', [TicketWeighingManagementController::class, 'updateDelivery'])
            ->whereNumber('ticket');
        Route::put('/operacion/tickets/{ticket}/pesadas/{weighing}', [TicketWeighingManagementController::class, 'update'])
            ->whereNumber(['ticket', 'weighing']);
        Route::delete('/operacion/tickets/{ticket}/pesadas/{weighing}', [TicketWeighingManagementController::class, 'destroy'])
            ->whereNumber(['ticket', 'weighing']);
    });
    Route::put('/operacion/jornada', [JourneyPlanController::class, 'update'])
        ->middleware($journeyWriteMiddleware);
    Route::get('/control-javas', [JavaControlController::class, 'index'])
        ->middleware($javaControlReadMiddleware);
    Route::post('/control-javas/recepciones', [JavaControlController::class, 'store'])
        ->middleware($javaControlWriteMiddleware);
    Route::post('/control-javas/inventario', [JavaControlController::class, 'storeInventory'])
        ->middleware($javaControlWriteMiddleware);
    Route::post('/control-javas/conteo-diario', [JavaControlController::class, 'storeDailyCount'])
        ->middleware($javaControlWriteMiddleware);

    Route::middleware($directoryMiddleware)->group(function () use ($priceMiddleware): void {
        Route::apiResource('camiones', TruckController::class)
            ->parameters(['camiones' => 'camion']);
        Route::apiResource('choferes', DriverController::class)
            ->parameters(['choferes' => 'chofer']);

        Route::get('/clientes/{tercero}/historial', [CustomerHistoryController::class, 'show'])
            ->whereNumber('tercero');
        Route::get('/proveedores/{tercero}/historial', [ProviderHistoryController::class, 'show'])
            ->whereNumber('tercero');
        Route::post('/proveedores/{tercero}/vehiculos', [ProviderVehicleController::class, 'store'])
            ->whereNumber('tercero');
        Route::delete(
            '/proveedores/{tercero}/vehiculos/{association}',
            [ProviderVehicleController::class, 'destroy']
        )->whereNumber(['tercero', 'association']);

        foreach ([
            'clientes' => TerceroRole::CLIENT,
            'proveedores' => TerceroRole::PROVIDER,
        ] as $path => $role) {
            Route::prefix($path)->group(function () use ($priceMiddleware, $role): void {
                Route::get('/', [DirectoryController::class, 'index'])
                    ->defaults('directory_role', $role);
                Route::post('/', [DirectoryController::class, 'store'])
                    ->middleware($priceMiddleware)
                    ->defaults('directory_role', $role);
                Route::patch('/precios/ajuste-global', [DirectoryController::class, 'adjustPrices'])
                    ->middleware($priceMiddleware)
                    ->defaults('directory_role', $role);
                Route::put('/{tercero}', [DirectoryController::class, 'update'])
                    ->whereNumber('tercero')
                    ->middleware($priceMiddleware)
                    ->defaults('directory_role', $role);
                Route::delete('/{tercero}', [DirectoryController::class, 'destroy'])
                    ->whereNumber('tercero')
                    ->defaults('directory_role', $role);
            });
        }
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

        Route::middleware('active')->group(function (): void {
            Route::get('/auth/me', [AuthController::class, 'me']);
        });
    });
});
