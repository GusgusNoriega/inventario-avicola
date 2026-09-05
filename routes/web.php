<?php

use App\Http\Controllers\Web\AuthController as WebAuthController;
use App\Http\Controllers\Web\LiveChickenReceptionJourneyReportController;
use App\Http\Controllers\Web\ProductDispatchAccountStatementReportController;
use App\Http\Controllers\Web\ProductDispatchGeneralReportController;
use App\Http\Controllers\Web\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [WebAuthController::class, 'create'])->name('login');
Route::post('/login', [WebAuthController::class, 'store'])
    ->middleware('throttle:login')
    ->name('login.store');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [WebAuthController::class, 'destroy'])->name('logout');
    Route::view('/mi-cuenta', 'account.profile')->name('account');

    Route::middleware('password.changed')->group(function (): void {
        Route::view('/', 'menu')->name('menu');

        Route::middleware('module.enabled:MODULO_INSTALAR_APLICACION')->group(function (): void {
            Route::view('/instalar', 'install-app')->name('install-app');
            Route::get('/instalar/configurador-impresion', static function () {
                $installerPath = base_path('scripts/Install-SistemaPollosKiosk.ps1');

                abort_unless(is_file($installerPath), 404);

                return response()->download(
                    $installerPath,
                    'Configurar-Impresion-Sistema-Pollos.ps1',
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                );
            })->name('install-app.printer-installer');
        });

        Route::view('/operacion', 'operacion')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA')
            ->name('operacion');

        Route::middleware('module:MODULO_RECEPCION_POLLO_VIVO')->group(function (): void {
            Route::view('/recepcion-pollo-vivo/menu', 'recepcion-pollo-vivo-menu')
                ->name('recepcion-pollo-vivo.menu');
            Route::view('/recepcion-pollo-vivo', 'recepcion-pollo-vivo')
                ->name('recepcion-pollo-vivo');
            Route::view('/recepcion-pollo-vivo/historial', 'recepcion-pollo-vivo-historial')
                ->name('recepcion-pollo-vivo.historial');
            Route::get('/recepcion-pollo-vivo/historial/reporte/pdf', [LiveChickenReceptionJourneyReportController::class, 'pdf'])
                ->name('recepcion-pollo-vivo.historial.report.pdf');
            Route::get('/recepcion-pollo-vivo/historial/reporte/imagenes', [LiveChickenReceptionJourneyReportController::class, 'images'])
                ->name('recepcion-pollo-vivo.historial.report.images');
        });

        Route::view('/operacion/pantalla-cliente', 'pantalla-cliente')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA')
            ->name('operacion.pantalla-cliente');

        Route::view('/despacho-mayorista-2', 'despacho-mayorista-2')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA_2')
            ->name('despacho-mayorista-2');

        Route::view('/despacho-mayorista-2/pantalla-cliente', 'pantalla-cliente-mayorista-2')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA_2')
            ->name('despacho-mayorista-2.pantalla-cliente');

        Route::view('/despacho-minorista', 'despacho-minorista')
            ->middleware('module:MODULO_DESPACHO_MINORISTA_1')
            ->name('despacho-minorista');
        Route::view('/despacho-minorista/pantalla-cliente', 'pantalla-cliente', [
            'customerDisplayMode' => 'retail',
            'customerDisplayTitle' => 'Despacho minorista 1 en vivo',
            'retailStation' => 1,
        ])->middleware('module:MODULO_DESPACHO_MINORISTA_1')
            ->name('despacho-minorista.pantalla-cliente');
        Route::view('/despacho-minorista-2', 'despacho-minorista', [
            'retailStation' => 2,
            'retailTitle' => 'Despacho minorista 2',
            'retailApiBase' => '/despacho-minorista-2',
        ])->middleware('module:MODULO_DESPACHO_MINORISTA_2')
            ->name('despacho-minorista-2');
        Route::view('/despacho-minorista-2/pantalla-cliente', 'pantalla-cliente', [
            'customerDisplayMode' => 'retail',
            'customerDisplayTitle' => 'Despacho minorista 2 en vivo',
            'retailStation' => 2,
        ])->middleware('module:MODULO_DESPACHO_MINORISTA_2')
            ->name('despacho-minorista-2.pantalla-cliente');

        Route::middleware('module:MODULO_DESPACHO_PRODUCTOS')->group(function (): void {
            Route::view('/despacho-productos', 'despacho-productos-menu')
                ->name('despacho-productos.menu');
            Route::view('/despacho-productos/productos', 'despacho-productos-productos')
                ->middleware('permission:PRODUCTOS_DESPACHO_GESTIONAR')
                ->name('despacho-productos.productos');
            Route::view('/despacho-productos/despacho', 'despacho-productos-despacho')
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR')
                ->name('despacho-productos.despacho');
            Route::view('/despacho-productos/clientes', 'despacho-productos-clientes')
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR')
                ->name('despacho-productos.clientes');
            Route::view('/despacho-productos/pagos', 'despacho-productos-pagos')
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR')
                ->name('despacho-productos.pagos');
            Route::view('/despacho-productos/tickets', 'despacho-productos-tickets')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR')
                ->name('despacho-productos.tickets');
            Route::view('/despacho-productos/estado-cuenta', 'despacho-productos-estado-cuenta')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR')
                ->name('despacho-productos.estado-cuenta');
            Route::view('/despacho-productos/reporte-general', 'despacho-productos-reporte-general')
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR')
                ->name('despacho-productos.reporte-general');
            Route::get('/despacho-productos/reporte-general/pdf', [ProductDispatchGeneralReportController::class, 'pdf'])
                ->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR')
                ->name('despacho-productos.reporte-general.pdf');
            Route::get(
                '/despacho-productos/estado-cuenta/pdf',
                [ProductDispatchAccountStatementReportController::class, 'pdf'],
            )->middleware('permission:PRODUCTOS_DESPACHO_TICKETS_GESTIONAR')
                ->name('despacho-productos.estado-cuenta.pdf');
            Route::view(
                '/despacho-productos/configuracion-ticket',
                'despacho-productos-configuracion-ticket',
            )->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR')
                ->name('despacho-productos.configuracion-ticket');
            Route::view('/despacho-productos/pantalla-cliente', 'despacho-productos-pantalla-cliente')
                ->middleware('permission:PRODUCTOS_DESPACHO_DESPACHAR')
                ->name('despacho-productos.pantalla-cliente');
        });

        Route::view('/precios-jornada', 'precios-jornada')
            ->middleware([
                'module.enabled:MODULO_PRECIOS_JORNADA',
                'module:MODULO_DESPACHO_MINORISTA_1,MODULO_DESPACHO_MINORISTA_2',
            ])
            ->name('precios-jornada');

        Route::view('/tickets-dia', 'tickets-dia')
            ->middleware('module:MODULO_RESUMEN_JORNADA')
            ->name('tickets-dia');
        Route::view('/reporte-proveedores', 'reporte-proveedores')
            ->middleware('module:MODULO_REPORTE_PROVEEDORES')
            ->name('reporte-proveedores');
        Route::view('/gestion-pesadas', 'gestion-pesadas')
            ->middleware('module:MODULO_GESTION_PESADAS')
            ->name('gestion-pesadas');
        Route::view('/jornada', 'jornada')
            ->middleware('module:MODULO_JORNADA_PROVEEDORES')
            ->name('jornada');

        Route::middleware('module:MODULO_DIRECTORIO')->group(function (): void {
            Route::view('/directorio', 'directorio')->name('directorio');
            Route::view('/directorio/clientes/{tercero}', 'cliente-detalle')
                ->whereNumber('tercero')
                ->name('clientes.detalle');
            Route::view('/directorio/proveedores/{tercero}', 'proveedor-detalle')
                ->whereNumber('tercero')
                ->name('proveedores.detalle');
        });

        Route::view('/flota', 'flota')
            ->middleware('module:MODULO_FLOTA')
            ->name('flota');

        Route::middleware('module:MODULO_FINANZAS')->group(function (): void {
            Route::view('/finanzas', 'finanzas-menu')->name('finanzas');
            Route::view('/finanzas/saldos', 'finanzas')->name('finanzas.saldos');
            Route::view('/finanzas/entidades', 'finanzas-entidades')->name('finanzas.entidades');
            Route::view('/finanzas/caja-efectivo', 'finanzas-caja-efectivo')
                ->name('finanzas.caja-efectivo');
            Route::view('/finanzas/cobranzas', 'finanzas-cobranzas')
                ->name('finanzas.cobranzas');
            Route::view('/finanzas/movimientos/nuevo', 'finanzas-movimiento')
                ->name('finanzas.movimientos.nuevo');
            Route::view('/finanzas/movimientos', 'finanzas-movimientos')
                ->name('finanzas.movimientos');
            Route::view('/finanzas/gastos', 'finanzas-gastos')
                ->name('finanzas.gastos');
            Route::view('/finanzas/descuentos-clientes', 'finanzas-descuentos-clientes')
                ->name('finanzas.descuentos-clientes');
            Route::view('/finanzas/tickets', 'finanzas-tickets')
                ->name('finanzas.tickets');
            Route::view('/compras', 'compras')->name('compras.index');
            Route::view('/compras/nueva', 'compra-form')->name('compras.create');
            Route::view('/compras/{compra}/editar', 'compra-form')
                ->whereNumber('compra')
                ->name('compras.edit');
            Route::get('/finanzas/reportes', [ReportController::class, 'index'])
                ->name('finanzas.reportes');
            Route::put('/finanzas/reportes/paleta', [ReportController::class, 'updatePalette'])
                ->name('finanzas.reportes.palette.update');
            Route::get('/finanzas/reportes/{type}/pdf', [ReportController::class, 'pdf'])
                ->name('finanzas.reportes.pdf');
            Route::get('/finanzas/reportes/{type}/imagen', [ReportController::class, 'image'])
                ->name('finanzas.reportes.imagen');
            Route::get('/finanzas/reportes/pagos/csv', [ReportController::class, 'paymentsCsv'])
                ->name('finanzas.reportes.pagos.csv');
        });

        Route::middleware('module:MODULO_CONTROL_JAVAS')->group(function (): void {
            Route::view('/control-javas', 'control-javas')->name('control-javas');
            Route::view('/control-javas/inventario', 'control-javas-inventario')
                ->name('control-javas.inventario');
            Route::view('/control-javas/devoluciones', 'control-javas-devoluciones')
                ->name('control-javas.devoluciones');
            Route::view('/control-javas/trazabilidad', 'control-javas-trazabilidad')
                ->name('control-javas.trazabilidad');
        });

        Route::view('/administracion/accesos', 'admin.access-control')
            ->middleware('module:MODULO_USUARIOS_ROLES')
            ->name('admin.access-control');
    });
});

Route::redirect('/menu.html', '/');
Route::redirect('/index.html', '/operacion');
Route::redirect('/clientes.html', '/directorio');
