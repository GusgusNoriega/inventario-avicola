<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'menu')->name('menu');
Route::view('/operacion', 'operacion')->name('operacion');
Route::view('/tickets-dia', 'tickets-dia')->name('tickets-dia');
Route::view('/gestion-pesadas', 'gestion-pesadas')->name('gestion-pesadas');
Route::view('/jornada', 'jornada')->name('jornada');
Route::view('/directorio', 'directorio')->name('directorio');
Route::view('/flota', 'flota')->name('flota');
Route::view('/control-javas', 'control-javas')->name('control-javas');
Route::view('/control-javas/inventario', 'control-javas-inventario')->name('control-javas.inventario');
Route::view('/control-javas/devoluciones', 'control-javas-devoluciones')->name('control-javas.devoluciones');
Route::view('/control-javas/trazabilidad', 'control-javas-trazabilidad')->name('control-javas.trazabilidad');
Route::view('/directorio/clientes/{tercero}', 'cliente-detalle')
    ->whereNumber('tercero')
    ->name('clientes.detalle');
Route::view('/directorio/proveedores/{tercero}', 'proveedor-detalle')
    ->whereNumber('tercero')
    ->name('proveedores.detalle');

Route::redirect('/menu.html', '/');
Route::redirect('/index.html', '/operacion');
Route::redirect('/clientes.html', '/directorio');
