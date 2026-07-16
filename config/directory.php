<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Acceso público temporal al directorio
    |--------------------------------------------------------------------------
    |
    | Facilita las pruebas con las vistas actuales. Debe permanecer desactivado
    | en producción para exigir Sanctum y el permiso TERCEROS_GESTIONAR.
    |
    */
    'public_access' => (bool) env('DIRECTORY_API_PUBLIC', false),
];
