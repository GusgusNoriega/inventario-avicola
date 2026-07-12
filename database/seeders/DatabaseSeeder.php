<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $empresaRuc = env('EMPRESA_RUC', '20000000001');
        DB::table('empresas')->updateOrInsert(['ruc' => $empresaRuc], [
            'razon_social' => env('EMPRESA_RAZON_SOCIAL', 'Sistema Pollos'),
            'nombre_comercial' => env('EMPRESA_NOMBRE_COMERCIAL', 'Sistema Pollos'),
            'pais_codigo' => 'PE',
            'moneda' => 'PEN',
            'zona_horaria' => 'America/Lima',
            'hora_corte_operativo' => '21:00:00',
            'sunat_habilitado' => false,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $empresaId = DB::table('empresas')->where('ruc', $empresaRuc)->value('id');

        DB::table('sucursales')->updateOrInsert([
            'empresa_id' => $empresaId,
            'codigo' => 'PRINCIPAL',
        ], [
            'nombre' => 'Sucursal principal',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sucursalId = DB::table('sucursales')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'PRINCIPAL')
            ->value('id');

        collect([
            ['codigo' => 'POLLO_VIVO', 'nombre' => 'Pollo vivo', 'permite_despacho' => true],
            ['codigo' => 'POLLO_MUERTO', 'nombre' => 'Pollo muerto', 'permite_despacho' => true],
            ['codigo' => 'POLLO_PELADO', 'nombre' => 'Pollo pelado', 'permite_despacho' => true],
            ['codigo' => 'POLLO_BENEFICIADO', 'nombre' => 'Pollo beneficiado', 'permite_despacho' => true],
        ])->each(fn (array $tipo) => DB::table('tipos_pollo')->updateOrInsert(
            ['codigo' => $tipo['codigo']],
            [...$tipo, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]
        ));
        $polloVivoId = DB::table('tipos_pollo')
            ->where('codigo', 'POLLO_VIVO')
            ->value('id');
        DB::table('tipos_pollo')
            ->where('codigo', 'POLLO_MUERTO')
            ->update(['precio_fuente_tipo_pollo_id' => $polloVivoId]);

        collect([
            ['codigo' => 'JAVA_700', 'nombre' => 'Java 7.00 kg', 'peso_kg' => 7.000],
            ['codigo' => 'JAVA_690', 'nombre' => 'Java 6.90 kg', 'peso_kg' => 6.900],
        ])->each(fn (array $tipo) => DB::table('tipos_java')->updateOrInsert(
            ['codigo' => $tipo['codigo']],
            [...$tipo, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]
        ));

        DB::table('tipos_bandeja')->updateOrInsert(
            ['codigo' => 'BANDEJA_ESTANDAR'],
            [
                'nombre' => 'Bandeja estandar',
                'peso_kg' => 0,
                'capacidad_aves' => 5,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        collect([
            ['codigo' => 'MACHO_CERRADO', 'nombre' => 'Macho cerrado', 'sexo' => 'MACHO', 'presentacion' => 'CERRADO', 'predeterminado' => true],
            ['codigo' => 'MACHO_ABIERTO', 'nombre' => 'Macho abierto', 'sexo' => 'MACHO', 'presentacion' => 'ABIERTO', 'predeterminado' => false],
            ['codigo' => 'HEMBRA_CERRADA', 'nombre' => 'Hembra cerrada', 'sexo' => 'HEMBRA', 'presentacion' => 'CERRADA', 'predeterminado' => false],
            ['codigo' => 'HEMBRA_ABIERTA', 'nombre' => 'Hembra abierta', 'sexo' => 'HEMBRA', 'presentacion' => 'ABIERTA', 'predeterminado' => false],
        ])->each(fn (array $adjustment) => DB::table('ajustes_peso_minorista')->updateOrInsert(
            ['empresa_id' => $empresaId, 'codigo' => $adjustment['codigo']],
            [
                ...$adjustment,
                'gramos_adicionales' => 0,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));

        collect([
            ['codigo' => 'ALMACEN_1', 'nombre' => 'Almacén 1'],
            ['codigo' => 'ALMACEN_2', 'nombre' => 'Almacén 2'],
        ])->each(fn (array $almacen) => DB::table('almacenes')->updateOrInsert(
            ['sucursal_id' => $sucursalId, 'codigo' => $almacen['codigo']],
            [
                'nombre' => $almacen['nombre'],
                'permite_stock_negativo' => false,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));

        collect([
            ['codigo' => 'BALANZA_1', 'nombre' => 'Balanza 1'],
            ['codigo' => 'BALANZA_2', 'nombre' => 'Balanza 2'],
            [
                'codigo' => 'BALANZA_MINORISTA',
                'nombre' => 'Balanza despacho minorista',
                'modo_conexion' => 'SERIAL',
                'configuracion' => json_encode([
                    'baudRate' => 9600,
                    'dataBits' => 8,
                    'stopBits' => 1,
                    'parity' => 'none',
                    'flowControl' => 'none',
                ], JSON_THROW_ON_ERROR),
            ],
        ])->each(fn (array $balanza) => DB::table('balanzas')->updateOrInsert(
            ['sucursal_id' => $sucursalId, 'codigo' => $balanza['codigo']],
            [
                'nombre' => $balanza['nombre'],
                'modo_conexion' => $balanza['modo_conexion'] ?? null,
                'configuracion' => $balanza['configuracion'] ?? null,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));

        $permissions = collect([
            'DASHBOARD_VER',
            'RECEPCIONES_VER',
            'RECEPCIONES_CREAR',
            'DESPACHOS_VER',
            'TICKETS_DIA_VER',
            'DESPACHOS_CREAR',
            'PESADAS_GESTIONAR',
            'PROGRAMACION_GESTIONAR',
            'RECEPCION_NO_PROGRAMADA',
            'TERCEROS_GESTIONAR',
            'PRECIOS_GESTIONAR',
            'USUARIOS_GESTIONAR',
        ])->mapWithKeys(function (string $code): array {
            $permission = Permission::query()->updateOrCreate(
                ['codigo' => $code],
                ['descripcion' => str($code)->replace('_', ' ')->lower()->ucfirst()]
            );

            return [$code => $permission];
        });

        $administrator = Role::query()->updateOrCreate(
            ['empresa_id' => $empresaId, 'codigo' => 'ADMINISTRADOR'],
            ['nombre' => 'Administrador']
        );
        $administrator->permissions()->sync($permissions->pluck('id'));

        $operator = Role::query()->updateOrCreate(
            ['empresa_id' => $empresaId, 'codigo' => 'OPERADOR'],
            ['nombre' => 'Operador']
        );
        $operator->permissions()->sync(
            $permissions
                ->only([
                    'DASHBOARD_VER',
                    'RECEPCIONES_VER',
                    'RECEPCIONES_CREAR',
                    'DESPACHOS_VER',
                    'TICKETS_DIA_VER',
                    'DESPACHOS_CREAR',
                    'PESADAS_GESTIONAR',
                ])
                ->pluck('id')
        );

        if (env('ADMIN_EMAIL') && env('ADMIN_PASSWORD')) {
            $user = User::query()->updateOrCreate(
                ['email' => str(env('ADMIN_EMAIL'))->lower()->toString()],
                [
                    'empresa_id' => $empresaId,
                    'sucursal_id' => $sucursalId,
                    'nombre' => env('ADMIN_NAME', 'Administrador'),
                    'password_hash' => Hash::make(env('ADMIN_PASSWORD')),
                    'estado' => User::STATUS_ACTIVE,
                ]
            );

            $user->roles()->syncWithoutDetaching([$administrator->id]);
        }
    }
}
