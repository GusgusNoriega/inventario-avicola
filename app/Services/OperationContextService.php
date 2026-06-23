<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OperationContextService
{
    public function companyId(Request $request): int
    {
        if ($request->user()) {
            return (int) $request->user()->empresa_id;
        }

        abort_unless(config('directory.public_access'), 401);

        $companyId = Empresa::query()
            ->where('estado', Empresa::STATUS_ACTIVE)
            ->orderBy('id')
            ->value('id');

        abort_unless($companyId, 503, 'No existe una empresa activa configurada.');

        return (int) $companyId;
    }

    /**
     * @return object{id: int, empresa_id: int, codigo: string, nombre: string, zona_horaria: string}
     */
    public function branch(Request $request): object
    {
        $companyId = $this->companyId($request);
        $branchQuery = DB::table('sucursales')
            ->where('empresa_id', $companyId)
            ->where('estado', 'ACTIVO');

        if ($request->user()?->sucursal_id) {
            $branchQuery->where('id', $request->user()->sucursal_id);
        }

        $branch = $branchQuery->orderBy('id')->first([
            'id',
            'empresa_id',
            'codigo',
            'nombre',
            'zona_horaria',
        ]);

        abort_unless($branch, 503, 'No existe una sucursal activa configurada.');

        return $branch;
    }

    public function actor(Request $request, int $branchId): User
    {
        if ($request->user()) {
            return $request->user();
        }

        $companyId = $this->companyId($request);
        $actor = User::query()->firstOrCreate(
            [
                'empresa_id' => $companyId,
                'email' => 'sistema-operacion@local.invalid',
            ],
            [
                'sucursal_id' => $branchId,
                'nombre' => 'Sistema operación local',
                'password_hash' => Hash::make(Str::random(64)),
                'estado' => User::STATUS_INACTIVE,
            ]
        );

        if (! $actor->sucursal_id) {
            $actor->update(['sucursal_id' => $branchId]);
        }

        return $actor;
    }
}
