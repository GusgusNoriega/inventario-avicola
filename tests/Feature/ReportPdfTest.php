<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\CuentaFinanciera;
use App\Models\EntidadFinanciera;
use App\Models\Pago;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TipoPollo;
use App\Models\User;
use App\Services\CashRegisterMovementService;
use App\Services\ReportDataService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ReportPdfTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->makeAdministrator($this->user);
        $this->actingAs($this->user);
    }

    public function test_reports_page_lists_current_system_reports_without_zones(): void
    {
        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('Ventas por cliente')
            ->assertSee('Estado de cuenta de cliente')
            ->assertSee('Estado de cuenta de proveedor')
            ->assertSee('Pagos y cobros')
            ->assertSee('Movimientos por responsable')
            ->assertSee('Sin zonas ni campos heredados')
            ->assertDontSee('Reporte de ventas por zonas');
    }

    public function test_reports_page_shows_the_csv_download_only_for_payments(): void
    {
        $response = $this->get(route('finanzas.reportes'))->assertOk();
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        $this->assertTrue($loaded);
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//button[normalize-space(.) = "Descargar CSV"]');

        $this->assertNotFalse($buttons);
        $this->assertSame(1, $buttons->length);
        $button = $buttons->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button);
        $this->assertSame(route('finanzas.reportes.pagos.csv'), $button->getAttribute('formaction'));
        $this->assertSame('_self', $button->getAttribute('formtarget'));

        $forms = $xpath->query('ancestor::form', $button);
        $this->assertNotFalse($forms);
        $form = $forms->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame(route('finanzas.reportes.pdf', 'pagos'), $form->getAttribute('action'));
    }

    public function test_reports_page_lists_only_own_tenant_accounts_and_includes_inactive_accounts(): void
    {
        $activeOwnAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta propia activa reporte',
        );
        $inactiveOwnAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta propia inactiva reporte',
            CuentaFinanciera::STATUS_INACTIVE,
        );
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa excluida reporte',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta de otro tenant excluida reporte',
            owner: $otherTenantUser,
        );

        $this->get(route('finanzas.reportes'))
            ->assertOk()
            ->assertSee('name="cuenta_id"', false)
            ->assertSee('Cuenta propia activa reporte')
            ->assertSee('Cuenta propia inactiva reporte')
            ->assertSee('Inactiva')
            ->assertDontSee('Cuenta externa excluida reporte')
            ->assertDontSee('Cuenta de otro tenant excluida reporte')
            ->assertViewHas('accounts', function ($accounts) use (
                $activeOwnAccount,
                $inactiveOwnAccount,
                $externalAccount,
                $otherTenantAccount,
            ): bool {
                $accountIds = $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id);

                return $accountIds->contains($activeOwnAccount)
                    && $accountIds->contains($inactiveOwnAccount)
                    && ! $accountIds->contains($externalAccount)
                    && ! $accountIds->contains($otherTenantAccount);
            });
    }

    public function test_report_http_validation_rejects_external_and_other_tenant_accounts(): void
    {
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa invalida reporte',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta ajena invalida reporte',
            owner: $otherTenantUser,
        );

        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'pagos',
                'cuenta_id' => $externalAccount,
                'desde' => '2026-07-01',
                'hasta' => '2026-07-31',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('cuenta_id');

        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'responsable',
                'usuario_id' => $this->user->id,
                'cuenta_id' => $otherTenantAccount,
                'desde' => '2026-07-01',
                'hasta' => '2026-07-31',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('cuenta_id');
    }

    public function test_payments_report_filters_by_origin_or_destination_account_and_recalculates_totals(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta seleccionada pagos reporte',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta no seleccionada pagos reporte',
        );
        $this->reportPayment('PG-CUENTA-DESTINO', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '120.00',
        ]);
        $this->reportPayment('PG-CUENTA-ORIGEN', [
            'cuenta_origen_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_EXPENSE,
            'importe' => '45.00',
        ]);
        $this->reportPayment('PG-CUENTA-TRANSFERENCIA', [
            'tipo' => Pago::TYPE_INTERNAL_TRANSFER,
            'cuenta_origen_id' => $selectedAccount,
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_NO_FLOW,
            'importe' => '20.00',
        ]);
        $this->reportPayment('PG-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '900.00',
        ]);

        $report = app(ReportDataService::class)->payments(
            (int) $this->user->empresa_id,
            '2026-07-01',
            '2026-07-31',
            ['cuenta_id' => $selectedAccount],
        );

        $this->assertSame(
            ['PG-CUENTA-DESTINO', 'PG-CUENTA-ORIGEN', 'PG-CUENTA-TRANSFERENCIA'],
            $report['rows']->pluck('code')->all(),
        );
        $this->assertSame(1, $report['rows']->where('code', 'PG-CUENTA-TRANSFERENCIA')->count());
        $this->assertFalse($report['rows']->pluck('code')->contains('PG-OTRA-CUENTA'));
        $this->assertSame(120.0, (float) $report['income']);
        $this->assertSame(45.0, (float) $report['expense']);
        $this->assertSame(185.0, (float) $report['total']);
    }

    public function test_payments_csv_is_excel_compatible_and_respects_account_and_date_filters(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Caja Ñandú; "Principal"',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Caja no seleccionada CSV',
        );
        $this->reportPayment('PG-CSV-INICIO', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-07-01 00:00:00',
            'referencia' => 'Referencia; "especial"',
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '120.50',
        ]);
        $this->reportPayment('=PG-CSV-TRANSFERENCIA', [
            'tipo' => Pago::TYPE_INTERNAL_TRANSFER,
            'cuenta_origen_id' => $selectedAccount,
            'cuenta_destino_id' => $otherAccount,
            'fecha_hora' => '2026-07-15 12:30:45',
            'direccion' => Pago::DIRECTION_NO_FLOW,
            'importe' => '20.00',
        ]);
        $this->reportPayment('PG-CSV-FIN', [
            'cuenta_origen_id' => $selectedAccount,
            'fecha_hora' => '2026-07-31 23:59:59',
            'direccion' => Pago::DIRECTION_EXPENSE,
            'importe' => '45.00',
        ]);
        $this->reportPayment('PG-CSV-ANTES', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-06-30 23:59:59',
        ]);
        $this->reportPayment('PG-CSV-DESPUES', [
            'cuenta_destino_id' => $selectedAccount,
            'fecha_hora' => '2026-08-01 00:00:00',
        ]);
        $this->reportPayment('PG-CSV-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'fecha_hora' => '2026-07-15 10:00:00',
            'importe' => '900.00',
        ]);

        $response = $this->get(route('finanzas.reportes.pagos.csv', [
            'cuenta_id' => $selectedAccount,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('pagos-2026-07-01-2026-07-31.csv');
        $contents = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFsep=;\r\n", $contents);
        $this->assertStringEndsWith("\r\n", $contents);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $contents));

        $rows = $this->parseExcelCsv($contents);
        $this->assertSame([
            'Fecha y hora',
            'Código',
            'Contraparte',
            'Tipo',
            'Método',
            'Detalle',
            'Responsable',
            'Flujo',
            'Monto',
        ], array_shift($rows));
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['PG-CSV-INICIO', "'=PG-CSV-TRANSFERENCIA", 'PG-CSV-FIN'],
            array_column($rows, 1),
        );
        $this->assertSame(1, count(array_filter(
            $rows,
            fn (array $row): bool => $row[1] === "'=PG-CSV-TRANSFERENCIA",
        )));
        $this->assertSame(0, preg_match('/^\s*[=+\-@]/u', $rows[1][1]));
        $this->assertSame(
            ['01/07/2026 00:00:00', '15/07/2026 12:30:45', '31/07/2026 23:59:59'],
            array_column($rows, 0),
        );
        $this->assertSame(['INGRESO', 'SIN_FLUJO', 'EGRESO'], array_column($rows, 7));
        $this->assertSame(['120,50', '20,00', '45,00'], array_column($rows, 8));
        $this->assertSame(
            'Entidad Caja Ñandú; "Principal" - Caja Ñandú; "Principal" - Referencia; "especial"',
            $rows[0][5],
        );
    }

    public function test_payments_csv_rejects_external_and_other_tenant_accounts(): void
    {
        $externalAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_EXTERNAL,
            'Cuenta externa invalida CSV',
        );
        $otherTenantUser = User::factory()->create();
        $otherTenantAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta ajena invalida CSV',
            owner: $otherTenantUser,
        );

        foreach ([$externalAccount, $otherTenantAccount] as $accountId) {
            $this->from(route('finanzas.reportes'))
                ->get(route('finanzas.reportes.pagos.csv', [
                    'cuenta_id' => $accountId,
                    'desde' => '2026-07-01',
                    'hasta' => '2026-07-31',
                ]))
                ->assertRedirect(route('finanzas.reportes'))
                ->assertSessionHasErrors('cuenta_id');
        }
    }

    public function test_responsible_report_respects_the_account_filter(): void
    {
        $selectedAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta seleccionada responsable reporte',
        );
        $otherAccount = $this->financialAccount(
            EntidadFinanciera::TYPE_OWN,
            'Cuenta no seleccionada responsable reporte',
        );
        $otherUser = $this->createUserForCompany($this->user, ['nombre' => 'Otro responsable de cuenta']);
        $this->reportPayment('PG-RESP-CUENTA', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '70.00',
        ]);
        $this->reportPayment('PG-RESP-OTRA-CUENTA', [
            'cuenta_destino_id' => $otherAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '90.00',
        ]);
        $this->reportPayment('PG-RESP-OTRO-USUARIO', [
            'cuenta_destino_id' => $selectedAccount,
            'direccion' => Pago::DIRECTION_INCOME,
            'importe' => '110.00',
            'created_by' => $otherUser->id,
        ]);

        $report = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
            $selectedAccount,
        );

        $this->assertSame(['PG-RESP-CUENTA'], $report['rows']->pluck('code')->all());
        $this->assertCount(1, $report['collections']);
        $this->assertCount(0, $report['expenses']);
        $this->assertCount(0, $report['other']);
        $this->assertSame(70.0, (float) $report['income']);
        $this->assertSame(0.0, (float) $report['expense']);
        $this->assertSame(70.0, (float) $report['total']);
    }

    public function test_sales_report_is_generated_as_an_inline_pdf(): void
    {
        $response = $this->get(route('finanzas.reportes.pdf', [
            'type' => 'ventas-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ventas-clientes-2026-07-01-2026-07-31.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_report_rejects_an_inverted_date_range(): void
    {
        $this->from(route('finanzas.reportes'))
            ->get(route('finanzas.reportes.pdf', [
                'type' => 'pagos',
                'desde' => '2026-07-31',
                'hasta' => '2026-07-01',
            ]))
            ->assertRedirect(route('finanzas.reportes'))
            ->assertSessionHasErrors('hasta');
    }

    public function test_report_can_be_downloaded_as_png(): void
    {
        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'ventas-clientes',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="ventas-clientes-2026-07-01-2026-07-31.png"');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function test_customer_statement_can_be_downloaded_as_png_with_the_customer_layout(): void
    {
        $client = $this->thirdParty('Cliente para imagen', TerceroRole::CLIENT);

        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'estado-cliente',
            'cliente_id' => $client->id,
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="estado-cliente-2026-07-01-2026-07-31.png"');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent());
    }

    public function test_long_image_report_is_downloaded_as_numbered_png_pages_in_zip(): void
    {
        $client = $this->thirdParty('Cliente para imagenes', TerceroRole::CLIENT);
        foreach (range(1, 36) as $index) {
            Pago::query()->create([
                'empresa_id' => $this->user->empresa_id,
                'codigo' => 'PG-IMG-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'tercero_id' => $client->id,
                'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
                'cliente_id' => $client->id,
                'direccion' => Pago::DIRECTION_INCOME,
                'fecha_hora' => "2026-07-15 10:00:{$index}",
                'metodo' => 'EFECTIVO',
                'moneda' => 'PEN',
                'importe' => '10.00',
                'estado' => Pago::STATUS_REGISTERED,
                'created_by' => $this->user->id,
            ]);
        }

        $response = $this->get(route('finanzas.reportes.imagen', [
            'type' => 'pagos',
            'desde' => '2026-07-01',
            'hasta' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('Content-Disposition', 'attachment; filename="pagos-2026-07-01-2026-07-31-imagenes.zip"');
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_all_current_report_types_generate_pdf_without_transactions(): void
    {
        $client = $this->thirdParty('Cliente de prueba', TerceroRole::CLIENT);
        $provider = $this->thirdParty('Proveedor de prueba', TerceroRole::PROVIDER);
        $common = ['desde' => '2026-07-01', 'hasta' => '2026-07-31'];
        $reports = [
            'estado-cliente' => [...$common, 'cliente_id' => $client->id],
            'estado-proveedor' => [...$common, 'proveedor_id' => $provider->id],
            'pagos' => $common,
            'responsable' => [...$common, 'usuario_id' => $this->user->id],
        ];

        foreach ($reports as $type => $query) {
            $response = $this->get(route('finanzas.reportes.pdf', ['type' => $type, ...$query]));
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        }
    }

    public function test_responsible_report_includes_each_active_cash_movement_once_in_its_real_section(): void
    {
        [$cashRegisterId, $otherCashRegisterId] = $this->cashRegisters();
        $client = $this->thirdParty('Cliente caja reporte', TerceroRole::CLIENT);
        $otherUser = $this->userForCompany('Responsable ajeno');

        $income = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'CLIENTE',
            'cliente_id' => $client->id,
            'fecha_hora' => '2026-07-10 09:00:00',
            'importe' => '100.00',
            'detalle' => 'Cobro en efectivo del pedido 100',
        ]));
        $expense = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'ADMINISTRATIVO',
            'fecha_hora' => '2026-07-10 10:00:00',
            'importe' => '30.00',
            'detalle' => 'Compra administrativa de utiles',
        ]));
        $transfer = $this->cashMovement($this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'OTRA_CAJA',
            'otra_caja_id' => $otherCashRegisterId,
            'fecha_hora' => '2026-07-10 11:00:00',
            'importe' => '20.00',
            'detalle' => 'Fondo enviado a caja secundaria',
        ]));
        $foreignMovement = $this->cashMovement($this->registerCashMovement($otherUser, $cashRegisterId, [
            'fecha_hora' => '2026-07-10 12:00:00',
            'importe' => '999.00',
            'detalle' => 'Movimiento de otro responsable',
        ]));

        $report = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $rows = $report['rows'];
        $rowsByCode = $rows->keyBy('code');

        $this->assertCount(3, $rows);
        $this->assertCount(3, $rows->pluck('code')->unique());
        $this->assertSame($this->user->nombre, $report['user_name']);
        $this->assertSame([$this->user->nombre], $rows->pluck('user')->unique()->values()->all());
        $this->assertSame(100.0, (float) $report['income']);
        $this->assertSame(30.0, (float) $report['expense']);
        $this->assertSame(150.0, (float) $report['total']);
        $this->assertCount(1, $report['collections']);
        $this->assertCount(1, $report['expenses']);
        $this->assertCount(1, $report['other']);
        $this->assertFalse($rowsByCode->has($foreignMovement->codigo));

        foreach ([$income, $expense, $transfer] as $cashMovement) {
            $this->assertStringStartsWith('CAJ-', $cashMovement->codigo);
            $this->assertSame(1, $rows->where('code', $cashMovement->codigo)->count());
        }

        $incomeRow = $rowsByCode->get($income->codigo);
        $this->assertSame('INGRESO DE CAJA', $incomeRow['type']);
        $this->assertSame('INGRESO', $incomeRow['flow']);
        $this->assertSame('Cliente caja reporte', $incomeRow['counterparty']);
        $this->assertSame('Cobro en efectivo del pedido 100', $incomeRow['detail']);
        $this->assertSame($income->codigo, $report['collections']->sole()['code']);

        $expenseRow = $rowsByCode->get($expense->codigo);
        $this->assertSame('GASTO DE CAJA', $expenseRow['type']);
        $this->assertSame('EGRESO', $expenseRow['flow']);
        $this->assertSame('Administrativo', $expenseRow['counterparty']);
        $this->assertSame('Compra administrativa de utiles', $expenseRow['detail']);
        $this->assertSame($expense->codigo, $report['expenses']->sole()['code']);

        $transferRow = $rowsByCode->get($transfer->codigo);
        $this->assertSame('TRANSFERENCIA ENTRE CAJAS', $transferRow['type']);
        $this->assertSame('SIN_FLUJO', $transferRow['flow']);
        $this->assertStringContainsString('Caja secundaria reporte', $transferRow['counterparty']);
        $this->assertSame('Fondo enviado a caja secundaria', $transferRow['detail']);
        $this->assertSame($transfer->codigo, $report['other']->sole()['code']);
    }

    public function test_financial_cash_edit_keeps_the_logical_creator_and_lists_only_the_active_replacement(): void
    {
        [$cashRegisterId] = $this->cashRegisters();
        $editor = $this->userForCompany('Editor de caja');
        $movementId = $this->registerCashMovement($this->user, $cashRegisterId, [
            'fecha_hora' => '2026-07-15 09:00:00',
            'importe' => '80.00',
            'detalle' => 'Ingreso antes de corregir',
        ]);
        $before = $this->cashMovement($movementId);

        app(CashRegisterMovementService::class)->update(
            (int) $this->user->empresa_id,
            $editor,
            $movementId,
            $this->cashMovementPayload(null, $cashRegisterId, [
                'fecha_hora' => '2026-07-15 09:00:00',
                'importe' => '65.00',
                'detalle' => 'Ingreso corregido por el editor',
            ]),
        );
        $after = $this->cashMovement($movementId);

        $this->assertNotSame((int) $before->pago_id, (int) $after->pago_id);
        $this->assertDatabaseHas('pagos', [
            'id' => $before->pago_id,
            'estado' => Pago::STATUS_VOIDED,
            'importe' => 80,
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $before->pago_id,
            'importe' => 80,
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $after->pago_id,
            'created_by' => $editor->id,
            'estado' => Pago::STATUS_REGISTERED,
            'importe' => 65,
        ]);
        $this->assertDatabaseCount('pagos', 3);

        $creatorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $editorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $editor->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertCount(1, $creatorReport['rows']);
        $this->assertCount(1, $creatorReport['collections']);
        $this->assertCount(0, $creatorReport['expenses']);
        $this->assertCount(0, $creatorReport['other']);
        $this->assertSame(65.0, (float) $creatorReport['income']);
        $this->assertSame(0.0, (float) $creatorReport['expense']);
        $this->assertSame(65.0, (float) $creatorReport['total']);
        $this->assertSame($before->codigo, $creatorReport['rows']->sole()['code']);
        $this->assertSame($this->user->nombre, $creatorReport['rows']->sole()['user']);
        $this->assertSame(65.0, (float) $creatorReport['rows']->sole()['amount']);
        $this->assertSame('Ingreso corregido por el editor', $creatorReport['rows']->sole()['detail']);
        $this->assertFalse($creatorReport['rows']->contains(
            fn (array $row): bool => (float) $row['amount'] === 80.0,
        ));

        $this->assertEmptyResponsibleReport($editorReport);
    }

    public function test_voided_cash_movement_is_excluded_from_creator_and_voider_reports_and_totals(): void
    {
        [$cashRegisterId] = $this->cashRegisters();
        $voider = $this->userForCompany('Anulador de caja');
        $movementId = $this->registerCashMovement($this->user, $cashRegisterId, [
            'direccion' => 'EGRESO',
            'contraparte_tipo' => 'ADMINISTRATIVO',
            'fecha_hora' => '2026-07-20 14:00:00',
            'importe' => '45.00',
            'detalle' => 'Gasto administrativo que sera eliminado',
        ]);
        $cashMovement = $this->cashMovement($movementId);

        app(CashRegisterMovementService::class)->void(
            (int) $this->user->empresa_id,
            $voider,
            $movementId,
        );

        $this->assertDatabaseHas('movimientos_caja_efectivo', [
            'id' => $movementId,
            'estado' => 'ANULADO',
        ]);
        $this->assertDatabaseHas('pagos', [
            'id' => $cashMovement->pago_id,
            'estado' => Pago::STATUS_VOIDED,
        ]);
        $this->assertDatabaseHas('pagos', [
            'reversa_de_pago_id' => $cashMovement->pago_id,
            'created_by' => $voider->id,
        ]);

        $creatorReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $this->user->id,
            '2026-07-01',
            '2026-07-31',
        );
        $voiderReport = app(ReportDataService::class)->responsibleMovements(
            (int) $this->user->empresa_id,
            $voider->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertEmptyResponsibleReport($creatorReport);
        $this->assertEmptyResponsibleReport($voiderReport);
        $this->assertFalse($creatorReport['rows']->pluck('code')->contains($cashMovement->codigo));
        $this->assertFalse($voiderReport['rows']->pluck('code')->contains($cashMovement->codigo));
    }

    public function test_customer_statement_uses_opening_balance_charges_and_collections(): void
    {
        $client = $this->thirdParty('Cliente con saldo', TerceroRole::CLIENT);
        $documentDefaults = [
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'origen_codigo' => 'PRUEBA',
            'moneda' => 'PEN',
            'subtotal' => '0.00',
            'impuesto' => '0.00',
            'saldo_pendiente' => '0.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ];
        Comprobante::query()->create([...$documentDefaults, 'codigo' => 'V-ANTERIOR', 'fecha_emision' => '2026-06-30', 'total' => '100.00']);
        Comprobante::query()->create([...$documentDefaults, 'codigo' => 'V-PERIODO', 'fecha_emision' => '2026-07-10', 'total' => '1000.00']);
        Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => 'PG-PRUEBA',
            'tercero_id' => $client->id,
            'tipo' => Pago::TYPE_CUSTOMER_COLLECTION,
            'cliente_id' => $client->id,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-15 10:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '200.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
        ]);

        $statement = app(ReportDataService::class)->customerStatement(
            (int) $this->user->empresa_id,
            (int) $client->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame(100.0, $statement['opening']);
        $this->assertSame(1000.0, $statement['charges']);
        $this->assertSame(200.0, $statement['credits']);
        $this->assertSame(900.0, $statement['balance']);
    }

    public function test_customer_statement_abbreviates_only_known_chicken_types(): void
    {
        $client = $this->thirdParty('Cliente con productos', TerceroRole::CLIENT);
        $document = Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $client->id,
            'operacion' => Comprobante::OPERATION_SALE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'V-PRODUCTOS',
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-07-10',
            'moneda' => 'PEN',
            'subtotal' => '100.00',
            'impuesto' => '0.00',
            'total' => '100.00',
            'saldo_pendiente' => '100.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
        $types = collect([
            TipoPollo::CHICKEN_LIVE => ['Pollo vivo', 'PV'],
            TipoPollo::CHICKEN_DEAD => ['Pollo muerto', 'PM'],
            TipoPollo::CHICKEN_DRESSED => ['Pollo pelado', 'PP'],
            TipoPollo::CHICKEN_PROCESSED => ['Pollo beneficiado', 'PB'],
            'POLLO_ESPECIAL' => ['Pollo especial', 'Pollo especial'],
        ])->mapWithKeys(function (array $type, string $code): array {
            $model = TipoPollo::query()->firstOrCreate([
                'codigo' => $code,
            ], [
                'nombre' => $type[0],
                'permite_despacho' => true,
                'estado' => TipoPollo::STATUS_ACTIVE,
            ]);

            return [$model->id => $type];
        });

        $details = $types->map(fn (array $type, int $typeId): array => [
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => $typeId,
            'descripcion' => $type[0],
            'cantidad_aves' => 1,
            'peso_neto_kg' => '1.000',
            'precio_kg' => '10.0000',
            'subtotal' => '10.00',
            'created_at' => now(),
        ])->values()->all();
        $details[] = [
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => null,
            'descripcion' => 'Ajuste manual',
            'cantidad_aves' => null,
            'peso_neto_kg' => null,
            'precio_kg' => null,
            'subtotal' => '50.00',
            'created_at' => now(),
        ];
        DB::table('comprobante_detalles')->insert($details);

        $statement = app(ReportDataService::class)->customerStatement(
            (int) $this->user->empresa_id,
            (int) $client->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('PV, PM, PP, PB, Pollo especial, Ajuste manual', $statement['rows']->first()['detail']);
    }

    public function test_provider_statement_keeps_full_chicken_type_names(): void
    {
        $provider = $this->thirdParty('Proveedor con productos', TerceroRole::PROVIDER);
        $liveChicken = TipoPollo::query()->firstOrCreate([
            'codigo' => TipoPollo::CHICKEN_LIVE,
        ], [
            'nombre' => 'Pollo vivo',
            'permite_despacho' => true,
            'estado' => TipoPollo::STATUS_ACTIVE,
        ]);
        $document = Comprobante::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tercero_id' => $provider->id,
            'operacion' => Comprobante::OPERATION_PURCHASE,
            'naturaleza' => Comprobante::NATURE_CHARGE,
            'tipo_documento' => 'INTERNO',
            'codigo' => 'C-PRODUCTOS',
            'origen_codigo' => 'PRUEBA',
            'fecha_emision' => '2026-07-10',
            'moneda' => 'PEN',
            'subtotal' => '100.00',
            'impuesto' => '0.00',
            'total' => '100.00',
            'saldo_pendiente' => '100.00',
            'estado' => Comprobante::STATUS_PENDING,
            'created_by' => $this->user->id,
        ]);
        DB::table('comprobante_detalles')->insert([
            'comprobante_id' => $document->id,
            'tipo_pollo_id' => $liveChicken->id,
            'descripcion' => 'Pollo vivo',
            'cantidad_aves' => 1,
            'peso_neto_kg' => '1.000',
            'precio_kg' => '100.0000',
            'subtotal' => '100.00',
            'created_at' => now(),
        ]);

        $statement = app(ReportDataService::class)->providerStatement(
            (int) $this->user->empresa_id,
            (int) $provider->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('Pollo vivo', $statement['rows']->first()['detail']);
    }

    public function test_customer_pdf_layout_shows_only_name_and_groups_movements_by_day(): void
    {
        $client = $this->thirdParty('Cliente solo nombre', TerceroRole::CLIENT);
        $rows = collect([
            [
                'date' => '2026-07-10',
                'code' => 'V-1',
                'type' => 'INTERNO',
                'detail' => 'PV',
                'weight' => 10.0,
                'price' => 7.0,
                'debit' => 70.0,
                'credit' => 0.0,
                'effect' => 70.0,
                'balance' => 170.0,
            ],
            [
                'date' => '2026-07-10',
                'code' => 'PG-1',
                'type' => 'COBRO',
                'detail' => 'EFECTIVO',
                'weight' => null,
                'price' => null,
                'debit' => 0.0,
                'credit' => 20.0,
                'effect' => -20.0,
                'balance' => 150.0,
            ],
            [
                'date' => '2026-07-11',
                'code' => 'V-2',
                'type' => 'INTERNO',
                'detail' => 'PP',
                'weight' => 5.0,
                'price' => 8.0,
                'debit' => 40.0,
                'credit' => 0.0,
                'effect' => 40.0,
                'balance' => 190.0,
            ],
        ]);

        $html = view('reports.pdf', [
            'company' => $this->user->empresa,
            'type' => 'estado-cliente',
            'title' => 'Estado de cuenta de cliente',
            'from' => '2026-07-01',
            'to' => '2026-07-31',
            'data' => [
                'counterparty' => $client,
                'opening' => 100.0,
                'rows' => $rows,
                'charges' => 110.0,
                'credits' => 20.0,
                'balance' => 190.0,
            ],
            'generatedAt' => CarbonImmutable::parse('2026-07-31 12:00:00'),
        ])->render();
        $plainText = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertIsString($plainText);
        $this->assertStringContainsString('Cliente: Cliente solo nombre', $plainText);
        $this->assertStringNotContainsString((string) $client->numero_documento, $plainText);
        $this->assertStringNotContainsString('<table class="summary">', $html);
        $this->assertStringNotContainsString('Cargos del periodo', $plainText);
        $this->assertStringContainsString('Cargo / Abono', $plainText);
        $this->assertSame(2, substr_count($plainText, 'Movimientos del'));
        $this->assertStringContainsString('class="num debit"', $html);
        $this->assertStringContainsString('class="num credit"', $html);
    }

    /** @return array{int, int} */
    private function cashRegisters(): array
    {
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'tipo' => 'PROPIA',
            'razon_social' => 'Entidad de cajas para reporte',
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = function (string $alias) use ($entityId): int {
            return DB::table('cuentas_financieras')->insertGetId([
                'entidad_financiera_id' => $entityId,
                'tipo' => 'CAJA',
                'alias' => $alias,
                'moneda' => 'PEN',
                'estado' => 'ACTIVO',
                'created_by' => $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        return [
            $account('Caja principal reporte'),
            $account('Caja secundaria reporte'),
        ];
    }

    private function financialAccount(
        string $entityType,
        string $alias,
        string $accountStatus = CuentaFinanciera::STATUS_ACTIVE,
        ?User $owner = null,
        string $entityStatus = EntidadFinanciera::STATUS_ACTIVE,
    ): int {
        $owner ??= $this->user;
        $entityId = DB::table('entidades_financieras')->insertGetId([
            'empresa_id' => $owner->empresa_id,
            'tipo' => $entityType,
            'razon_social' => 'Entidad '.$alias,
            'estado' => $entityStatus,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cuentas_financieras')->insertGetId([
            'entidad_financiera_id' => $entityId,
            'tipo' => CuentaFinanciera::TYPE_CASH,
            'alias' => $alias,
            'moneda' => 'PEN',
            'estado' => $accountStatus,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<list<string>> */
    private function parseExcelCsv(string $contents): array
    {
        $prefix = "\xEF\xBB\xBFsep=;\r\n";
        $this->assertStringStartsWith($prefix, $contents);
        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, substr($contents, strlen($prefix)));
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /** @param array<string, mixed> $overrides */
    private function reportPayment(string $code, array $overrides = []): Pago
    {
        return Pago::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'codigo' => $code,
            'tipo' => Pago::TYPE_ADJUSTMENT,
            'direccion' => Pago::DIRECTION_INCOME,
            'fecha_hora' => '2026-07-15 10:00:00',
            'metodo' => 'EFECTIVO',
            'moneda' => 'PEN',
            'importe' => '10.00',
            'estado' => Pago::STATUS_REGISTERED,
            'created_by' => $this->user->id,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function registerCashMovement(User $actor, int $cashRegisterId, array $overrides = []): int
    {
        $result = app(CashRegisterMovementService::class)->register(
            (int) $this->user->empresa_id,
            $actor,
            $this->cashMovementPayload((string) Str::uuid(), $cashRegisterId, $overrides),
        );

        return (int) $result['movimiento_caja_id'];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function cashMovementPayload(
        ?string $idempotencyKey,
        int $cashRegisterId,
        array $overrides = [],
    ): array {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'caja_id' => $cashRegisterId,
            'direccion' => 'INGRESO',
            'contraparte_tipo' => 'OTRO',
            'fecha_hora' => '2026-07-10 08:00:00',
            'importe' => '10.00',
            'detalle' => 'Movimiento de caja para reporte',
            ...$overrides,
        ];

        if ($idempotencyKey === null) {
            unset($payload['idempotency_key']);
        }

        return $payload;
    }

    private function cashMovement(int $movementId): object
    {
        $movement = DB::table('movimientos_caja_efectivo')->where('id', $movementId)->first();
        $this->assertNotNull($movement);

        return $movement;
    }

    private function userForCompany(string $name): User
    {
        $user = $this->createUserForCompany($this->user, ['nombre' => $name]);
        $administratorRole = $this->user->roles()
            ->where('roles.codigo', 'ADMINISTRADOR')
            ->firstOrFail();
        $user->roles()->attach($administratorRole);

        return $user;
    }

    /** @param array<string, mixed> $report */
    private function assertEmptyResponsibleReport(array $report): void
    {
        $this->assertCount(0, $report['rows']);
        $this->assertCount(0, $report['collections']);
        $this->assertCount(0, $report['expenses']);
        $this->assertCount(0, $report['other']);
        $this->assertSame(0.0, (float) $report['income']);
        $this->assertSame(0.0, (float) $report['expense']);
        $this->assertSame(0.0, (float) $report['total']);
    }

    private function thirdParty(string $name, string $role): Tercero
    {
        $thirdParty = Tercero::query()->create([
            'empresa_id' => $this->user->empresa_id,
            'tipo_documento' => 'DNI',
            'numero_documento' => fake()->unique()->numerify('########'),
            'nombre_razon_social' => $name,
            'direccion' => 'Direccion de prueba',
            'estado' => Tercero::STATUS_ACTIVE,
        ]);
        TerceroRole::query()->create(['tercero_id' => $thirdParty->id, 'rol' => $role]);

        return $thirdParty;
    }
}
