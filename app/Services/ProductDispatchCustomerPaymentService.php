<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\User;
use App\Support\FinancialMoney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductDispatchCustomerPaymentService
{
    public function __construct(
        private readonly FinancialMovementService $movements,
        private readonly FinancialAuditService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function catalog(int $companyId, object $branch): array
    {
        $currency = strtoupper(trim((string) DB::table('empresas')->where('id', $companyId)->value('moneda')));

        return [
            'methods' => DB::table('metodos_pago')->where('estado', 'ACTIVO')->orderBy('nombre')
                ->get(['id', 'codigo', 'nombre'])
                ->map(fn (object $method): array => [
                    'id' => (int) $method->id,
                    'code' => $method->codigo,
                    'name' => $method->nombre,
                ])->all(),
            'accounts' => DB::table('cuentas_financieras as cuenta')
                ->join('entidades_financieras as entidad', 'entidad.id', '=', 'cuenta.entidad_financiera_id')
                ->where('entidad.empresa_id', $companyId)
                ->where('entidad.tipo', 'PROPIA')->where('entidad.estado', 'ACTIVO')
                ->where('cuenta.estado', 'ACTIVO')->orderBy('cuenta.alias')
                ->get(['cuenta.id', 'cuenta.alias', 'cuenta.moneda'])
                ->map(fn (object $account): array => [
                    'id' => (int) $account->id,
                    'name' => $account->alias,
                    'currency' => $account->moneda,
                ])->all(),
            'currency' => preg_match('/\A[A-Z]{3}\z/', $currency) ? $currency : 'PEN',
            'now' => CarbonImmutable::now($this->timezone($branch))->format('Y-m-d\TH:i'),
            'branch' => ['id' => (int) $branch->id, 'name' => $branch->nombre],
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function listing(int $companyId, object $branch, array $filters): array
    {
        $query = $this->query($companyId, (int) $branch->id)
            ->where('product_payment.estado', Pago::STATUS_REGISTERED)
            ->where('payment.estado', Pago::STATUS_REGISTERED);
        if (! empty($filters['date_from'])) {
            $query->where('payment.fecha_hora', '>=', $this->databaseDate($filters['date_from'].'T00:00', $branch));
        }
        if (! empty($filters['date_to'])) {
            $end = CarbonImmutable::parse($filters['date_to'], $this->timezone($branch))
                ->addDay()->startOfDay()->setTimezone($this->databaseTimezone())->toDateTimeString();
            $query->where('payment.fecha_hora', '<', $end);
        }
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('client.nombre_razon_social', 'like', '%'.$search.'%')
                    ->orWhere('client.numero_documento', 'like', '%'.$search.'%')
                    ->orWhere('payment.referencia', 'like', '%'.$search.'%')
                    ->orWhere('payment.observaciones', 'like', '%'.$search.'%')
                    ->orWhere('method.nombre', 'like', '%'.$search.'%');
                if (preg_match('/^(?:PCL-)?0*(\d+)$/i', $search, $matches)) {
                    $query->orWhere('product_payment.id', (int) $matches[1]);
                }
            });
        }
        $page = $query->orderByDesc('payment.fecha_hora')->orderByDesc('product_payment.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row): array => $this->format($row, $branch))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function show(int $companyId, object $branch, int $id): array
    {
        $row = $this->query($companyId, (int) $branch->id)->where('product_payment.id', $id)->first();
        abort_unless($row, 404, 'Pago de cliente no encontrado.');

        return $this->format($row, $branch);
    }

    /** @param array<string, mixed> $data @return array{id: int, idempotent: bool} */
    public function register(int $companyId, object $branch, User $actor, array $data, ?string $ip = null): array
    {
        $this->assertActor($companyId, $branch, $actor);
        $requestHash = $this->requestHash($data);

        return DB::transaction(function () use ($companyId, $branch, $actor, $data, $ip, $requestHash): array {
            // Serialize creation for this company, including retries from another branch.
            DB::table('empresas')->where('id', $companyId)->lockForUpdate()->first(['id']);
            $existing = DB::table('pagos_despacho_productos')
                ->where('empresa_id', $companyId)->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->sucursal_id !== (int) $branch->id || ! hash_equals($existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Esta solicitud ya se utilizó para registrar un pago diferente.',
                    ]);
                }

                return ['id' => (int) $existing->id, 'idempotent' => true];
            }
            $this->assertExternalClient($companyId, (int) $data['cliente_id']);
            $result = $this->movements->register(
                $companyId,
                $actor,
                $this->payload($data, $branch, (string) Str::uuid()),
                $ip,
                allowMissingMethodReference: true,
                allowUnassignedCustomerAccount: true,
            );
            $id = DB::table('pagos_despacho_productos')->insertGetId([
                'empresa_id' => $companyId,
                'sucursal_id' => (int) $branch->id,
                'pago_id' => $result['pago_id'],
                'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $requestHash,
                'estado' => Pago::STATUS_REGISTERED,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'pagos_despacho_productos', $id, 'REGISTRAR', null,
                $this->show($companyId, $branch, $id), $ip);

            return ['id' => $id, 'idempotent' => false];
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(int $companyId, object $branch, User $actor, int $id, array $data, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        DB::transaction(function () use ($companyId, $branch, $actor, $id, $data, $ip): void {
            $record = $this->locked($companyId, (int) $branch->id, $id);
            if ($record->estado !== Pago::STATUS_REGISTERED) {
                throw ValidationException::withMessages(['pago' => 'Este pago ya fue eliminado y no se puede editar.']);
            }
            $this->assertExternalClient($companyId, (int) $data['cliente_id']);
            $before = $this->show($companyId, $branch, $id);
            $payload = $this->payload($data, $branch, (string) Str::uuid());
            $sameEntry = $before['client']['id'] === $payload['cliente_id']
                && $before['amount'] === $payload['importe']
                && $before['currency'] === $payload['moneda']
                && $before['payment_method']['id'] === $payload['metodo_pago_id']
                && ($before['account']['id'] ?? null) === $payload['cuenta_destino_id'];
            $paymentId = (int) $record->pago_id;
            if ($sameEntry) {
                $this->movements->updateMetadata($companyId, $actor, $paymentId, $payload, $ip,
                    productPaymentContextId: $id);
            } else {
                $this->movements->void($companyId, $actor, $paymentId,
                    'Corrección del pago de cliente '.$this->code($id), $ip, productPaymentContextId: $id);
                $replacement = $this->movements->register(
                    $companyId,
                    $actor,
                    $payload,
                    $ip,
                    allowMissingMethodReference: true,
                    allowUnassignedCustomerAccount: true,
                );
                $paymentId = $replacement['pago_id'];
            }
            DB::table('pagos_despacho_productos')->where('id', $id)->update([
                'pago_id' => $paymentId,
                'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'pagos_despacho_productos', $id, 'EDITAR',
                $before, $this->show($companyId, $branch, $id), $ip);
        }, 3);
    }

    public function delete(int $companyId, object $branch, User $actor, int $id, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        DB::transaction(function () use ($companyId, $branch, $actor, $id, $ip): void {
            $record = $this->locked($companyId, (int) $branch->id, $id);
            if ($record->estado === Pago::STATUS_VOIDED) {
                return;
            }
            $before = $this->show($companyId, $branch, $id);
            $this->movements->void($companyId, $actor, (int) $record->pago_id,
                'Eliminación del pago de cliente '.$this->code($id), $ip, productPaymentContextId: $id);
            DB::table('pagos_despacho_productos')->where('id', $id)->update([
                'estado' => Pago::STATUS_VOIDED,
                'anulada_por' => $actor->id,
                'anulada_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'pagos_despacho_productos', $id, 'ELIMINAR',
                $before, $this->show($companyId, $branch, $id), $ip);
        }, 3);
    }

    private function query(int $companyId, int $branchId): Builder
    {
        return DB::table('pagos_despacho_productos as product_payment')
            ->join('pagos as payment', 'payment.id', '=', 'product_payment.pago_id')
            ->join('terceros as client', 'client.id', '=', 'payment.cliente_id')
            ->leftJoin('metodos_pago as method', 'method.id', '=', 'payment.metodo_pago_id')
            ->leftJoin('cuentas_financieras as account', 'account.id', '=', 'payment.cuenta_destino_id')
            ->where('product_payment.empresa_id', $companyId)
            ->where('product_payment.sucursal_id', $branchId)
            ->where('payment.empresa_id', $companyId)
            ->select([
                'product_payment.id', 'product_payment.estado', 'payment.fecha_hora', 'payment.importe',
                'payment.moneda', 'payment.referencia', 'payment.observaciones', 'payment.cliente_id',
                'client.nombre_razon_social as client_name', 'client.numero_documento as client_document',
                'payment.metodo_pago_id', 'method.nombre as method_name', 'payment.cuenta_destino_id',
                'account.alias as account_name',
            ]);
    }

    private function locked(int $companyId, int $branchId, int $id): object
    {
        $record = DB::table('pagos_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($record, 404, 'Pago de cliente no encontrado.');

        return $record;
    }

    /** @return array<string, mixed> */
    private function format(object $row, object $branch): array
    {
        return [
            'id' => (int) $row->id,
            'code' => $this->code((int) $row->id),
            'client' => ['id' => (int) $row->cliente_id, 'name' => $row->client_name, 'document' => $row->client_document],
            'amount' => FinancialMoney::normalize((string) $row->importe),
            'currency' => $row->moneda,
            'payment_method' => ['id' => (int) $row->metodo_pago_id, 'name' => $row->method_name],
            'account' => $row->cuenta_destino_id === null ? null : ['id' => (int) $row->cuenta_destino_id, 'name' => $row->account_name],
            'date_time' => CarbonImmutable::parse($row->fecha_hora, $this->databaseTimezone())
                ->setTimezone($this->timezone($branch))->format('Y-m-d\TH:i'),
            'reference' => $row->referencia,
            'notes' => $row->observaciones,
            'state' => $row->estado,
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(array $data, object $branch, string $key): array
    {
        return [
            'idempotency_key' => $key,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => (int) $data['cliente_id'],
            'metodo_pago_id' => (int) $data['metodo_pago_id'],
            'cuenta_destino_id' => empty($data['cuenta_destino_id']) ? null : (int) $data['cuenta_destino_id'],
            'importe' => FinancialMoney::normalize($data['importe']),
            'moneda' => $data['moneda'],
            'fecha_hora' => empty($data['fecha_hora']) ? now()->toDateTimeString() : $this->databaseDate($data['fecha_hora'], $branch),
            'referencia' => $data['referencia'] ?? null,
            'observaciones' => $data['observaciones'] ?? null,
            'aplicaciones' => [],
        ];
    }

    /** @param array<string, mixed> $data */
    private function requestHash(array $data): string
    {
        return hash('sha256', json_encode([
            (int) $data['cliente_id'], FinancialMoney::normalize($data['importe']), (int) $data['metodo_pago_id'],
            $data['fecha_hora'] ?? null, $data['moneda'], (int) ($data['cuenta_destino_id'] ?? 0),
            $data['referencia'] ?? null, $data['observaciones'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertExternalClient(int $companyId, int $clientId): void
    {
        $valid = DB::table('terceros as client')->where('client.id', $clientId)
            ->where('client.empresa_id', $companyId)->where('client.estado', 'ACTIVO')
            ->where('client.es_cliente_interno', false)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('tercero_roles')->whereColumn('tercero_id', 'client.id')->where('rol', 'CLIENTE');
            })->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['cliente_id' => 'Selecciona un cliente externo activo de esta empresa.']);
        }
    }

    private function assertActor(int $companyId, object $branch, User $actor): void
    {
        abort_unless((int) $actor->empresa_id === $companyId && $actor->isActive()
            && (int) $branch->empresa_id === $companyId
            && (! $actor->sucursal_id || (int) $actor->sucursal_id === (int) $branch->id)
            && $actor->hasPermission('PRODUCTOS_DESPACHO_DESPACHAR'), 403, 'No tienes permiso para gestionar pagos de clientes.');
    }

    private function code(int $id): string
    {
        return 'PCL-'.str_pad((string) $id, 10, '0', STR_PAD_LEFT);
    }

    private function timezone(object $branch): string
    {
        return (string) ($branch->zona_horaria ?: $this->databaseTimezone());
    }

    private function databaseTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    private function databaseDate(string $date, object $branch): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $date, $this->timezone($branch))
            ->setTimezone($this->databaseTimezone())->toDateTimeString();
    }
}
