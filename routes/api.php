<?php

use App\Http\Controllers\Api\V1\AccessModuleController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminRoleController;
use App\Http\Controllers\Api\V1\AdminUserController;
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
use App\Http\Controllers\Api\V1\JourneyPriceController;
use App\Http\Controllers\Api\V1\OperationCatalogController;
use App\Http\Controllers\Api\V1\ProviderHistoryController;
use App\Http\Controllers\Api\V1\ProviderVehicleController;
use App\Http\Controllers\Api\V1\PurchaseController;
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

    Route::prefix('finanzas')->middleware([
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_FINANZAS',
    ])->group(function (): void {
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
        Route::post('/movimientos/{movimiento}/aplicaciones', [FinancialMovementController::class, 'applyProviderPayment'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/movimientos/{movimiento}/anular', [FinancialMovementController::class, 'void'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_ANULAR');
    });

    Route::prefix('compras')->middleware([
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_FINANZAS',
    ])->group(function (): void {
        Route::middleware('permission:COMPRAS_VER')->group(function (): void {
            Route::get('/catalogo', [PurchaseController::class, 'catalog']);
            Route::get('/', [PurchaseController::class, 'index']);
            Route::get('/{compra}', [PurchaseController::class, 'show'])->whereNumber('compra');
        });
        Route::post('/', [PurchaseController::class, 'store'])
            ->middleware('permission:COMPRAS_REGISTRAR');
        Route::post('/{compra}/anular', [PurchaseController::class, 'void'])
            ->whereNumber('compra')
            ->middleware('permission:COMPRAS_ANULAR');
    });

    $directoryMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DIRECTORIO'];
    $fleetMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_FLOTA'];
    $operationCatalogMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA'];
    $journeyReadMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA,MODULO_JORNADA_PROVEEDORES'];
    $retailOneMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MINORISTA_1'];
    $retailTwoMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MINORISTA_2'];
    $dailyTicketsMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_RESUMEN_JORNADA'];
    $operationWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA'];
    $weighingManagementMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_GESTION_PESADAS'];
    $journeyWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_JORNADA_PROVEEDORES'];
    $journeyPriceMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MINORISTA_1,MODULO_DESPACHO_MINORISTA_2'];
    $javaControlReadMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_CONTROL_JAVAS'];
    $javaControlWriteMiddleware = config('directory.public_access')
        ? ['throttle:api']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_CONTROL_JAVAS'];
    $priceMiddleware = config('directory.public_access')
        ? []
        : ['permission:PRECIOS_GESTIONAR'];

    Route::middleware($operationCatalogMiddleware)->group(function (): void {
        Route::get('/operacion/catalogo', [OperationCatalogController::class, 'index']);

        foreach ([
            'clientes' => TerceroRole::CLIENT,
            'proveedores' => TerceroRole::PROVIDER,
        ] as $path => $role) {
            Route::get("/operacion/{$path}", [DirectoryController::class, 'index'])
                ->defaults('directory_role', $role);
        }
    });
    Route::get('/operacion/jornada', [JourneyPlanController::class, 'show'])
        ->middleware($journeyReadMiddleware);
    Route::get('/operacion/tickets-dia', [DailyDispatchTicketController::class, 'index'])
        ->middleware($dailyTicketsMiddleware);
    Route::post('/operacion/tickets', [DispatchTicketController::class, 'store'])
        ->middleware($operationWriteMiddleware);
    Route::get('/despacho-minorista/catalogo', [RetailDispatchController::class, 'catalog'])
        ->middleware($retailOneMiddleware);
    Route::put('/despacho-minorista/configuracion', [RetailDispatchController::class, 'updateConfiguration'])
        ->middleware($retailOneMiddleware);
    Route::post('/despacho-minorista/tickets', [RetailDispatchController::class, 'store'])
        ->middleware($retailOneMiddleware);
    Route::prefix('despacho-minorista-2')
        ->group(function () use ($retailTwoMiddleware): void {
            Route::get('/catalogo', [RetailDispatchController::class, 'catalog'])
                ->defaults('retail_station', 2)
                ->middleware($retailTwoMiddleware);
            Route::put('/configuracion', [RetailDispatchController::class, 'updateConfiguration'])
                ->defaults('retail_station', 2)
                ->middleware($retailTwoMiddleware);
            Route::post('/tickets', [RetailDispatchController::class, 'store'])
                ->defaults('retail_station', 2)
                ->middleware($retailTwoMiddleware);
        });
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
    Route::get('/operacion/precios-jornada', [JourneyPriceController::class, 'show'])
        ->middleware($journeyPriceMiddleware);
    Route::put('/operacion/precios-jornada', [JourneyPriceController::class, 'update'])
        ->middleware($journeyPriceMiddleware);
    Route::get('/control-javas', [JavaControlController::class, 'index'])
        ->middleware($javaControlReadMiddleware);
    Route::post('/control-javas/recepciones', [JavaControlController::class, 'store'])
        ->middleware($javaControlWriteMiddleware);
    Route::post('/control-javas/inventario', [JavaControlController::class, 'storeInventory'])
        ->middleware($javaControlWriteMiddleware);
    Route::post('/control-javas/conteo-diario', [JavaControlController::class, 'storeDailyCount'])
        ->middleware($javaControlWriteMiddleware);

    Route::middleware($fleetMiddleware)->group(function (): void {
        Route::apiResource('camiones', TruckController::class)
            ->parameters(['camiones' => 'camion']);
        Route::apiResource('choferes', DriverController::class)
            ->parameters(['choferes' => 'chofer']);
    });

    Route::middleware($directoryMiddleware)->group(function () use ($priceMiddleware): void {
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

            Route::get('/account', [AccountController::class, 'show']);
            Route::put('/account', [AccountController::class, 'update']);
            Route::put('/account/password', [AccountController::class, 'password']);

            Route::prefix('admin')->middleware([
                'password.changed',
                'module:MODULO_USUARIOS_ROLES',
            ])->group(function (): void {
                Route::get('/modules', [AccessModuleController::class, 'index']);

                Route::get('/roles', [AdminRoleController::class, 'index']);
                Route::post('/roles', [AdminRoleController::class, 'store']);
                Route::get('/roles/{role}', [AdminRoleController::class, 'show'])
                    ->whereNumber('role');
                Route::put('/roles/{role}', [AdminRoleController::class, 'update'])
                    ->whereNumber('role');
                Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy'])
                    ->whereNumber('role');

                Route::get('/users', [AdminUserController::class, 'index']);
                Route::post('/users', [AdminUserController::class, 'store']);
                Route::get('/users/{user}', [AdminUserController::class, 'show'])
                    ->whereNumber('user');
                Route::put('/users/{user}', [AdminUserController::class, 'update'])
                    ->whereNumber('user');
                Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])
                    ->whereNumber('user');
                Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])
                    ->whereNumber('user');
                Route::post('/users/{user}/revoke-sessions', [AdminUserController::class, 'revokeSessions'])
                    ->whereNumber('user');
            });
        });
    });
});
