<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'menu')->name('menu');
Route::view('/operacion', 'operacion')->name('operacion');
Route::view('/jornada', 'jornada')->name('jornada');
Route::view('/directorio', 'directorio')->name('directorio');
Route::view('/directorio/clientes/{tercero}', 'cliente-detalle')
    ->whereNumber('tercero')
    ->name('clientes.detalle');
Route::view('/directorio/proveedores/{tercero}', 'proveedor-detalle')
    ->whereNumber('tercero')
    ->name('proveedores.detalle');

Route::redirect('/menu.html', '/');
Route::redirect('/index.html', '/operacion');
Route::redirect('/clientes.html', '/directorio');
