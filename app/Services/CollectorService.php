<?php

namespace App\Services;

use App\Models\Cobrador;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectorService
{
    public function __construct(private readonly FinancialAuditService $audit) {}

    /** @param array<string, mixed> $filters @return array{data: list<array<string, mixed>>} */
    public function list(int $companyId, array $filters = []): array
    {
        $rows = DB::table('cobradores')
            ->where('empresa_id', $companyId)
            ->when(! ($filters['incluir_inactivos'] ?? false), fn (Builder $query) => $query
                ->where('estado', Cobrador::STATUS_ACTIVE))
            ->when(trim((string) ($filters['buscar'] ?? '')) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['buscar']);
                $query->where('nombre', 'like', "%{$search}%");
            })
            ->orderBy('nombre')
            ->get();

        return ['data' => $rows->map(fn (object $row): array => $this->format($row))->all()];
    }

    /** @param array{nombre: string} $data @return array<string, mixed> */
    public function create(
        int $companyId,
        User $actor,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor);

        try {
            return DB::transaction(function () use ($companyId, $actor, $data, $ip): array {
                $now = now();
                $id = DB::table('cobradores')->insertGetId([
                    'empresa_id' => $companyId,
                    'nombre' => trim($data['nombre']),
                    'estado' => Cobrador::STATUS_ACTIVE,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $collector = DB::table('cobradores')->where('id', $id)->first();
                $this->audit->record(
                    $companyId,
                    $actor->id,
                    'cobradores',
                    $id,
                    'CREAR',
                    null,
                    (array) $collector,
                    $ip,
                );

                return $this->format($collector);
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName($companyId, trim($data['nombre']), null, $exception);
        }
    }

    /** @param array{nombre: string, estado: string} $data @return array<string, mixed> */
    public function update(
        int $companyId,
        User $actor,
        int $collectorId,
        array $data,
        ?string $ip = null,
    ): array {
        $this->assertActor($companyId, $actor);

        try {
            return DB::transaction(function () use ($companyId, $actor, $collectorId, $data, $ip): array {
                $collector = DB::table('cobradores')
                    ->where('empresa_id', $companyId)
                    ->where('id', $collectorId)
                    ->lockForUpdate()
                    ->first();
                abort_unless($collector, 404, 'Cobrador no encontrado.');

                DB::table('cobradores')->where('id', $collectorId)->update([
                    'nombre' => trim($data['nombre']),
                    'estado' => $data['estado'],
                    'updated_at' => now(),
                ]);
                $updated = DB::table('cobradores')->where('id', $collectorId)->first();
                $this->audit->record(
                    $companyId,
                    $actor->id,
                    'cobradores',
                    $collectorId,
                    'ACTUALIZAR',
                    (array) $collector,
                    (array) $updated,
                    $ip,
                );

                return $this->format($updated);
            }, 3);
        } catch (QueryException $exception) {
            $this->rethrowDuplicateName(
                $companyId,
                trim($data['nombre']),
                $collectorId,
                $exception,
            );
        }
    }

    /** @return array<string, mixed> */
    private function format(object $collector): array
    {
        return [
            'id' => (int) $collector->id,
            'nombre' => $collector->nombre,
            'estado' => $collector->estado,
            'created_at' => $collector->created_at,
            'updated_at' => $collector->updated_at,
        ];
    }

    private function assertActor(int $companyId, User $actor): void
    {
        abort_unless(
            (int) $actor->empresa_id === $companyId
                && $actor->isActive()
                && $actor->hasPermission('PAGOS_REGISTRAR'),
            403,
            'Se requiere el permiso PAGOS_REGISTRAR.',
        );
    }

    private function rethrowDuplicateName(
        int $companyId,
        string $name,
        ?int $ignoreCollectorId,
        QueryException $exception,
    ): never {
        $duplicate = DB::table('cobradores')
            ->where('empresa_id', $companyId)
            ->where('nombre', $name)
            ->when($ignoreCollectorId !== null, fn (Builder $query) => $query
                ->where('id', '!=', $ignoreCollectorId))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe un cobrador con este nombre en la empresa.',
            ]);
        }

        throw $exception;
    }
}
