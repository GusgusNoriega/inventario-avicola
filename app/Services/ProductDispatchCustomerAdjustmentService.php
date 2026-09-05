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

class ProductDispatchCustomerAdjustmentService
{
    public function __construct(
        private readonly ManualCustomerDebtService $debts,
        private readonly FinancialMovementService $movements,
        private readonly FinancialAuditService $audit,
    ) {}

    public function register(int $companyId, object $branch, User $actor, array $data, ?string $ip = null): array
    {
        $this->assertActor($companyId, $branch, $actor);
        $hash = hash('sha256', json_encode([
            (int) $data['cliente_id'], $data['tipo'], $data['importe'], $data['moneda'],
            $data['fecha_hora'] ?? null, $data['observaciones'] ?? null,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($companyId, $branch, $actor, $data, $ip, $hash): array {
            DB::table('empresas')->where('id', $companyId)->lockForUpdate()->first(['id']);
            $existing = DB::table('ajustes_despacho_productos')->where('empresa_id', $companyId)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->sucursal_id !== (int) $branch->id || ! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se utilizó para otro ajuste.']);
                }

                return ['id' => (int) $existing->id, 'idempotent' => true];
            }
            $this->assertClient($companyId, (int) $data['cliente_id']);
            $documentId = null;
            $paymentId = null;
            if ($data['tipo'] === 'PRIOR_DEBT') {
                $result = $this->debts->register($companyId, $actor,
                    $this->debtPayload($data, $branch), $ip, productDispatchBranch: $branch);
                $documentId = $result['document_id'];
            } else {
                $result = $this->movements->register($companyId, $actor,
                    $this->creditPayload($companyId, $data, $branch), $ip, productDispatchBranch: $branch);
                $paymentId = $result['pago_id'];
            }
            $id = DB::table('ajustes_despacho_productos')->insertGetId([
                'empresa_id' => $companyId, 'sucursal_id' => (int) $branch->id,
                'tipo' => $data['tipo'], 'comprobante_id' => $documentId, 'pago_id' => $paymentId,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'fecha_hora' => $this->databaseDate($data['fecha_hora'] ?? null, $branch),
                'estado' => Pago::STATUS_REGISTERED, 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'ajustes_despacho_productos', $id, 'REGISTRAR',
                null, $this->show($companyId, $branch, $id), $ip);

            return ['id' => (int) $id, 'idempotent' => false];
        }, 3);
    }

    public function update(int $companyId, object $branch, User $actor, int $id, array $data, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        DB::transaction(function () use ($companyId, $branch, $actor, $id, $data, $ip): void {
            $record = $this->record($companyId, $branch, $id, true);
            if ($record->estado !== Pago::STATUS_REGISTERED || $record->tipo !== $data['tipo']) {
                throw ValidationException::withMessages(['tipo' => 'Solo puedes editar un ajuste vigente conservando su tipo.']);
            }
            $this->assertClient($companyId, (int) $data['cliente_id']);
            $before = $this->show($companyId, $branch, $id);
            $paymentId = $record->pago_id;
            if ($record->tipo === 'PRIOR_DEBT') {
                $this->debts->update($companyId, $actor, (int) $record->comprobante_id,
                    $this->debtPayload($data, $branch), $ip, productDispatchBranch: $branch);
            } else {
                $sameEntry = $before['client']['id'] === (int) $data['cliente_id']
                    && $before['amount'] === $data['importe'] && $before['currency'] === $data['moneda'];
                if ($sameEntry) {
                    $this->movements->updateMetadata($companyId, $actor, (int) $paymentId, [
                        'fecha_hora' => $this->databaseDate($data['fecha_hora'] ?? null, $branch),
                        'observaciones' => $data['observaciones'] ?: 'Saldo a favor del cliente',
                    ], $ip, productAdjustmentContextId: $id);
                } else {
                    $this->movements->void($companyId, $actor, (int) $paymentId,
                        'Corrección del saldo a favor de Despacho de productos.', $ip, productAdjustmentContextId: $id);
                    $result = $this->movements->register($companyId, $actor,
                        $this->creditPayload($companyId, $data, $branch), $ip, productDispatchBranch: $branch);
                    $paymentId = $result['pago_id'];
                }
            }
            DB::table('ajustes_despacho_productos')->where('id', $id)->update([
                'pago_id' => $paymentId, 'fecha_hora' => $this->databaseDate($data['fecha_hora'] ?? null, $branch),
                'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'ajustes_despacho_productos', $id, 'EDITAR',
                $before, $this->show($companyId, $branch, $id), $ip);
        }, 3);
    }

    public function delete(int $companyId, object $branch, User $actor, int $id, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        DB::transaction(function () use ($companyId, $branch, $actor, $id, $ip): void {
            $record = $this->record($companyId, $branch, $id, true);
            if ($record->estado === Pago::STATUS_VOIDED) {
                return;
            }
            $before = $this->show($companyId, $branch, $id);
            if ($record->tipo === 'PRIOR_DEBT') {
                $this->debts->void($companyId, $actor, (int) $record->comprobante_id,
                    'Eliminación desde Pagos de clientes.', $ip, productDispatchBranch: $branch);
            } else {
                $this->movements->void($companyId, $actor, (int) $record->pago_id,
                    'Eliminación del saldo a favor desde Pagos de clientes.', $ip, productAdjustmentContextId: $id);
            }
            DB::table('ajustes_despacho_productos')->where('id', $id)->update([
                'estado' => Pago::STATUS_VOIDED, 'anulada_por' => $actor->id,
                'anulada_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($companyId, $actor->id, 'ajustes_despacho_productos', $id, 'ELIMINAR',
                $before, $this->show($companyId, $branch, $id), $ip);
        }, 3);
    }

    public function show(int $companyId, object $branch, int $id): array
    {
        $record = $this->record($companyId, $branch, $id);
        if ($record->tipo === 'PRIOR_DEBT') {
            $row = $this->legacyDebt($companyId, $branch, (int) $record->comprobante_id);
            $row['id'] = $id;
        } else {
            $payment = DB::table('pagos as payment')->join('terceros as client', 'client.id', '=', 'payment.cliente_id')
                ->where('payment.empresa_id', $companyId)->where('payment.id', $record->pago_id)
                ->first(['payment.*', 'client.nombre_razon_social as client_name', 'client.numero_documento as client_document']);
            abort_unless($payment, 404);
            $row = [
                'id' => $id, 'kind' => 'CREDIT', 'code' => 'SAF-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT),
                'client' => ['id' => (int) $payment->cliente_id, 'name' => $payment->client_name, 'document' => $payment->client_document],
                'amount' => FinancialMoney::normalize((string) $payment->importe), 'currency' => $payment->moneda,
                'notes' => $payment->observaciones, 'reference' => null,
                'can_edit' => $payment->estado === Pago::STATUS_REGISTERED,
                'can_delete' => $payment->estado === Pago::STATUS_REGISTERED,
                'payment_id' => (int) $payment->id,
            ];
        }
        $row['state'] = $record->estado;
        $row['date_time'] = CarbonImmutable::parse($record->fecha_hora, config('app.timezone'))
            ->setTimezone($branch->zona_horaria ?: config('app.timezone'))->format('Y-m-d\TH:i');
        $row['edit_url'] = $row['delete_url'] = '/despacho-productos/pagos/ajustes/'.$id;

        return $row;
    }

    public function legacyDebt(int $companyId, object $branch, int $id): array
    {
        $this->assertLegacyDebt($companyId, $branch, $id);
        $document = $this->debts->document($companyId, $id);

        return [
            'id' => $id, 'document_id' => $id, 'kind' => 'PRIOR_DEBT', 'code' => $document['codigo'],
            'client' => ['id' => $document['cliente']['id'], 'name' => $document['cliente']['nombre'],
                'document' => $document['cliente']['numero_documento']],
            'amount' => $document['total'], 'currency' => $document['moneda'],
            'date_time' => $document['fecha_emision'].'T00:00', 'notes' => $document['detalle'], 'reference' => null,
            'state' => $document['estado'], 'can_edit' => $document['puede_editar'], 'can_delete' => $document['puede_anular'],
            'edit_url' => '/despacho-productos/pagos/deudas/'.$id,
            'delete_url' => '/despacho-productos/pagos/deudas/'.$id,
            'action_reason' => $document['puede_anular'] ? null : 'La deuda tiene abonos aplicados. Elimina primero los abonos relacionados.',
        ];
    }

    public function updateLegacyDebt(int $companyId, object $branch, User $actor, int $id, array $data, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        $this->assertLegacyDebt($companyId, $branch, $id, true);
        $this->assertClient($companyId, (int) $data['cliente_id']);
        if ($data['tipo'] !== 'PRIOR_DEBT') {
            throw ValidationException::withMessages(['tipo' => 'Conserva el tipo de deuda anterior.']);
        }
        $this->debts->update($companyId, $actor, $id, $this->debtPayload($data, $branch), $ip, productDispatchBranch: $branch);
    }

    public function deleteLegacyDebt(int $companyId, object $branch, User $actor, int $id, ?string $ip = null): void
    {
        $this->assertActor($companyId, $branch, $actor);
        $this->assertLegacyDebt($companyId, $branch, $id, true);
        $this->debts->void($companyId, $actor, $id, 'Eliminación desde Pagos de clientes.', $ip, productDispatchBranch: $branch);
    }

    private function assertLegacyDebt(int $companyId, object $branch, int $id, bool $unwrappedOnly = false): void
    {
        abort_unless(DB::table('comprobantes')->where('empresa_id', $companyId)->where('id', $id)
            ->where('operacion', 'VENTA')->where('naturaleza', 'CARGO')->where('tipo_documento', 'SALDO_ANTERIOR')
            ->where('origen_codigo', 'MANUAL')->where('origen_clave', 'like', 'DEUDA_ANTERIOR_CLIENTE:%')->exists(), 404);
        $wrapper = DB::table('ajustes_despacho_productos')->where('comprobante_id', $id)->first(['sucursal_id']);
        abort_if($wrapper && ($unwrappedOnly || (int) $wrapper->sucursal_id !== (int) $branch->id), 404);
    }

    private function record(int $companyId, object $branch, int $id, bool $lock = false): object
    {
        $query = DB::table('ajustes_despacho_productos')->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)->where('id', $id);
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($record, 404, 'El ajuste del cliente no fue encontrado.');

        return $record;
    }

    private function debtPayload(array $data, object $branch): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(), 'cliente_id' => (int) $data['cliente_id'],
            'importe' => $data['importe'], 'moneda' => $data['moneda'],
            'fecha_emision' => substr($data['fecha_hora'] ?? now($branch->zona_horaria ?: config('app.timezone'))->format('Y-m-d\TH:i'), 0, 10),
            'detalle' => $data['observaciones'] ?: 'Deuda anterior del cliente',
        ];
    }

    private function creditPayload(int $companyId, array $data, object $branch): array
    {
        // Only allocate against this module's branch documents or shared historical debts.
        $remaining = $data['importe'];
        $applications = [];
        $documents = DB::table('comprobantes as document')->where('document.empresa_id', $companyId)
            ->where('document.tercero_id', $data['cliente_id'])->where('document.moneda', $data['moneda'])
            ->where('document.operacion', 'VENTA')->where('document.naturaleza', 'CARGO')
            ->whereIn('document.estado', ['PENDIENTE', 'PARCIAL'])->where('document.saldo_pendiente', '>', 0)
            ->where(function (Builder $scope) use ($companyId, $branch): void {
                $scope->whereExists(function (Builder $tickets) use ($companyId, $branch): void {
                    $tickets->selectRaw('1')->from('comprobante_tickets_despacho_productos as link')
                        ->join('tickets_despacho_productos as ticket', 'ticket.id', '=', 'link.ticket_despacho_producto_id')
                        ->whereColumn('link.comprobante_id', 'document.id')->where('ticket.empresa_id', $companyId)
                        ->where('ticket.sucursal_id', $branch->id)->where('ticket.estado', 'REGISTRADO');
                })->orWhere(function (Builder $debts) use ($branch): void {
                    $debts->where('document.origen_codigo', 'MANUAL')->where('document.tipo_documento', 'SALDO_ANTERIOR')
                        ->where('document.origen_clave', 'like', 'DEUDA_ANTERIOR_CLIENTE:%')
                        ->whereNotExists(function (Builder $adjustments) use ($branch): void {
                            $adjustments->selectRaw('1')->from('ajustes_despacho_productos')
                                ->whereColumn('comprobante_id', 'document.id')->where('sucursal_id', '<>', $branch->id);
                        });
                });
            })->orderBy('document.fecha_emision')->orderBy('document.id')->lockForUpdate()->get(['document.id', 'document.saldo_pendiente']);
        foreach ($documents as $document) {
            if (FinancialMoney::compare($remaining, '0.00') <= 0) {
                break;
            }
            $amount = FinancialMoney::compare($remaining, (string) $document->saldo_pendiente) > 0
                ? FinancialMoney::normalize((string) $document->saldo_pendiente) : $remaining;
            $applications[] = ['lado' => 'CXC', 'comprobante_id' => (int) $document->id, 'importe_aplicado' => $amount];
            $remaining = FinancialMoney::subtract($remaining, $amount);
        }

        return [
            'idempotency_key' => (string) Str::uuid(), 'tipo' => Pago::TYPE_CUSTOMER_DISCOUNT,
            'cliente_id' => (int) $data['cliente_id'], 'importe' => $data['importe'], 'moneda' => $data['moneda'],
            'fecha_hora' => $this->databaseDate($data['fecha_hora'] ?? null, $branch),
            'observaciones' => $data['observaciones'] ?: 'Saldo a favor del cliente', 'aplicaciones' => $applications,
        ];
    }

    private function databaseDate(?string $date, object $branch): string
    {
        return ($date ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $date, $branch->zona_horaria ?: config('app.timezone'))
            : CarbonImmutable::now())->setTimezone(config('app.timezone'))->toDateTimeString();
    }

    private function assertClient(int $companyId, int $clientId): void
    {
        $valid = DB::table('terceros as client')->where('client.id', $clientId)->where('client.empresa_id', $companyId)
            ->where('client.estado', 'ACTIVO')->where('client.es_cliente_interno', false)
            ->whereExists(function (Builder $roles): void {
                $roles->selectRaw('1')->from('tercero_roles')->whereColumn('tercero_id', 'client.id')->where('rol', 'CLIENTE');
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
            && $actor->hasPermission('PRODUCTOS_DESPACHO_DESPACHAR'), 403);
    }
}
