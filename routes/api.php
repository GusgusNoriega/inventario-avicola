<?php

use App\Http\Controllers\Api\V1\AccessModuleController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminRoleController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\CollectorController;
use App\Http\Controllers\Api\V1\CompanyExpenseController;
use App\Http\Controllers\Api\V1\CustomerDiscountController;
use App\Http\Controllers\Api\V1\CustomerHistoryController;
use App\Http\Controllers\Api\V1\DailyDispatchTicketController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\DispatchProductController;
use App\Http\Controllers\Api\V1\DispatchTicketController;
use App\Http\Controllers\Api\V1\DispatchTicketVoidController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\FinancialAccountController;
use App\Http\Controllers\Api\V1\FinancialCounterpartyController;
use App\Http\Controllers\Api\V1\FinancialEntityController;
use App\Http\Controllers\Api\V1\FinancialMovementController;
use App\Http\Controllers\Api\V1\FinancialQueryController;
use App\Http\Controllers\Api\V1\FinancialTicketController;
use App\Http\Controllers\Api\V1\JavaControlController;
use App\Http\Controllers\Api\V1\JourneyPlanController;
use App\Http\Controllers\Api\V1\JourneyPriceController;
use App\Http\Controllers\Api\V1\LiveChickenReceptionController;
use App\Http\Controllers\Api\V1\LiveChickenReceptionDispatchTicketController;
use App\Http\Controllers\Api\V1\LiveChickenReceptionHistoryController;
use App\Http\Controllers\Api\V1\ManualCustomerDebtController;
use App\Http\Controllers\Api\V1\OperationCatalogController;
use App\Http\Controllers\Api\V1\ProductDispatchOperationController;
use App\Http\Controllers\Api\V1\ProviderHistoryController;
use App\Http\Controllers\Api\V1\ProviderReportController;
use App\Http\Controllers\Api\V1\ProviderVehicleController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RetailDispatchController;
use App\Http\Controllers\Api\V1\TicketWeighingManagementController;
use App\Http\Controllers\Api\V1\TruckController;
use App\Http\Controllers\Api\V1\WholesaleTwoDispatchTicketController;
use App\Http\Controllers\Api\V1\WholesaleTwoWeightAdjustmentController;
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
            Route::get('/cobranzas/catalogo', [CollectionController::class, 'catalog']);
            Route::get('/cobranzas', [CollectionController::class, 'index']);
            Route::get('/cobranzas/{cobranza}', [CollectionController::class, 'show'])
                ->whereNumber('cobranza');
            Route::get('/cobradores', [CollectorController::class, 'index']);
            Route::get('/caja-efectivo/catalogo', [CashRegisterController::class, 'catalog']);
            Route::get('/caja-efectivo', [CashRegisterController::class, 'index']);
            Route::get('/entidades', [FinancialEntityController::class, 'index']);
            Route::get('/catalogo', [FinancialQueryController::class, 'catalog']);
            Route::get('/cartera', [FinancialQueryController::class, 'portfolio']);
            Route::get('/saldos', [FinancialQueryController::class, 'balances']);
            Route::get('/trazabilidad', [FinancialQueryController::class, 'trace']);
            Route::get('/movimientos', [FinancialMovementController::class, 'index']);
            Route::get('/movimientos/{movimiento}', [FinancialMovementController::class, 'show'])
                ->whereNumber('movimiento');
            Route::get('/deudas-clientes', [ManualCustomerDebtController::class, 'index']);
            Route::get('/deudas-clientes/{deuda}', [ManualCustomerDebtController::class, 'show'])
                ->whereNumber('deuda');
            Route::get('/descuentos-clientes', [CustomerDiscountController::class, 'index']);
            Route::get('/tickets', [FinancialTicketController::class, 'index']);
            Route::get('/tickets/clientes', [FinancialTicketController::class, 'clients']);
            Route::get('/tickets/{ticket}/pesadas', [TicketWeighingManagementController::class, 'showForFinance'])
                ->whereNumber('ticket');
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
        Route::post('/cobradores', [CollectorController::class, 'store'])
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::put('/cobradores/{cobrador}', [CollectorController::class, 'update'])
            ->whereNumber('cobrador')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/cobranzas', [CollectionController::class, 'store'])
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/cobranzas/{cobranza}/asignaciones', [CollectionController::class, 'assignPending'])
            ->whereNumber('cobranza')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::put('/cobranzas/{cobranza}/recepcion-caja', [CollectionController::class, 'updateCashReceipt'])
            ->whereNumber('cobranza')
            ->middleware(['permission:PAGOS_REGISTRAR', 'permission:SALDOS_AJUSTAR']);
        Route::post('/cobranzas/{cobranza}/anular', [CollectionController::class, 'void'])
            ->whereNumber('cobranza')
            ->middleware('permission:PAGOS_ANULAR');
        Route::post('/caja-efectivo', [CashRegisterController::class, 'store'])
            ->middleware(['permission:PAGOS_REGISTRAR', 'permission:SALDOS_AJUSTAR']);
        Route::put('/caja-efectivo/{movimientoCaja}', [CashRegisterController::class, 'update'])
            ->whereNumber('movimientoCaja')
            ->middleware(['permission:PAGOS_REGISTRAR', 'permission:SALDOS_AJUSTAR']);
        Route::delete('/caja-efectivo/{movimientoCaja}', [CashRegisterController::class, 'destroy'])
            ->whereNumber('movimientoCaja')
            ->middleware('permission:PAGOS_ANULAR');
        Route::put('/movimientos/{movimiento}', [FinancialMovementController::class, 'update'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/movimientos/{movimiento}/aplicaciones', [FinancialMovementController::class, 'applyProviderPayment'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/movimientos/{movimiento}/anular', [FinancialMovementController::class, 'void'])
            ->whereNumber('movimiento')
            ->middleware('permission:PAGOS_ANULAR');
        Route::post('/deudas-clientes', [ManualCustomerDebtController::class, 'store'])
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/deudas-clientes/{deuda}', [ManualCustomerDebtController::class, 'update'])
            ->whereNumber('deuda')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/deudas-clientes/{deuda}/anular', [ManualCustomerDebtController::class, 'void'])
            ->whereNumber('deuda')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/descuentos-clientes', [CustomerDiscountController::class, 'store'])
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/descuentos-clientes/{descuento}', [CustomerDiscountController::class, 'update'])
            ->whereNumber('descuento')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/descuentos-clientes/{descuento}/anular', [CustomerDiscountController::class, 'void'])
            ->whereNumber('descuento')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/tickets/ajustar-precios', [FinancialTicketController::class, 'bulkAdjust'])
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/tickets/{ticket}/precios', [FinancialTicketController::class, 'updatePrices'])
            ->whereNumber('ticket')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/tickets/{ticket}/cliente', [FinancialTicketController::class, 'updateClient'])
            ->whereNumber('ticket')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/tickets/{ticket}/fecha-hora', [FinancialTicketController::class, 'updateDateTime'])
            ->whereNumber('ticket')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::put('/tickets/{ticket}/pesadas/{weighing}', [TicketWeighingManagementController::class, 'updateForFinance'])
            ->whereNumber(['ticket', 'weighing'])
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/tickets/{ticket}/anular', [FinancialTicketController::class, 'void'])
            ->whereNumber('ticket')
            ->middleware('permission:SALDOS_AJUSTAR');
        Route::post('/tickets/{ticket}/restablecer', [FinancialTicketController::class, 'restore'])
            ->whereNumber('ticket')
            ->middleware('permission:SALDOS_AJUSTAR');

        Route::middleware('permission:FINANZAS_VER')->group(function (): void {
            Route::get('/gastos/catalogo', [CompanyExpenseController::class, 'catalog']);
            Route::get('/gastos', [CompanyExpenseController::class, 'index']);
            Route::get('/gastos/{gasto}', [CompanyExpenseController::class, 'show'])
                ->whereNumber('gasto');
        });
        Route::post('/gastos', [CompanyExpenseController::class, 'store'])
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::put('/gastos/{gasto}', [CompanyExpenseController::class, 'update'])
            ->whereNumber('gasto')
            ->middleware('permission:PAGOS_REGISTRAR');
        Route::post('/gastos/{gasto}/anular', [CompanyExpenseController::class, 'void'])
            ->whereNumber('gasto')
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
        Route::put('/{compra}', [PurchaseController::class, 'update'])
            ->whereNumber('compra')
            ->middleware('permission:COMPRAS_REGISTRAR');
        Route::post('/{compra}/anular', [PurchaseController::class, 'void'])
            ->whereNumber('compra')
            ->middleware('permission:COMPRAS_ANULAR');
    });

    $directoryMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DIRECTORIO']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DIRECTORIO'];
    $fleetMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_FLOTA']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_FLOTA'];
    $operationCatalogMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DESPACHO_MAYORISTA']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA'];
    $journeyReadMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DESPACHO_MAYORISTA,MODULO_JORNADA_PROVEEDORES']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA,MODULO_JORNADA_PROVEEDORES'];
    $retailOneMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DESPACHO_MINORISTA_1']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MINORISTA_1'];
    $retailTwoMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DESPACHO_MINORISTA_2']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MINORISTA_2'];
    $dailyTicketsMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_RESUMEN_JORNADA']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_RESUMEN_JORNADA'];
    $providerReportMiddleware = [
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_REPORTE_PROVEEDORES',
    ];
    $operationWriteMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_DESPACHO_MAYORISTA']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_DESPACHO_MAYORISTA'];
    $wholesaleTwoMiddleware = [
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_DESPACHO_MAYORISTA_2',
    ];
    $productDispatchMiddleware = [
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_DESPACHO_PRODUCTOS',
    ];
    $weighingManagementMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_GESTION_PESADAS']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_GESTION_PESADAS'];
    $journeyWriteMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_JORNADA_PROVEEDORES']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_JORNADA_PROVEEDORES'];
    $journeyPriceManagementMiddleware = config('directory.public_access')
        ? [
            'throttle:api',
            'module.enabled:MODULO_DESPACHO_MINORISTA_1,MODULO_DESPACHO_MINORISTA_2',
            'module.enabled:MODULO_PRECIOS_JORNADA',
        ]
        : [
            'auth:sanctum',
            'active',
            'password.changed',
            'module:MODULO_DESPACHO_MINORISTA_1,MODULO_DESPACHO_MINORISTA_2',
            'module.enabled:MODULO_PRECIOS_JORNADA',
        ];
    $javaControlReadMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_CONTROL_JAVAS']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_CONTROL_JAVAS'];
    $javaControlWriteMiddleware = config('directory.public_access')
        ? ['throttle:api', 'module.enabled:MODULO_CONTROL_JAVAS']
        : ['auth:sanctum', 'active', 'password.changed', 'module:MODULO_CONTROL_JAVAS'];
    $javaBalanceAdjustmentMiddleware = [
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_CONTROL_JAVAS',
    ];
    $liveChickenReceptionMiddleware = [
        'auth:sanctum',
        'active',
        'password.changed',
        'module:MODULO_RECEPCION_POLLO_VIVO',
    ];
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
    Route::get('/operacion/tickets-dia/impresion', [DailyDispatchTicketController::class, 'printable'])
        ->middleware([
            'auth:sanctum',
            'active',
            'password.changed',
            'module:MODULO_RESUMEN_JORNADA',
        ]);
    Route::get('/operacion/tickets-dia', [DailyDispatchTicketController::class, 'index'])
        ->middleware($dailyTicketsMiddleware);
    Route::get('/operacion/reporte-proveedores', [ProviderReportController::class, 'index'])
        ->middleware($providerReportMiddleware);
    Route::post('/operacion/tickets/{ticket}/anular', DispatchTicketVoidController::class)
        ->whereNumber('ticket')
        ->middleware([
            'auth:sanctum',
            'active',
            'password.changed',
            'module:MODULO_RESUMEN_JORNADA,MODULO_GESTION_PESADAS',
        ]);
    Route::post('/operacion/tickets', [DispatchTicketController::class, 'store'])
        ->middleware($operationWriteMiddleware);
    Route::prefix('despacho-mayorista-2')
        ->middleware($wholesaleTwoMiddleware)
        ->group(function (): void {
            Route::get('/catalogo', [OperationCatalogController::class, 'index'])
                ->defaults('wholesale_two_catalog', true);

            foreach ([
                'clientes' => TerceroRole::CLIENT,
                'proveedores' => TerceroRole::PROVIDER,
            ] as $path => $role) {
                Route::get("/{$path}", [DirectoryController::class, 'index'])
                    ->defaults('directory_role', $role);
            }

            Route::get('/jornada', [JourneyPlanController::class, 'show']);
            Route::get('/configuracion-mermas', [WholesaleTwoWeightAdjustmentController::class, 'show']);
            Route::put('/configuracion-mermas', [WholesaleTwoWeightAdjustmentController::class, 'update']);
            Route::post('/tickets', [WholesaleTwoDispatchTicketController::class, 'store']);
        });
    Route::prefix('productos-despacho')
        ->middleware($productDispatchMiddleware)
        ->group(function (): void {
            Route::get('/', [DispatchProductController::class, 'index'])
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR');
            Route::post('/', [DispatchProductController::class, 'store'])
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR');
            Route::get('/{producto}/imagen', [DispatchProductController::class, 'image'])
                ->whereNumber('producto');
            Route::get(
                '/{producto}/variaciones/{variacion}/imagen',
                [DispatchProductController::class, 'variationImage'],
            )->whereNumber(['producto', 'variacion']);
            Route::get('/{producto}', [DispatchProductController::class, 'show'])
                ->whereNumber('producto')
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR');
            Route::put('/{producto}', [DispatchProductController::class, 'update'])
                ->whereNumber('producto')
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR');
            Route::delete('/{producto}', [DispatchProductController::class, 'destroy'])
                ->whereNumber('producto')
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR');
        });
    Route::prefix('despacho-productos')
        ->middleware($productDispatchMiddleware)
        ->group(function (): void {
            Route::get('/catalogo', [ProductDispatchOperationController::class, 'catalog']);
            Route::get('/tickets', [ProductDispatchOperationController::class, 'index'])
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR');
            Route::put('/tickets/{ticket}', [ProductDispatchOperationController::class, 'update'])
                ->whereNumber('ticket')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR');
            Route::delete('/tickets/{ticket}', [ProductDispatchOperationController::class, 'destroy'])
                ->whereNumber('ticket')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR');
            Route::put('/configuracion', [ProductDispatchOperationController::class, 'updateConfiguration'])
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR');
            Route::post('/tickets', [ProductDispatchOperationController::class, 'store'])
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR');
            Route::get('/tickets/{ticket}', [ProductDispatchOperationController::class, 'show'])
                ->whereNumber('ticket')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR');
        });
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
            Route::get('/precios-jornada', [JourneyPriceController::class, 'show'])
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
        ->middleware($journeyPriceManagementMiddleware);
    Route::put('/operacion/precios-jornada', [JourneyPriceController::class, 'update'])
        ->middleware($journeyPriceManagementMiddleware);
    Route::put('/operacion/precios-jornada/mensaje-ticket', [JourneyPriceController::class, 'updateTicketMessage'])
        ->middleware($journeyPriceManagementMiddleware);
    Route::put('/operacion/precios-jornada/titulo-ticket', [JourneyPriceController::class, 'updateTicketTitle'])
        ->middleware($journeyPriceManagementMiddleware);
    Route::get('/control-javas', [JavaControlController::class, 'index'])
        ->middleware($javaControlReadMiddleware);
    Route::post('/control-javas/recepciones', [JavaControlController::class, 'store'])
        ->middleware($javaControlWriteMiddleware);
    Route::patch('/control-javas/clientes/{cliente}/saldo', [JavaControlController::class, 'updateClientBalance'])
        ->whereNumber('cliente')
        ->middleware($javaBalanceAdjustmentMiddleware);
    Route::post('/control-javas/inventario', [JavaControlController::class, 'storeInventory'])
        ->middleware($javaControlWriteMiddleware);
    Route::post('/control-javas/conteo-diario', [JavaControlController::class, 'storeDailyCount'])
        ->middleware($javaControlWriteMiddleware);

    Route::prefix('recepcion-pollo-vivo')
        ->middleware($liveChickenReceptionMiddleware)
        ->group(function (): void {
            Route::get('/', [LiveChickenReceptionController::class, 'index']);
            Route::get('/historial', LiveChickenReceptionHistoryController::class);
            Route::put('/configuracion', [LiveChickenReceptionController::class, 'updateConfiguration']);
            Route::post('/pesadas', [LiveChickenReceptionController::class, 'store']);
            Route::post('/tickets', [LiveChickenReceptionDispatchTicketController::class, 'store']);
            Route::get('/tickets/{ticket}', [LiveChickenReceptionDispatchTicketController::class, 'show'])
                ->whereNumber('ticket');
            Route::put('/tickets/{ticket}', [LiveChickenReceptionDispatchTicketController::class, 'update'])
                ->whereNumber('ticket');
            Route::delete('/tickets/{ticket}/pesadas/{weighing}', [LiveChickenReceptionDispatchTicketController::class, 'destroyWeighing'])
                ->whereNumber(['ticket', 'weighing']);
            Route::put('/pesadas/{weighing}', [LiveChickenReceptionController::class, 'update'])
                ->whereNumber('weighing');
            Route::delete('/pesadas/{weighing}', [LiveChickenReceptionController::class, 'destroy'])
                ->whereNumber('weighing');
        });

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
        Route::get('/proveedores/{tercero}/vehiculos-disponibles', [ProviderVehicleController::class, 'available'])
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
