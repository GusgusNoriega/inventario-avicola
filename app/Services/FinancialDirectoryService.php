<?php

namespace App\Services;

use App\Models\User;
use App\Support\FinancialMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialDirectoryService
{
    public function __construct(
        private readonly FinancialAuditService $audit,
        private readonly FinancialAccountBalanceService $balances,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function entities(int $companyId, array $filters): array
    {
        $query = DB::table('entidades_financieras as entidad')
            ->leftJoin('terceros as proveedor', 'proveedor.id', '=', 'entidad.proveedor_id')
            ->where('entidad.empresa_id', $companyId)
            ->select([
                'entidad.*',
                'proveedor.nombre_razon_social as proveedor_nombre',
                'proveedor.numero_documento as proveedor_documento',
            ])
            ->when($filters['tipo'] ?? null, fn (Builder $query, string $type) => $query->where('entidad.tipo', $type))
            ->when($filters['proveedor_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('entidad.proveedor_id', $id))
            ->when($filters['estado'] ?? null, fn (Builder $query, string $status) => $query->where('entidad.estado', $status))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('entidad.razon_social', 'like', "%{$search}%")
                        ->orWhere('entidad.nombre_comercial', 'like', "%{$search}%")
                        ->orWhere('entidad.numero_documento', 'like', "%{$search}%")
                        ->orWhere('proveedor.nombre_razon_social', 'like', "%{$search}%");
                });
            })
            ->orderBy('entidad.tipo')
            ->orderBy('entidad.razon_social');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 50));
        $entityIds = collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $accounts = $this->accountsForEntities($companyId, $entityIds);

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $entity): array => $this->formatEntity($entity, $accounts[(int) $entity->id] ?? []))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @param array<string, mixed> $data */
    public function createEntity(int $companyId, User $actor, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($companyId, $actor, $data, $ip): array {
            $this->assertProviderLink($companyId, $data['tipo'], $data['proveedor_id'] ?? null);
            $now = now();
            $id = DB::table('entidades_financieras')->insertGetId([
                'empresa_id' => $companyId,
                'tipo' => $data['tipo'],
                'proveedor_id' => $data['tipo'] === 'EXTERNA' ? $data['proveedor_id'] : null,
                'tipo_documento' => $data['tipo_documento'] ?? null,
                'numero_documento' => $data['numero_documento'] ?? null,
                'razon_social' => $data['razon_social'],
                'nombre_comercial' => $data['nombre_comercial'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'email' => $data['email'] ?? null,
                'estado' => 'ACTIVO',
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $entity = $this->scopedEntity($companyId, $id);
            $this->audit->record($companyId, $actor->id, 'entidades_financieras', $id, 'CREAR', null, (array) $entity, $ip);

            return $this->formatEntity($entity, []);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateEntity(int $companyId, User $actor, int $entityId, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($companyId, $actor, $entityId, $data, $ip): array {
            $entity = $this->scopedEntity($companyId, $entityId, true);
            $this->assertProviderLink($companyId, $data['tipo'], $data['proveedor_id'] ?? null);

            $hasMovements = $this->entityHasMovements($entityId);

            if (($entity->tipo !== $data['tipo'] || (int) ($entity->proveedor_id ?? 0) !== (int) ($data['proveedor_id'] ?? 0))
                && $hasMovements) {
                throw ValidationException::withMessages([
                    'tipo' => 'No se puede cambiar el tipo o proveedor porque la entidad ya tiene movimientos.',
                ]);
            }
            if ($hasMovements && $this->changed($entity, $data, [
                'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial',
            ])) {
                throw ValidationException::withMessages([
                    'razon_social' => 'No se pueden cambiar los datos identificadores de una entidad con movimientos. Crea otra entidad para conservar la trazabilidad.',
                ]);
            }

            $status = $data['estado'] ?? $entity->estado;
            if ($status === 'INACTIVO' && $entity->estado !== 'INACTIVO') {
                $this->assertEntityCanBeDeactivated($entityId);
            }

            $before = (array) $entity;
            DB::table('entidades_financieras')->where('id', $entityId)->update([
                'tipo' => $data['tipo'],
                'proveedor_id' => $data['tipo'] === 'EXTERNA' ? $data['proveedor_id'] : null,
                'tipo_documento' => $data['tipo_documento'] ?? null,
                'numero_documento' => $data['numero_documento'] ?? null,
                'razon_social' => $data['razon_social'],
                'nombre_comercial' => $data['nombre_comercial'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'email' => $data['email'] ?? null,
                'estado' => $status,
                'updated_at' => now(),
            ]);
            $updated = $this->scopedEntity($companyId, $entityId);
            $this->audit->record($companyId, $actor->id, 'entidades_financieras', $entityId, 'ACTUALIZAR', $before, (array) $updated, $ip);

            return $this->formatEntity($updated, $this->accountsForEntities($companyId, [$entityId])[$entityId] ?? []);
        }, 3);
    }

    public function deactivateEntity(int $companyId, User $actor, int $entityId, ?string $ip): void
    {
        DB::transaction(function () use ($companyId, $actor, $entityId, $ip): void {
            $entity = $this->scopedEntity($companyId, $entityId, true);
            if ($entity->estado === 'INACTIVO') {
                return;
            }

            $this->assertEntityCanBeDeactivated($entityId);

            DB::table('entidades_financieras')->where('id', $entityId)->update([
                'estado' => 'INACTIVO',
                'updated_at' => now(),
            ]);
            $after = (array) $entity;
            $after['estado'] = 'INACTIVO';
            $this->audit->record($companyId, $actor->id, 'entidades_financieras', $entityId, 'DESACTIVAR', (array) $entity, $after, $ip);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createAccount(int $companyId, User $actor, int $entityId, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($companyId, $actor, $entityId, $data, $ip): array {
            $entity = $this->scopedEntity($companyId, $entityId, true);
            if ($entity->estado !== 'ACTIVO') {
                throw ValidationException::withMessages(['entidad' => 'La entidad financiera esta inactiva.']);
            }

            $now = now();
            $id = DB::table('cuentas_financieras')->insertGetId([
                'entidad_financiera_id' => $entityId,
                'tipo' => $data['tipo'],
                'alias' => $data['alias'],
                'banco' => $data['banco'] ?? null,
                'numero_cuenta' => $data['numero_cuenta'] ?? null,
                'cci' => $data['cci'] ?? null,
                'moneda' => $data['moneda'],
                'estado' => 'ACTIVO',
                'created_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $account = $this->scopedAccount($companyId, $id);
            $this->audit->record($companyId, $actor->id, 'cuentas_financieras', $id, 'CREAR', null, (array) $account, $ip);

            return $this->formatAccount($account);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateAccount(int $companyId, User $actor, int $accountId, array $data, ?string $ip): array
    {
        return DB::transaction(function () use ($companyId, $actor, $accountId, $data, $ip): array {
            $account = $this->scopedAccount($companyId, $accountId, true);
            $hasMovements = $this->accountHasMovements($accountId);
            if ($hasMovements && ($account->moneda !== $data['moneda'] || $account->tipo !== $data['tipo'])) {
                throw ValidationException::withMessages([
                    'moneda' => 'No se puede cambiar el tipo o moneda de una cuenta con movimientos.',
                ]);
            }
            if ($hasMovements && $this->changed($account, $data, [
                'alias', 'banco', 'numero_cuenta', 'cci',
            ])) {
                throw ValidationException::withMessages([
                    'alias' => 'No se pueden cambiar los datos identificadores de una cuenta con movimientos. Crea otra cuenta para conservar la trazabilidad.',
                ]);
            }

            $status = $data['estado'] ?? $account->estado;
            if ($status === 'ACTIVO' && $account->entidad_estado !== 'ACTIVO') {
                throw ValidationException::withMessages([
                    'estado' => 'Activa primero la entidad financiera de esta cuenta.',
                ]);
            }
            if ($status === 'INACTIVO' && $account->estado !== 'INACTIVO') {
                $this->assertAccountCanBeDeactivated($account);
            }

            $before = (array) $account;
            DB::table('cuentas_financieras')->where('id', $accountId)->update([
                'tipo' => $data['tipo'],
                'alias' => $data['alias'],
                'banco' => $data['banco'] ?? null,
                'numero_cuenta' => $data['numero_cuenta'] ?? null,
                'cci' => $data['cci'] ?? null,
                'moneda' => $data['moneda'],
                'estado' => $status,
                'updated_at' => now(),
            ]);
            $updated = $this->scopedAccount($companyId, $accountId);
            $this->audit->record($companyId, $actor->id, 'cuentas_financieras', $accountId, 'ACTUALIZAR', $before, (array) $updated, $ip);

            return $this->formatAccount($updated);
        }, 3);
    }

    public function deactivateAccount(int $companyId, User $actor, int $accountId, ?string $ip): void
    {
        DB::transaction(function () use ($companyId, $actor, $accountId, $ip): void {
            $account = $this->scopedAccount($companyId, $accountId, true);
            if ($account->estado === 'INACTIVO') {
                return;
            }

            $this->assertAccountCanBeDeactivated($account);

            DB::table('cuentas_financieras')->where('id', $accountId)->update([
                'estado' => 'INACTIVO',
                'updated_at' => now(),
            ]);
            $after = (array) $account;
            $after['estado'] = 'INACTIVO';
            $this->audit->record($companyId, $actor->id, 'cuentas_financieras', $accountId, 'DESACTIVAR', (array) $account, $after, $ip);
        }, 3);
    }

    private function assertProviderLink(int $companyId, string $type, mixed $providerId): void
    {
        if ($type === 'PROPIA' && $providerId !== null) {
            throw ValidationException::withMessages(['proveedor_id' => 'Una entidad propia no puede vincularse a un proveedor.']);
        }

        if ($type !== 'EXTERNA') {
            return;
        }

        $valid = DB::table('terceros as tercero')
            ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
            ->where('tercero.id', $providerId)
            ->where('tercero.empresa_id', $companyId)
            ->where('tercero.estado', 'ACTIVO')
            ->where('rol.rol', 'PROVEEDOR')
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages(['proveedor_id' => 'El proveedor seleccionado no es valido para esta empresa.']);
        }
    }

    private function scopedEntity(int $companyId, int $entityId, bool $lock = false): object
    {
        $query = DB::table('entidades_financieras')
            ->where('empresa_id', $companyId)
            ->where('id', $entityId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $entity = $query->first();
        abort_unless($entity, 404, 'Entidad financiera no encontrada.');

        return $entity;
    }

    private function scopedAccount(int $companyId, int $accountId, bool $lock = false): object
    {
        $query = DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->where('cuenta.id', $accountId)
            ->select(
                'cuenta.*',
                'entidad.tipo as entidad_tipo',
                'entidad.proveedor_id',
                'entidad.estado as entidad_estado'
            );
        if ($lock) {
            $query->lockForUpdate();
        }

        $account = $query->first();
        abort_unless($account, 404, 'Cuenta financiera no encontrada.');

        return $account;
    }

    private function entityHasMovements(int $entityId): bool
    {
        return DB::table('pagos')->where(function (Builder $query) use ($entityId): void {
            $query->whereIn('cuenta_origen_id', DB::table('cuentas_financieras')->select('id')->where('entidad_financiera_id', $entityId))
                ->orWhereIn('cuenta_destino_id', DB::table('cuentas_financieras')->select('id')->where('entidad_financiera_id', $entityId));
        })->exists();
    }

    private function accountHasMovements(int $accountId): bool
    {
        return DB::table('pagos')
            ->where(fn (Builder $query) => $query
                ->where('cuenta_origen_id', $accountId)
                ->orWhere('cuenta_destino_id', $accountId))
            ->exists();
    }

    /** @param array<string, mixed> $data @param list<string> $fields */
    private function changed(object $stored, array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            $before = $stored->{$field} ?? null;
            $after = $data[$field] ?? null;
            if (($before === null ? '' : (string) $before) !== ($after === null ? '' : (string) $after)) {
                return true;
            }
        }

        return false;
    }

    private function assertEntityCanBeDeactivated(int $entityId): void
    {
        if (DB::table('cuentas_financieras')->where('entidad_financiera_id', $entityId)->where('estado', 'ACTIVO')->exists()) {
            throw ValidationException::withMessages([
                'entidad' => 'Desactiva primero todas las cuentas financieras de esta entidad.',
            ]);
        }
    }

    private function assertAccountCanBeDeactivated(object $account): void
    {
        if ($account->entidad_tipo !== 'PROPIA') {
            return;
        }

        $balance = $this->balances->forAccount((int) $account->id)['saldo'];
        if (FinancialMoney::compare($balance, '0.00') !== 0) {
            throw ValidationException::withMessages([
                'cuenta' => 'La cuenta debe tener saldo cero antes de desactivarse.',
            ]);
        }
    }

    /**
     * @param  list<int>  $entityIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function accountsForEntities(int $companyId, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        return DB::table('cuentas_financieras as cuenta')
            ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
            ->where('entidad.empresa_id', $companyId)
            ->whereIn('cuenta.entidad_financiera_id', $entityIds)
            ->select('cuenta.*', 'entidad.tipo as entidad_tipo', 'entidad.proveedor_id')
            ->orderBy('cuenta.alias')
            ->get()
            ->groupBy('entidad_financiera_id')
            ->map(fn ($items) => $items->map(fn (object $account): array => $this->formatAccount($account))->all())
            ->all();
    }

    /** @param list<array<string, mixed>> $accounts */
    private function formatEntity(object $entity, array $accounts): array
    {
        return [
            'id' => (int) $entity->id,
            'tipo' => $entity->tipo,
            'proveedor_id' => $entity->proveedor_id === null ? null : (int) $entity->proveedor_id,
            'proveedor' => isset($entity->proveedor_nombre) && $entity->proveedor_id !== null ? [
                'id' => (int) $entity->proveedor_id,
                'nombre' => $entity->proveedor_nombre,
                'numero_documento' => $entity->proveedor_documento,
            ] : null,
            'tipo_documento' => $entity->tipo_documento,
            'numero_documento' => $entity->numero_documento,
            'razon_social' => $entity->razon_social,
            'nombre_comercial' => $entity->nombre_comercial,
            'direccion' => $entity->direccion,
            'telefono' => $entity->telefono,
            'email' => $entity->email,
            'estado' => $entity->estado,
            'cuentas' => $accounts,
            'created_at' => $entity->created_at,
            'updated_at' => $entity->updated_at,
        ];
    }

    private function formatAccount(object $account): array
    {
        return [
            'id' => (int) $account->id,
            'entidad_financiera_id' => (int) $account->entidad_financiera_id,
            'tipo' => $account->tipo,
            'alias' => $account->alias,
            'banco' => $account->banco,
            'numero_cuenta' => $account->numero_cuenta,
            'cci' => $account->cci,
            'moneda' => $account->moneda,
            'estado' => $account->estado,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }

    /** @return array<string, int> */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
