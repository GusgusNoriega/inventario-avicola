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
            ['codigo' => 'POLLO_PELADO', 'nombre' => 'Pollo pelado', 'permite_despacho' => true],
            ['codigo' => 'POLLO_BENEFICIADO', 'nombre' => 'Pollo beneficiado', 'permite_despacho' => true],
        ])->each(fn (array $tipo) => DB::table('tipos_pollo')->updateOrInsert(
            ['codigo' => $tipo['codigo']],
            [...$tipo, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]
        ));

        collect([
            ['codigo' => 'JAVA_700', 'nombre' => 'Java 7.00 kg', 'peso_kg' => 7.000],
            ['codigo' => 'JAVA_690', 'nombre' => 'Java 6.90 kg', 'peso_kg' => 6.900],
        ])->each(fn (array $tipo) => DB::table('tipos_java')->updateOrInsert(
            ['codigo' => $tipo['codigo']],
            [...$tipo, 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now()]
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
        ])->each(fn (array $balanza) => DB::table('balanzas')->updateOrInsert(
            ['sucursal_id' => $sucursalId, 'codigo' => $balanza['codigo']],
            [
                'nombre' => $balanza['nombre'],
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
            'DESPACHOS_CREAR',
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
                    'DESPACHOS_CREAR',
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
