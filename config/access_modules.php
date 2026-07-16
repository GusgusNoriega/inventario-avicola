<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modulos de acceso
    |--------------------------------------------------------------------------
    |
    | Cada modulo representa una entrada del menu principal. La interfaz de
    | administracion solo expone estos codigos; los permisos tecnicos se
    | mantienen como un detalle de compatibilidad para los endpoints actuales.
    |
    */
    'modules' => [
        'MODULO_DESPACHO_MAYORISTA' => [
            'name' => 'Despacho mayorista',
            'description' => 'Despacho mayorista y todas sus operaciones internas.',
            'path' => '/operacion',
            'technical_permissions' => [
                'DASHBOARD_VER',
                'DESPACHOS_VER',
                'DESPACHOS_CREAR',
            ],
            'legacy_permissions' => ['DESPACHOS_VER', 'DESPACHOS_CREAR'],
        ],
        'MODULO_DESPACHO_MINORISTA_1' => [
            'name' => 'Despacho minorista 1',
            'description' => 'Primer puesto de despacho minorista.',
            'path' => '/despacho-minorista',
            'technical_permissions' => [
                'DESPACHOS_VER',
                'DESPACHOS_CREAR',
            ],
            'legacy_permissions' => ['DESPACHOS_VER', 'DESPACHOS_CREAR'],
        ],
        'MODULO_DESPACHO_MINORISTA_2' => [
            'name' => 'Despacho minorista 2',
            'description' => 'Segundo puesto de despacho minorista.',
            'path' => '/despacho-minorista-2',
            'technical_permissions' => [
                'DESPACHOS_VER',
                'DESPACHOS_CREAR',
            ],
            'legacy_permissions' => ['DESPACHOS_VER', 'DESPACHOS_CREAR'],
        ],
        'MODULO_RESUMEN_JORNADA' => [
            'name' => 'Resumen de la jornada',
            'description' => 'Resumen, consulta e impresion de tickets del dia.',
            'path' => '/tickets-dia',
            'technical_permissions' => ['TICKETS_DIA_VER'],
            'legacy_permissions' => ['TICKETS_DIA_VER'],
        ],
        'MODULO_GESTION_PESADAS' => [
            'name' => 'Gestion de pesadas',
            'description' => 'Consulta, edicion y anulacion de pesadas.',
            'path' => '/gestion-pesadas',
            'technical_permissions' => [
                'DESPACHOS_VER',
                'PESADAS_GESTIONAR',
            ],
            'legacy_permissions' => ['PESADAS_GESTIONAR'],
        ],
        'MODULO_DIRECTORIO' => [
            'name' => 'Clientes y proveedores',
            'description' => 'Directorio, historiales y precios de clientes y proveedores.',
            'path' => '/directorio',
            'technical_permissions' => [
                'TERCEROS_GESTIONAR',
                'PRECIOS_GESTIONAR',
            ],
            'legacy_permissions' => ['TERCEROS_GESTIONAR', 'PRECIOS_GESTIONAR'],
        ],
        'MODULO_FLOTA' => [
            'name' => 'Mi flota y choferes',
            'description' => 'Administracion de camiones y choferes.',
            'path' => '/flota',
            'technical_permissions' => ['TERCEROS_GESTIONAR'],
            'legacy_permissions' => ['TERCEROS_GESTIONAR'],
        ],
        'MODULO_FINANZAS' => [
            'name' => 'Finanzas y tesoreria',
            'description' => 'Finanzas, compras, saldos, cuentas, cobros y pagos.',
            'path' => '/finanzas',
            'technical_permissions' => [
                'FINANZAS_VER',
                'CUENTAS_FINANCIERAS_GESTIONAR',
                'PAGOS_REGISTRAR',
                'PAGOS_ANULAR',
                'SALDOS_AJUSTAR',
                'COMPRAS_VER',
                'COMPRAS_REGISTRAR',
                'COMPRAS_ANULAR',
            ],
            'legacy_permissions' => [
                'FINANZAS_VER',
                'COMPRAS_VER',
                'CUENTAS_FINANCIERAS_GESTIONAR',
            ],
        ],
        'MODULO_CONTROL_JAVAS' => [
            'name' => 'Control de javas y bandejas',
            'description' => 'Panel, inventario, devoluciones y trazabilidad de javas y bandejas.',
            'path' => '/control-javas',
            'technical_permissions' => [
                'DESPACHOS_VER',
                'DESPACHOS_CREAR',
            ],
            'legacy_permissions' => ['DESPACHOS_VER', 'DESPACHOS_CREAR'],
        ],
        'MODULO_JORNADA_PROVEEDORES' => [
            'name' => 'Jornada de proveedores',
            'description' => 'Programacion de recepciones, camiones y precios del dia.',
            'path' => '/jornada',
            'technical_permissions' => [
                'RECEPCIONES_VER',
                'RECEPCIONES_CREAR',
                'PROGRAMACION_GESTIONAR',
                'RECEPCION_NO_PROGRAMADA',
                'DESPACHOS_VER',
                'DESPACHOS_CREAR',
                'TERCEROS_GESTIONAR',
                'PRECIOS_GESTIONAR',
            ],
            'legacy_permissions' => [
                'RECEPCIONES_VER',
                'RECEPCIONES_CREAR',
                'PROGRAMACION_GESTIONAR',
                'RECEPCION_NO_PROGRAMADA',
            ],
        ],
        'MODULO_USUARIOS_ROLES' => [
            'name' => 'Usuarios y roles',
            'description' => 'Administracion de usuarios, roles y accesos.',
            'path' => '/administracion/accesos',
            'technical_permissions' => ['USUARIOS_GESTIONAR'],
            'legacy_permissions' => ['USUARIOS_GESTIONAR'],
        ],
    ],
];
