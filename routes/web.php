<?php

use App\Http\Controllers\Web\AuthController as WebAuthController;
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

        Route::view('/operacion', 'operacion')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA')
            ->name('operacion');

        Route::view('/operacion/pantalla-cliente', 'pantalla-cliente')
            ->middleware('module:MODULO_DESPACHO_MAYORISTA')
            ->name('operacion.pantalla-cliente');

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
        Route::view('/precios-jornada', 'precios-jornada')
            ->middleware('module:MODULO_DESPACHO_MINORISTA_1,MODULO_DESPACHO_MINORISTA_2')
            ->name('precios-jornada');

        Route::view('/tickets-dia', 'tickets-dia')
            ->middleware('module:MODULO_RESUMEN_JORNADA')
            ->name('tickets-dia');
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
            Route::view('/finanzas/movimientos/nuevo', 'finanzas-movimiento')
                ->name('finanzas.movimientos.nuevo');
            Route::view('/compras', 'compras')->name('compras.index');
            Route::view('/compras/nueva', 'compra-form')->name('compras.create');
            Route::get('/finanzas/reportes', [ReportController::class, 'index'])
                ->name('finanzas.reportes');
            Route::get('/finanzas/reportes/{type}/pdf', [ReportController::class, 'pdf'])
                ->name('finanzas.reportes.pdf');
            Route::get('/finanzas/reportes/{type}/imagen', [ReportController::class, 'image'])
                ->name('finanzas.reportes.imagen');
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
