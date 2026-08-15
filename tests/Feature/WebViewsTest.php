<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class WebViewsTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->makeAdministrator($user);
        $this->actingAs($user);
    }

    public function test_main_menu_is_the_application_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Menú principal')
            ->assertSee(route('operacion').'#despacho', false)
            ->assertSee('Despacho mayorista')
            ->assertSee(route('despacho-mayorista-2'), false)
            ->assertSee('Despacho mayorista 2')
            ->assertSee(route('despacho-minorista'), false)
            ->assertSee('Despacho minorista')
            ->assertSee(route('despacho-minorista-2'), false)
            ->assertSee('Despacho minorista 2')
            ->assertDontSee('Registro de pesadas y balanzas')
            ->assertSee(route('tickets-dia'), false)
            ->assertSee('Resumen de la jornada')
            ->assertSee('Consolidado diario por cliente')
            ->assertSee(route('reporte-proveedores'), false)
            ->assertSee('Reporte de proveedores')
            ->assertSee('Javas, pollos, peso y destinos por camión')
            ->assertSee(route('gestion-pesadas'), false)
            ->assertSee(route('jornada'), false)
            ->assertSee(route('directorio'), false)
            ->assertSee(route('flota'), false)
            ->assertSee(route('finanzas'), false)
            ->assertSee('Finanzas y tesorería')
            ->assertSee('Saldos, compras, cobros, pagos y cuentas')
            ->assertDontSee(route('compras.index'), false)
            ->assertDontSee('Compras a proveedores')
            ->assertSee(route('control-javas'), false)
            ->assertSee(route('install-app'), false)
            ->assertSee('Control de javas y bandejas')
            ->assertSee('Mi flota y choferes')
            ->assertDontSee('Facturación')
            ->assertDontSee('Ingresos y despachos')
            ->assertDontSee('data-future-view', false);
    }

    public function test_install_application_view_exposes_the_direct_pwa_prompt(): void
    {
        $this->get('/instalar')
            ->assertOk()
            ->assertSee('id="installPwaButton"', false)
            ->assertSee('id="ticketPrinterSetup"', false)
            ->assertSee('https://sada-csa.com/', false)
            ->assertSee('href="ms-settings:printers"', false)
            ->assertSee(route('install-app.printer-installer'), false)
            ->assertSee('La aplicación web no puede ver ni cambiar las impresoras de esta computadora.')
            ->assertSee('El configurador usa Chrome si está instalado y Edge como respaldo')
            ->assertSee('Configurar-Impresion-Sistema-Pollos.ps1 -DirectPrint')
            ->assertSee('Los tickets seguirán abriendo la ventana normal para elegir una impresora.')
            ->assertSee(asset('js/install-app.js'), false)
            ->assertSee(asset('manifest.webmanifest'), false)
            ->assertSee(route('menu'), false);

        $javascript = (string) file_get_contents(public_path('js/install-app.js'));
        $registration = (string) file_get_contents(public_path('js/pwa-register.js'));

        $this->assertStringContainsString('window.deferredPwaInstallPrompt', $javascript);
        $this->assertStringContainsString('promptEvent.prompt()', $javascript);
        $this->assertStringContainsString('beforeinstallprompt', $registration);
        $this->assertStringContainsString('event.preventDefault()', $registration);
    }

    public function test_printer_configurator_is_downloaded_from_an_authenticated_route(): void
    {
        $response = $this->get(route('install-app.printer-installer'));

        $response->assertOk()
            ->assertDownload('Configurar-Impresion-Sistema-Pollos.ps1');

        $this->assertSame(
            realpath(base_path('scripts/Install-SistemaPollosKiosk.ps1')),
            $response->baseResponse->getFile()->getRealPath(),
        );

        auth()->logout();
        $this->get(route('install-app.printer-installer'))
            ->assertRedirect(route('login'));
    }

    public function test_financial_views_are_available_without_database_queries(): void
    {
        $financeMenu = $this->get('/finanzas')
            ->assertOk()
            ->assertSee('Finanzas y tesorería')
            ->assertSee('¿Qué necesitas gestionar?')
            ->assertSee('class="fin-module-grid"', false)
            ->assertSee(route('finanzas.saldos'), false)
            ->assertSee('Saldos y trazabilidad')
            ->assertSee(route('compras.index'), false)
            ->assertSee('Compras a proveedores')
            ->assertSee(route('finanzas.entidades'), false)
            ->assertSee('Empresas y cuentas')
            ->assertSee(route('finanzas.caja-efectivo'), false)
            ->assertSee('Caja de efectivo')
            ->assertSee(route('finanzas.cobranzas'), false)
            ->assertSee('Cobranzas')
            ->assertSee(route('finanzas.movimientos.nuevo'), false)
            ->assertSee('Registrar cobro, deuda o pago')
            ->assertSee(route('finanzas.movimientos'), false)
            ->assertSee('Gestionar movimientos y deudas')
            ->assertSee(route('finanzas.gastos'), false)
            ->assertSee('Gastos de empresa')
            ->assertSee(route('finanzas.descuentos-clientes'), false)
            ->assertSee('Descuentos a clientes')
            ->assertSee(route('finanzas.tickets'), false)
            ->assertSee('Consultar tickets')
            ->assertSee(route('finanzas.reportes'), false)
            ->assertSee('Reportes PDF')
            ->assertSee(route('menu'), false)
            ->assertSee(asset('css/finanzas.css'), false)
            ->assertDontSee('id="financeAvailableBalance"', false)
            ->assertDontSee('id="financeAuthDialog"', false)
            ->assertDontSee(asset('js/finanzas-dashboard.js'), false);

        $this->assertSame(11, substr_count($financeMenu->getContent(), 'class="fin-module-card fin-card"'));

        $this->get('/finanzas/saldos')
            ->assertOk()
            ->assertSee('Saldos y trazabilidad')
            ->assertSee('id="financeAvailableBalance"', false)
            ->assertSee('id="financeTraceRows"', false)
            ->assertSee('id="financeAdvanceList"', false)
            ->assertSee('id="financeAdvanceDialog"', false)
            ->assertSee('aria-describedby="financeAdvanceDialogDescription"', false)
            ->assertSee('Saldo a favor con proveedores')
            ->assertSee('id="financeProviderCreditBalance"', false)
            ->assertSee('id="financeAuthDialog"', false)
            ->assertSee(route('finanzas'), false)
            ->assertSee(route('finanzas.saldos'), false)
            ->assertSee(route('finanzas.entidades'), false)
            ->assertSee(route('finanzas.movimientos.nuevo'), false)
            ->assertSee(route('compras.index'), false)
            ->assertSee(asset('css/finanzas.css'), false)
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/finanzas-dashboard.js'), false);

        $this->get('/finanzas/entidades')
            ->assertOk()
            ->assertSee('Empresas y cuentas')
            ->assertSee('id="financeEntityForm"', false)
            ->assertSee('value="PROPIA"', false)
            ->assertSee('value="EXTERNA"', false)
            ->assertSee('id="financeEntityProvider"', false)
            ->assertSee('id="financeAccountForm"', false)
            ->assertSee('value="BANCO"', false)
            ->assertSee('value="CAJA"', false)
            ->assertSee('value="BILLETERA"', false)
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/finanzas-entidades.js'), false);

        $this->get('/finanzas/caja-efectivo')
            ->assertOk()
            ->assertSee('Caja de efectivo')
            ->assertSee('Gastos del día')
            ->assertSee('Nuevo ingreso o gasto')
            ->assertSee('<option value="EGRESO">Gasto</option>', false)
            ->assertDontSee('Egresos del día')
            ->assertSee('id="cashRegisterAccount"', false)
            ->assertSee('id="cashRegisterDate"', false)
            ->assertSee('id="cashRegisterIncome"', false)
            ->assertSee('Centro')
            ->assertSee('id="cashRegisterAccountIncome"', false)
            ->assertSee('Cobranza por cobrador')
            ->assertSee('incluye días anteriores')
            ->assertSee('hasta confirmar «Recibido»')
            ->assertSee('id="cashRegisterCollectionsByCollector"', false)
            ->assertDontSee('id="cashRegisterRetailTwoDispatch"', false)
            ->assertSee('id="cashRegisterExpense"', false)
            ->assertSee('id="cashRegisterNet"', false)
            ->assertSee('id="cashRegisterList"', false)
            ->assertSee('id="cashRegisterDialog"', false)
            ->assertSee('id="cashRegisterCounterpartType"', false)
            ->assertSee('id="cashRegisterClientSearch"', false)
            ->assertSee('role="combobox"', false)
            ->assertDontSee('id="cashRegisterOperation"', false)
            ->assertSee(asset('js/finanzas-caja-efectivo.js'), false);

        $this->get('/finanzas/cobranzas')
            ->assertOk()
            ->assertSee('Cobranzas')
            ->assertSee('id="collectionDepositTitle"', false)
            ->assertSee('id="collectionCollector"', false)
            ->assertSee('id="collectionDestination"', false)
            ->assertSee('id="collectionDetails"', false)
            ->assertSee('id="collectionSummaryDifference"', false)
            ->assertSee('id="collectionPendingConfirmation"', false)
            ->assertSee('id="collectionFilterReconciliation"', false)
            ->assertSee('pendiente por identificar')
            ->assertSee('id="collectionHistoryTitle"', false)
            ->assertSee('id="collectorDialog"', false)
            ->assertSee('id="collectionDetailDialog"', false)
            ->assertSee('id="collectionAssignDialog"', false)
            ->assertSee('id="collectionAssignDetails"', false)
            ->assertSee('id="collectionAssignSubmit"', false)
            ->assertSee('id="collectionVoidDialog"', false)
            ->assertSee(asset('js/finanzas-cobranzas.js'), false);

        $this->get('/finanzas/movimientos/nuevo')
            ->assertOk()
            ->assertSee('Registrar movimiento')
            ->assertSee('value="COBRO_CLIENTE"', false)
            ->assertSee('value="PAGO_DIRECTO"', false)
            ->assertSee('value="PAGO_PROVEEDOR"', false)
            ->assertSee('value="DEUDA_ANTERIOR_CLIENTE"', false)
            ->assertSee('Deuda anterior de cliente')
            ->assertSee('value="SALDO_FAVOR_PROVEEDOR"', false)
            ->assertSee('value="COBRO_MINORISTA"', false)
            ->assertSee('value="REEMBOLSO_CLIENTE"', false)
            ->assertSee('id="financeProviderPaymentSourcePanel"', false)
            ->assertSee('value="SALDO_FAVOR"', false)
            ->assertSee('id="financeProviderCreditSource"', false)
            ->assertSee('id="financeCxcList"', false)
            ->assertSee('id="financeCxpList"', false)
            ->assertSee('id="financeApplicationsInstructions"', false)
            ->assertSee('id="financeDailyPanel"', false)
            ->assertSee('id="financeDailyFilters"', false)
            ->assertSee('id="financeDailyFrom"', false)
            ->assertSee('id="financeDailyTo"', false)
            ->assertSee('id="financeDailyApply"', false)
            ->assertSee('id="financeDailyToday"', false)
            ->assertSee('id="financeDailyLiveText"', false)
            ->assertSee('id="financeDailyRefresh"', false)
            ->assertSee('id="financeDailyRows"', false)
            ->assertSee('Historial por fechas')
            ->assertSee('Actualización automática cada 10 s')
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/finanzas-movimiento.js'), false);

        $this->get('/finanzas/movimientos')
            ->assertOk()
            ->assertSee('Movimientos, saldos y deudas')
            ->assertSee('id="financeMovementsRows"', false)
            ->assertSee('id="financeDebtsRows"', false)
            ->assertSee('id="financeEditMovementDialog"', false)
            ->assertSee('id="financeEditDebtDialog"', false)
            ->assertSee('id="financeVoidDialog"', false)
            ->assertSee(asset('js/finanzas-movimientos.js'), false);

        $this->get('/finanzas/gastos')
            ->assertOk()
            ->assertSee('Gastos de empresa')
            ->assertSee('id="companyExpenseForm"', false)
            ->assertSee('id="companyExpenseAccount"', false)
            ->assertSee('id="companyExpenseRows"', false)
            ->assertSee('id="companyExpenseEditDialog"', false)
            ->assertSee('id="companyExpenseVoidDialog"', false)
            ->assertSee(asset('js/finanzas-gastos.js'), false);

        $this->get('/finanzas/descuentos-clientes')
            ->assertOk()
            ->assertSee('Descuentos a clientes')
            ->assertSee('id="customerDiscountForm"', false)
            ->assertSee('id="customerDiscountClientSearch"', false)
            ->assertSee('id="customerDiscountClient"', false)
            ->assertSee('id="customerDiscountDate"', false)
            ->assertSee('id="customerDiscountAmount"', false)
            ->assertSee('id="customerDiscountReason"', false)
            ->assertSee('id="customerDiscountRows"', false)
            ->assertSee('id="customerDiscountEditDialog"', false)
            ->assertSee('id="customerDiscountEditDate"', false)
            ->assertSee('id="customerDiscountVoidDialog"', false)
            ->assertSee(asset('js/finanzas-descuentos-clientes.js'), false);

        $this->get('/finanzas/tickets')
            ->assertOk()
            ->assertSee('Consulta y edición de tickets')
            ->assertSee('Debes aplicar al menos un filtro')
            ->assertSee('id="financeTicketFilters"', false)
            ->assertSee('id="financeTicketClient"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-controls="financeTicketClientSuggestions"', false)
            ->assertSee('id="financeTicketClientSuggestions"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('id="financeTicketStatus"', false)
            ->assertSee('value="ANULADOS"', false)
            ->assertSee('id="financeTicketRows"', false)
            ->assertSee('id="financeTicketPriceDialog"', false)
            ->assertSee('id="financeTicketClientDialog"', false)
            ->assertSee('id="financeTicketDateTimeDialog"', false)
            ->assertSee('id="financeTicketDateTimeForm"', false)
            ->assertSee('id="financeTicketDateTimeInput"', false)
            ->assertSee('id="financeTicketVoidDialog"', false)
            ->assertSee('id="financeTicketVoidReason"', false)
            ->assertSee('id="financeTicketRestoreDialog"', false)
            ->assertSee('Sí, anular ticket')
            ->assertSee('Sí, restablecer ticket')
            ->assertSee('Las pesadas anuladas junto con el ticket')
            ->assertSee('id="financeTicketBulkDialog"', false)
            ->assertSee('data-bulk-operation="AUMENTAR"', false)
            ->assertSee('data-bulk-operation="DISMINUIR"', false)
            ->assertSee(asset('js/finanzas-tickets.js'), false);

        $financeStylesheet = file_get_contents(public_path('css/finanzas.css'));
        $dashboardJavascript = file_get_contents(public_path('js/finanzas-dashboard.js'));
        $entitiesJavascript = file_get_contents(public_path('js/finanzas-entidades.js'));
        $movementJavascript = file_get_contents(public_path('js/finanzas-movimiento.js'));
        $managementJavascript = file_get_contents(public_path('js/finanzas-movimientos.js'));
        $expensesJavascript = file_get_contents(public_path('js/finanzas-gastos.js'));
        $cashRegisterJavascript = file_get_contents(public_path('js/finanzas-caja-efectivo.js'));
        $discountsJavascript = file_get_contents(public_path('js/finanzas-descuentos-clientes.js'));
        $ticketsJavascript = file_get_contents(public_path('js/finanzas-tickets.js'));

        $this->assertIsString($financeStylesheet);
        $this->assertIsString($dashboardJavascript);
        $this->assertIsString($entitiesJavascript);
        $this->assertIsString($movementJavascript);
        $this->assertIsString($managementJavascript);
        $this->assertIsString($expensesJavascript);
        $this->assertIsString($cashRegisterJavascript);
        $this->assertIsString($discountsJavascript);
        $this->assertIsString($ticketsJavascript);
        $this->assertMatchesRegularExpression(
            '/html\.fin-root,\s*body\.fin-page\s*\{[^}]*height:\s*auto;[^}]*overflow-y:\s*auto;/s',
            $financeStylesheet,
        );
        $this->assertStringContainsString('/finanzas/saldos', $dashboardJavascript);
        $this->assertStringContainsString('/finanzas/trazabilidad', $dashboardJavascript);
        $this->assertStringContainsString('/finanzas/movimientos?per_page=6', $dashboardJavascript);
        $this->assertStringContainsString('aplicacion_estado: "CON_SALDO"', $dashboardJavascript);
        $this->assertStringContainsString('/aplicaciones', $dashboardJavascript);
        $this->assertStringContainsString('data-void-ticket', $ticketsJavascript);
        $this->assertStringContainsString('data-restore-ticket', $ticketsJavascript);
        $this->assertStringContainsString('/anular`', $ticketsJavascript);
        $this->assertStringContainsString('/restablecer`', $ticketsJavascript);
        $this->assertStringContainsString('data-advance-apply', $dashboardJavascript);
        $this->assertStringContainsString('["ANULADO", "REVERSA"]', $dashboardJavascript);
        $this->assertStringContainsString('state.savingAdvance && !force', $dashboardJavascript);
        $this->assertStringContainsString('/finanzas/entidades', $entitiesJavascript);
        $this->assertStringContainsString('include=cuentas&per_page=100', $entitiesJavascript);
        $this->assertStringNotContainsString('include=cuentas&per_page=200', $entitiesJavascript);
        $this->assertStringContainsString('/finanzas/cuentas/', $entitiesJavascript);
        $this->assertStringContainsString('/finanzas/catalogo', $movementJavascript);
        $this->assertStringContainsString('include=cuentas&estado=ACTIVO&per_page=100', $movementJavascript);
        $this->assertStringNotContainsString('include=cuentas&estado=ACTIVO&per_page=200', $movementJavascript);
        $this->assertStringContainsString('/finanzas/cartera?', $movementJavascript);
        $this->assertStringContainsString('SALDO_FAVOR_PROVEEDOR', $movementJavascript);
        $this->assertStringContainsString('DEUDA_ANTERIOR_CLIENTE', $movementJavascript);
        $this->assertStringContainsString('/finanzas/deudas-clientes', $managementJavascript);
        $this->assertStringContainsString('/anular', $managementJavascript);
        $this->assertStringContainsString('/finanzas/deudas-clientes', $movementJavascript);
        $this->assertStringContainsString('function usesProviderCredit()', $movementJavascript);
        $this->assertStringContainsString('aplicacion_estado: "CON_SALDO"', $movementJavascript);
        $this->assertStringContainsString('responseMeta(response)', $movementJavascript);
        $this->assertStringContainsString('["last_page"]', $movementJavascript);
        $this->assertStringContainsString('providerCreditSource', $movementJavascript);
        $this->assertStringContainsString('/aplicaciones', $movementJavascript);
        $this->assertStringContainsString('importe_aplicado:', $movementJavascript);
        $this->assertStringContainsString('Las aplicaciones a CXC y CXP son opcionales', $movementJavascript);
        $this->assertStringNotContainsString('Un pago directo debe aplicarse al menos', $movementJavascript);
        $this->assertStringContainsString('idempotency_key:', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("tipo")', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("cliente_id")', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("proveedor_id")', $movementJavascript);
        $this->assertStringContainsString('method: "POST"', $movementJavascript);
        $this->assertStringContainsString('DAILY_REFRESH_INTERVAL = 10000', $movementJavascript);
        $this->assertStringContainsString('function dailyEndpoint(', $movementJavascript);
        $this->assertStringContainsString('params.set("tipo", modeKey)', $movementJavascript);
        $this->assertStringContainsString('desde: from', $movementJavascript);
        $this->assertStringContainsString('hasta: to', $movementJavascript);
        $this->assertStringContainsString('function applyDailyFilters(', $movementJavascript);
        $this->assertStringContainsString('function dailyRangeIncludesToday()', $movementJavascript);
        $this->assertStringContainsString('La fecha Hasta debe ser igual o posterior a Desde.', $movementJavascript);
        $this->assertStringContainsString('state.dailyFollowsToday', $movementJavascript);
        $this->assertStringContainsString('liveOnly: true', $movementJavascript);
        $this->assertStringContainsString('state.dailyLiveActive !== includesToday', $movementJavascript);
        $this->assertStringContainsString('El rango ya no incluye hoy', $movementJavascript);
        $this->assertStringContainsString('./finanzas-date-range.js', $movementJavascript);
        $this->assertStringContainsString('new BroadcastChannel("sistema-pollos-finanzas-movimientos")', $movementJavascript);
        $this->assertStringContainsString('refreshDailyAfterSave = true', $movementJavascript);
        $this->assertStringContainsString('modeKey: savedMode', $movementJavascript);
        $this->assertStringContainsString('selectedModeInput.checked = true', $movementJavascript);
        $this->assertStringContainsString('.fin-daily-panel', $financeStylesheet);
        $this->assertStringContainsString('.fin-daily-filters', $financeStylesheet);
        $this->assertStringContainsString('.fin-daily-table', $financeStylesheet);
        $this->assertStringContainsString('/finanzas/gastos/catalogo', $expensesJavascript);
        $this->assertStringContainsString('/finanzas/gastos?', $expensesJavascript);
        $this->assertStringContainsString('/anular', $expensesJavascript);
        $this->assertStringContainsString('idempotency_key:', $expensesJavascript);
        $this->assertStringContainsString('.fin-expense-layout', $financeStylesheet);
        $this->assertStringContainsString('.fin-cash-list', $financeStylesheet);
        $this->assertStringContainsString('/finanzas/caja-efectivo/catalogo', $cashRegisterJavascript);
        $this->assertStringContainsString('/finanzas/caja-efectivo?', $cashRegisterJavascript);
        $this->assertStringContainsString('localStorage.setItem', $cashRegisterJavascript);
        $this->assertStringContainsString('POLL_INTERVAL = 3000', $cashRegisterJavascript);
        $this->assertStringContainsString('function dateTimeInputForFilteredDay(', $cashRegisterJavascript);
        $this->assertStringContainsString('dateTimeInputForFilteredDay(elements.date.value)', $cashRegisterJavascript);
        $this->assertStringContainsString('function reloadLedgerAfterMutation()', $cashRegisterJavascript);
        $this->assertStringContainsString('function accountIncomeText(', $cashRegisterJavascript);
        $this->assertStringContainsString('summary.ingresos_cuentas', $cashRegisterJavascript);
        $this->assertStringContainsString('summary.cobranzas_por_cobrador', $cashRegisterJavascript);
        $this->assertStringContainsString('data-collection-cash-received', $cashRegisterJavascript);
        $this->assertStringContainsString('/recepcion-caja', $cashRegisterJavascript);
        $this->assertStringContainsString('.fin-cash-summary-collections', $financeStylesheet);
        $this->assertStringContainsString('BroadcastChannel', $cashRegisterJavascript);
        $this->assertStringContainsString('aria-activedescendant', $cashRegisterJavascript);
        $this->assertMatchesRegularExpression(
            '/\["ADMINISTRATIVO", "Administrativo"\].*\["TRANSPORTE", "Transporte"\].*\["DEPOSITO", "Depósito"\].*\["OTRA_CAJA", "Otra caja"\]/s',
            $cashRegisterJavascript,
        );
        $this->assertStringContainsString('data-delete-cash', $cashRegisterJavascript);
        $this->assertStringContainsString('method: "DELETE"', $cashRegisterJavascript);
        $this->assertStringContainsString('window.confirm', $cashRegisterJavascript);
        $this->assertStringContainsString('class="fin-cash-item-title">${escapeHtml(record.detalle)}', $cashRegisterJavascript);
        $this->assertStringContainsString('.fin-cash-item-title', $financeStylesheet);
        $this->assertStringContainsString('minmax(260px, 520px)', $financeStylesheet);
        $this->assertStringContainsString('clamp(150px, 14vw, 180px)', $financeStylesheet);
        $this->assertMatchesRegularExpression(
            '/\.fin-cash-item-counterpart\s*\{[^}]*font-size:\s*15px;/s',
            $financeStylesheet,
        );
        $this->assertMatchesRegularExpression(
            '/\.fin-cash-item\.is-income \.fin-cash-item-counterpart\s*\{[^}]*order:\s*1;/s',
            $financeStylesheet,
        );
        $this->assertMatchesRegularExpression(
            '/\.fin-cash-item\.is-income \.fin-cash-item-title\s*\{[^}]*order:\s*2;[^}]*font-size:\s*14px;/s',
            $financeStylesheet,
        );
        $this->assertStringNotContainsString('numero_operacion', $cashRegisterJavascript);
        $this->assertStringContainsString('/finanzas/descuentos-clientes', $discountsJavascript);
        $this->assertStringContainsString('/finanzas/clientes/', $discountsJavascript);
        $this->assertStringContainsString('fecha_transaccion', $discountsJavascript);
        $this->assertStringContainsString('data-edit-discount', $discountsJavascript);
        $this->assertStringContainsString('data-void-discount', $discountsJavascript);
        $this->assertStringContainsString('/finanzas/tickets?', $ticketsJavascript);
        $this->assertStringContainsString('/finanzas/catalogo', $ticketsJavascript);
        $this->assertStringContainsString('/finanzas/tickets/clientes?', $ticketsJavascript);
        $this->assertStringContainsString('/ajustar-precios', $ticketsJavascript);
        $this->assertStringContainsString('data-edit-date-time', $ticketsJavascript);
        $this->assertStringContainsString('/fecha-hora`', $ticketsJavascript);
        $this->assertStringContainsString('filters.cliente_id', $ticketsJavascript);
        $this->assertStringContainsString('FILTER_CLIENT_RESULT_LIMIT = 8', $ticketsJavascript);
        $this->assertStringContainsString('aria-activedescendant', $ticketsJavascript);
        $this->assertStringContainsString('tipo_pollo_id:', $ticketsJavascript);
        $this->assertStringContainsString('state.appliedFilters', $ticketsJavascript);
        $this->assertStringContainsString('máximo 30 por página', $ticketsJavascript);
    }

    public function test_purchase_views_are_available_without_database_queries(): void
    {
        $this->get('/compras')
            ->assertOk()
            ->assertSee('Compras a proveedores')
            ->assertSee('data-purchase-edit-url=', false)
            ->assertSee('data-can-edit-purchases=', false)
            ->assertSee('data-can-edit-cash-purchases=', false)
            ->assertSee('id="purchaseTotalAmount"', false)
            ->assertSee('id="purchaseLegacyAmount"', false)
            ->assertSee('id="purchaseFilters"', false)
            ->assertSee('id="purchaseFilterCurrency"', false)
            ->assertSee('value="LEGADO"', false)
            ->assertSee('Histórica sin clasificar')
            ->assertSee('id="purchaseRows"', false)
            ->assertSee('id="purchaseDetailDialog"', false)
            ->assertSee('id="purchaseEdit"', false)
            ->assertSee('id="purchaseVoidReason"', false)
            ->assertSee('Cliente → nuestra empresa', false)
            ->assertSee('Cliente → proveedor', false)
            ->assertSee(route('compras.create'), false)
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/compras.js'), false);

        $this->get('/compras/nueva')
            ->assertOk()
            ->assertSee('Registrar compra')
            ->assertSee('value="CREDITO"', false)
            ->assertSee('value="CONTADO"', false)
            ->assertSee('id="purchaseProvider"', false)
            ->assertSee('id="purchaseLines"', false)
            ->assertSee('id="purchaseCashPanel"', false)
            ->assertSee('id="purchaseOriginAccount"', false)
            ->assertSee('id="purchaseDestinationAccount"', false)
            ->assertSee('id="purchasePaymentMethod"', false)
            ->assertSee('id="purchaseTax"', false)
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/compra-form.js'), false);

        $this->get(route('compras.edit', ['compra' => 123]))
            ->assertOk()
            ->assertSee('Editar compra')
            ->assertSee('id="purchaseForm"', false)
            ->assertSee('data-purchase-id="123"', false)
            ->assertSee('data-can-edit-purchases=', false)
            ->assertSee('data-can-edit-cash-purchases=', false)
            ->assertSee('id="purchaseCorrectionNote"', false)
            ->assertSee('El original quedará anulado y se creará el registro corregido para conservar la trazabilidad.')
            ->assertSee('id="purchaseSave"', false)
            ->assertSee('Guardar corrección')
            ->assertSee('id="purchaseReset"', false)
            ->assertSee('Restaurar datos originales')
            ->assertSee(asset('js/compra-form.js'), false);

        $purchaseJavascript = file_get_contents(public_path('js/compras.js'));
        $purchaseFormJavascript = file_get_contents(public_path('js/compra-form.js'));
        $financeStylesheet = file_get_contents(public_path('css/finanzas.css'));

        $this->assertIsString($purchaseJavascript);
        $this->assertIsString($purchaseFormJavascript);
        $this->assertIsString($financeStylesheet);
        $this->assertStringContainsString('/compras/catalogo', $purchaseJavascript);
        $this->assertStringContainsString('/compras?', $purchaseJavascript);
        $this->assertStringContainsString('data-purchase-edit', $purchaseJavascript);
        $this->assertStringContainsString('/anular', $purchaseJavascript);
        $this->assertStringContainsString('tipo=PAGO_PROVEEDOR', $purchaseJavascript);
        $this->assertStringContainsString('tipo=PAGO_DIRECTO', $purchaseJavascript);
        $this->assertStringContainsString('sin_clasificar', $purchaseJavascript);
        $this->assertStringContainsString('moneda: elements.filterCurrency.value', $purchaseJavascript);
        $this->assertStringContainsString('Histórica sin clasificar', $purchaseJavascript);
        $this->assertStringContainsString('Comprobante histórico conservado', $purchaseJavascript);
        $this->assertStringContainsString('status === "ANULADO" || condition === "LEGADO"', $purchaseJavascript);
        $this->assertStringContainsString('is-legacy', $purchaseJavascript);
        $this->assertStringContainsString('/compras/catalogo', $purchaseFormJavascript);
        $this->assertStringContainsString('method: "PUT"', $purchaseFormJavascript);
        $this->assertStringContainsString('idempotency_key:', $purchaseFormJavascript);
        $this->assertStringContainsString('/compras/${encodeURIComponent', $purchaseFormJavascript);
        $this->assertStringContainsString('cuentas_propias', $purchaseFormJavascript);
        $this->assertStringContainsString('cuentas_proveedores', $purchaseFormJavascript);
        $this->assertStringContainsString('condicion: condition()', $purchaseFormJavascript);
        $this->assertStringContainsString('payload.pago =', $purchaseFormJavascript);
        $this->assertStringContainsString('peso_kg:', $purchaseFormJavascript);
        $this->assertStringContainsString('function roundMoney(value)', $purchaseFormJavascript);
        $this->assertStringNotContainsString('no tiene saldo suficiente para esta compra', $purchaseFormJavascript);
        $this->assertStringContainsString('.fin-purchase-form-columns', $financeStylesheet);
        $this->assertStringContainsString('.fin-purchase-dialog', $financeStylesheet);
        $this->assertStringContainsString('.fin-purchase-condition-tag.is-legacy', $financeStylesheet);
        $this->assertStringContainsString('.fin-purchase-legacy-note', $financeStylesheet);
    }

    public function test_retail_dispatch_view_is_available_without_database_queries(): void
    {
        $this->get('/despacho-minorista')
            ->assertOk()
            ->assertSee('Despacho minorista')
            ->assertSee('id="retailRawWeightInput"', false)
            ->assertSee('id="retailTrayCount"', false)
            ->assertSee('id="retailTrayCountTrigger"', false)
            ->assertSee('data-retail-tray-option="0"', false)
            ->assertSee('data-retail-tray-option="10"', false)
            ->assertSee('Sin bandejas')
            ->assertSee('id="retailBirdsPerTray"', false)
            ->assertSee('id="retailBirdsPerTrayAccessibleLabel" class="sr-only"', false)
            ->assertSee('id="retailBirdsPerTrayTrigger"', false)
            ->assertSee('aria-labelledby="retailBirdsPerTrayAccessibleLabel retailBirdsPerTrayValue retailBirdsPerTrayLabel"', false)
            ->assertSee('id="retailBirdsPerTrayValue"', false)
            ->assertSee('id="retailBirdsPerTrayLabel"', false)
            ->assertSee('id="retailBirdsPerTrayModal"', false)
            ->assertSee('data-retail-birds-per-tray-option="1"', false)
            ->assertSee('data-retail-birds-per-tray-option="10"', false)
            ->assertSee('id="retailAdjustedWeight"', false)
            ->assertSee('id="retailOpenManualWeight"', false)
            ->assertSee('Colocar peso manual')
            ->assertDontSee('id="retailManualWeightModal"', false)
            ->assertDontSee('id="retailManualWeightForm"', false)
            ->assertDontSee('retailManualWeightModal', false)
            ->assertSee('id="retailOpenManualWeight" class="rd-manual-weight-button" type="button" aria-haspopup="dialog" aria-controls="retailTouchKeyboard"', false)
            ->assertSee('id="retailManualWeightEntry"', false)
            ->assertSee('data-retail-keyboard-label="Peso manual en kilogramos"', false)
            ->assertSee('class="rd-lists-stage"', false)
            ->assertSee('aria-label="Seleccionar lista de destino"', false)
            ->assertSee('Selecciona una columna y captura; desliza para ver las listas 5 a 8.')
            ->assertSee('class="is-active" data-retail-add-list="0" aria-pressed="true"', false)
            ->assertSee('Seleccionar lista 1')
            ->assertSee('Seleccionar lista 8')
            ->assertDontSee('Agregar a lista 1')
            ->assertSee('data-retail-add-list="7"', false)
            ->assertSee('id="retailSettingsModal"', false)
            ->assertSee('Balanza y ajustes minoristas')
            ->assertDontSee('id="retailDefaultPaymentMethod"', false)
            ->assertDontSee('id="retailDefaultPaymentAccount"', false)
            ->assertSee('Configurar impresión')
            ->assertSee(route('install-app').'#ticketPrinterSetup', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('id="retailOpenTypography"', false)
            ->assertSee('id="retailTypographyDrawer"', false)
            ->assertSee('id="retailTypographyControls"', false)
            ->assertSee('id="retailTypographyReset"', false)
            ->assertSee('id="retailTypographyClose"', false)
            ->assertSee('Tamaños de tipografía')
            ->assertSee('id="retailDeliveryModal"', false)
            ->assertSee('id="retailDeliveryTruck"', false)
            ->assertSee('id="retailDeliveryDriver"', false)
            ->assertDontSee('id="retailPaymentForm"', false)
            ->assertDontSee('data-retail-payment-mode=', false)
            ->assertDontSee('A crédito · cobro en Finanzas')
            ->assertSee('id="retailDeliveryForm" class="rd-modal-card is-delivery" role="dialog" aria-modal="true" aria-labelledby="retailDeliveryModalTitle" novalidate', false)
            ->assertSee('id="retailErrorModal"', false)
            ->assertSee('id="retailErrorModalDetails"', false)
            ->assertSee('id="retailRetryPrint"', false)
            ->assertSee('id="retailErrorLogin"', false)
            ->assertSee('La lista y sus pesadas se conservan')
            ->assertSee('Guardar e imprimir / PDF')
            ->assertSee('Grabar')
            ->assertSee('id="retailTareDetail"', false)
            ->assertSee('Pollo pelado por defecto')
            ->assertSee('Elige beneficiado únicamente cuando el despacho sea sin merma.')
            ->assertSee('id="retailAdjustments" class="rd-adjustment-buttons" role="group" aria-label="Producto y presentación; solo se puede elegir una opción"', false)
            ->assertDontSee('id="retailChickenTypes"', false)
            ->assertSee('id="retailTouchKeyboard"', false)
            ->assertSee('class="rd-touch-keyboard-card" role="dialog" aria-modal="true"', false)
            ->assertSee('data-retail-keyboard="text"', false)
            ->assertSee('data-retail-keyboard="decimal"', false)
            ->assertSee('data-retail-keyboard="integer"', false)
            ->assertSee('Teclado táctil')
            ->assertSee(asset('js/despacho-minorista.js'), false)
            ->assertSee(asset('css/despacho-minorista.css'), false)
            ->assertSee(route('menu'), false)
            ->assertDontSee('id="retailAdjustmentPreview"', false)
            ->assertDontSee('id="retailSex"', false);

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('`${RETAIL_API_BASE}/catalogo`', $javascript);
        $this->assertStringContainsString('`${RETAIL_API_BASE}/configuracion`', $javascript);
        $this->assertStringContainsString('`${RETAIL_API_BASE}/tickets`', $javascript);
        $this->assertStringContainsString('const LIST_COUNT = RETAIL_STATION === "1" ? 8 : 5;', $javascript);
        $this->assertStringContainsString('.slice(0, LIST_COUNT)', $javascript);
        $this->assertStringContainsString('while (restoredLists.length < LIST_COUNT)', $javascript);
        $this->assertStringContainsString('from "./retail-dispatch-errors.js"', $javascript);
        $this->assertStringNotContainsString('from "./retail-payment-defaults.js"', $javascript);
        $this->assertStringNotContainsString('from "./retail-payment-mode.js"', $javascript);
        $this->assertStringContainsString('from "./record-order.js"', $javascript);
        $this->assertStringContainsString('from "./retail-client-search.js"', $javascript);
        $this->assertStringContainsString('filterAndRankRetailClients(state.catalog.clients, search)', $javascript);
        $this->assertStringContainsString('newestRecordsFirst(list.items).map((item) => {', $javascript);
        $this->assertStringContainsString('<th>Tipo</th><th>Band.</th><th>Aves</th><th>P. leído</th>', $javascript);
        $this->assertStringContainsString(
            '<td>${Number(item.readWeight || 0).toFixed(3)}<small>${formatMoney(signedAmount)}</small></td>',
            $javascript
        );
        $this->assertStringContainsString('<span>Peso neto<b>${formatWeight(totals.net)}</b></span>', $javascript);
        $this->assertStringNotContainsString(
            '<td>${Number(item.netWeight).toFixed(3)}<small>${formatMoney(signedAmount)}</small></td>',
            $javascript
        );
        $this->assertStringContainsString('showRetailError(presentation)', $javascript);
        $this->assertStringContainsString('continueDispatchRegistration();', $javascript);
        $this->assertStringNotContainsString('openPaymentModal()', $javascript);
        $this->assertStringNotContainsString('payments,', $javascript);
        $this->assertStringContainsString('adjustment_code', $javascript);
        $this->assertStringContainsString('read_weight_kg', $javascript);
        $this->assertStringContainsString('tray_type_code', $javascript);
        $this->assertStringContainsString('additional_grams', $javascript);
        $this->assertStringContainsString('const RETAIL_DRESSED_CHICKEN_CODE = "POLLO_PELADO";', $javascript);
        $this->assertStringContainsString('RETAIL_PROCESSED_CHICKEN_CODE', $javascript);
        $this->assertStringContainsString('data-retail-processed=', $javascript);
        $this->assertStringNotContainsString('data-retail-chicken=', $javascript);
        $this->assertStringContainsString('ensureDressedChickenTypeSelection();', $javascript);
        $this->assertStringContainsString('"Pollo beneficiado activo: no se aplicará merma."', $javascript);
        $this->assertStringContainsString('availableChickenTypeCodes.has(item.chickenTypeCode)', $javascript);
        $this->assertStringContainsString('from "./retail-weight-calculation.js"', $javascript);
        $this->assertStringContainsString('calculateRetailWeightAdjustment({', $javascript);
        $this->assertStringContainsString('readWeight + totalAdjustmentGrams / 1000', $javascript);
        $this->assertMatchesRegularExpression(
            '/function applyMainManualWeight\\(value\\)[\\s\\S]+?setManualReading\\(value\\)[\\s\\S]+?captureWeight\\(\\{ addImmediately: true \\}\\)/',
            $javascript
        );
        $this->assertStringContainsString('acceptHandler: applyMainManualWeight', $javascript);
        $this->assertStringContainsString('const addImmediately = options?.addImmediately === true;', $javascript);
        $this->assertStringContainsString('addWeighingToList(state.activeList, capturedReading);', $javascript);
        $this->assertStringContainsString(
            'elements.openManualWeight.addEventListener("click", openManualWeightModal)',
            $javascript
        );
        $this->assertStringContainsString('elements.manualWeightTrigger.disabled = captureLocked || Boolean(pendingCapture)', $javascript);
        $this->assertStringContainsString('elements.openManualWeight.disabled = captureLocked || Boolean(pendingCapture)', $javascript);
        $this->assertStringContainsString('function openDirectPriceEditor()', $javascript);
        $this->assertStringContainsString('general_prices', $javascript);
        $this->assertStringNotContainsString('data-retail-clear-client', $javascript);
        $this->assertStringNotContainsString('Venta sin cliente', $javascript);
        $this->assertStringContainsString('const PUBLIC_SALE_LABEL = "Venta público";', $javascript);
        $this->assertStringContainsString('function isPublicSale(list = activeList())', $javascript);
        $this->assertStringContainsString('|| (!current.clientId && !publicSale);', $javascript);
        $this->assertStringContainsString('Asigna un cliente antes de grabar.', $javascript);
        $this->assertStringContainsString('client_id: list.clientId ? Number(list.clientId) : null', $javascript);
        $this->assertStringContainsString('function basePrice(list, chickenTypeCode)', $javascript);
        $this->assertStringContainsString('sistema-pollos-retail-typography-v1', $javascript);
        $this->assertStringContainsString('data-typography-step', $javascript);
        $this->assertStringContainsString('document.documentElement.style.setProperty', $javascript);
        $this->assertStringContainsString('localStorage.setItem(TYPOGRAPHY_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('addWeighingToList(pendingCapture.listIndex, pendingCapture.reading)', $javascript);
        $this->assertStringContainsString('state.pendingCapture = {', $javascript);
        $this->assertStringContainsString('` Registrar en lista ${state.activeList + 1}`', $javascript);
        $this->assertStringNotContainsString('lastCapturedReadingId', $javascript);
        $this->assertStringNotContainsString('alreadyCaptured', $javascript);
        $this->assertStringNotContainsString('Esta lectura ya fue capturada', $javascript);
        $this->assertStringContainsString('selectList(addButton.dataset.retailAddList)', $javascript);
        $this->assertStringContainsString('values.trayCount < 0', $javascript);
        $this->assertStringContainsString('elements.birdsPerTray.value = birdsOption.dataset.retailBirdsPerTrayOption', $javascript);
        $this->assertStringContainsString('openModal(elements.birdsPerTrayModal)', $javascript);
        $this->assertStringContainsString('elements.birdsPerTrayModal,', $javascript);
        $this->assertStringContainsString('["A", "S", "D", "F", "G", "H", "J", "K", "L", "Ñ"]', $javascript);
        $this->assertStringContainsString('["Á", "É", "Í", "Ó", "Ú", "Ü", "-", "/", "."]', $javascript);
        $this->assertStringContainsString('function openTouchKeyboard(input, options = {})', $javascript);
        $this->assertStringContainsString('data-retail-keyboard-key=" ">Espacio', $javascript);
        $this->assertStringContainsString('const current = touchKeyboardState.buffer;', $javascript);
        $this->assertStringContainsString('const next = `${current}${key}`;', $javascript);
        $this->assertStringContainsString('setTouchKeyboardInputValue(current.slice(0, -1));', $javascript);
        $this->assertStringContainsString('target.dispatchEvent(new Event("input", { bubbles: true }))', $javascript);
        $this->assertStringNotContainsString('data-retail-keyboard="text"', $javascript);
        $this->assertStringContainsString('directPriceInput: document.querySelector("#retailDirectPriceInput")', $javascript);
        $this->assertStringContainsString('data-retail-keyboard="integer"', $javascript);
        $this->assertStringContainsString('const MONEY_DECIMALS = 2;', $javascript);
        $this->assertStringContainsString('function roundMoney(value)', $javascript);
        $this->assertStringContainsString('function moneyToCents(value)', $javascript);
        $this->assertStringContainsString('function lineAmount(list, item)', $javascript);
        $this->assertStringContainsString('function normalizedTouchKeyboardValue()', $javascript);
        $this->assertStringContainsString('acceptedKey = key.slice(0, remaining);', $javascript);
        $this->assertStringNotContainsString('importe: formatMoneyValue(row.amount)', $javascript);
        $this->assertStringContainsString('[code, formatMoneyValue(value)]', $javascript);
        $this->assertStringNotContainsString('toFixed(4)', $javascript);
        $this->assertStringNotContainsString('step="0.0001"', $javascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $javascript);
        $this->assertStringContainsString('function requiresDelivery(list)', $javascript);
        $this->assertStringContainsString('delivery_trucks', $javascript);
        $this->assertStringContainsString('delivery_drivers', $javascript);
        $this->assertStringContainsString('delivery,', $javascript);
        $this->assertStringContainsString('clearRegisteredList(listIndex, list.draftId, ticket);', $javascript);
        $this->assertStringContainsString('await printTicketAndReport(ticket);', $javascript);
        $this->assertStringContainsString('state.pendingPrintTicket = ticket;', $javascript);
        $this->assertStringContainsString('elements.retryPrint.addEventListener', $javascript);
        $this->assertStringNotContainsString('state.captured', $javascript);
        $this->assertStringNotContainsString('g adicionales', $javascript);
        $this->assertStringNotContainsString('type.code.replaceAll', $javascript);
        $this->assertStringNotContainsString('cage_type_code', $javascript);
        $this->assertStringNotContainsString('cantidad_javas', $javascript);

        $weightCalculationJavascript = file_get_contents(public_path('js/retail-weight-calculation.js'));
        $this->assertIsString($weightCalculationJavascript);
        $this->assertStringContainsString('Math.max(trays, 1)', $weightCalculationJavascript);
        $this->assertStringContainsString('totalAdjustmentGrams: adjustmentGrams * birds', $weightCalculationJavascript);
        $this->assertStringContainsString('RETAIL_PROCESSED_CHICKEN_CODE', $weightCalculationJavascript);

        $blade = file_get_contents(resource_path('views/despacho-minorista.blade.php'));
        $this->assertIsString($blade);
        preg_match_all('/<input\b[^>]*>/i', $blade, $retailInputs);
        foreach ($retailInputs[0] as $input) {
            if (str_contains($input, 'type="hidden"')) {
                continue;
            }
            $this->assertStringContainsString('data-retail-keyboard=', $input);
            $this->assertStringContainsString('inputmode="none"', $input);
            $this->assertStringContainsString('readonly', $input);
        }

        preg_match_all('/<input\b[^>]*type="(?:number|text)"[^>]*>/i', $javascript, $dynamicRetailInputs);
        foreach ($dynamicRetailInputs[0] as $input) {
            $this->assertStringContainsString('data-retail-keyboard=', $input);
            $this->assertStringContainsString('inputmode="none"', $input);
            $this->assertStringContainsString('readonly', $input);
        }

        $scaleJavascript = file_get_contents(public_path('js/despacho-minorista-balanza.js'));

        $this->assertIsString($scaleJavascript);
        $this->assertStringContainsString('sistema-pollos-retail-scale-v1', $scaleJavascript);
        $this->assertStringContainsString('connectBle', $scaleJavascript);
        $this->assertStringContainsString('connectSerial', $scaleJavascript);

        $stylesheet = file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('--rd-font-base:', $stylesheet);
        $this->assertStringContainsString('--rd-font-chicken-type:', $stylesheet);
        $this->assertStringContainsString('.rd-touch-keyboard-card', $stylesheet);
        $this->assertStringContainsString('.rd-touch-keyboard.is-numeric-entry .rd-touch-keyboard-head output', $stylesheet);
        $this->assertStringContainsString('--rd-font-presentation:', $stylesheet);
        $this->assertStringContainsString('--rd-font-table-cell:', $stylesheet);
        $this->assertStringContainsString('.rd-typography-drawer', $stylesheet);
        $this->assertStringContainsString('.rd-payment-default-settings', $stylesheet);
        $this->assertStringContainsString('.rd-payment-default-fields', $stylesheet);
        $this->assertStringContainsString('.rd-printer-settings', $stylesheet);
        $this->assertStringContainsString('.rd-printer-settings-link', $stylesheet);
        $this->assertStringContainsString('.rd-payment-mode-options', $stylesheet);
        $this->assertStringContainsString('.rd-payment-credit-panel', $stylesheet);
        $this->assertStringContainsString('.rd-modal-card.is-error', $stylesheet);
        $this->assertStringContainsString('.rd-error-details', $stylesheet);
        $this->assertStringContainsString('[data-retail-station="1"] .rd-lists-stage', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(8, minmax(0, 1fr));', $stylesheet);
        $this->assertStringContainsString('width: calc(200% + 5px);', $stylesheet);
        $this->assertDoesNotMatchRegularExpression('/font-size:\s*(?:\d|\.)/', $stylesheet);
    }

    public function test_login_view_provides_a_reusable_touch_keyboard_for_credentials(): void
    {
        auth()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('id="loginTouchKeyboard"', false)
            ->assertSee('data-touch-keyboard-input="email"', false)
            ->assertSee('data-touch-keyboard-input="password"', false)
            ->assertSee('inputmode="none"', false)
            ->assertSee('Teclado táctil')
            ->assertSee(asset('js/touch-keyboard.js'), false)
            ->assertSee(asset('css/touch-keyboard.css'), false);

        $javascript = file_get_contents(public_path('js/touch-keyboard.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('state.target.type === "password"', $javascript);
        $this->assertStringContainsString('"•".repeat(state.buffer.length)', $javascript);
        $this->assertStringContainsString('["!", "@", "#", "$", "%", "^", "&", "*", "(", ")"]', $javascript);
        $this->assertStringContainsString('data-touch-keyboard-action="symbols"', $javascript);
        $this->assertStringContainsString('case "backspace"', $javascript);
        $this->assertStringContainsString('case "accept"', $javascript);
    }

    public function test_second_retail_dispatch_view_uses_an_independent_station_namespace(): void
    {
        $response = $this->get('/despacho-minorista-2')
            ->assertOk()
            ->assertSee('Despacho minorista 2')
            ->assertSee('data-retail-station="2"', false)
            ->assertSee('data-retail-api-base="/despacho-minorista-2"', false)
            ->assertSee('id="retailAdjustments" class="rd-adjustment-buttons" role="group" aria-label="Seleccionar la columna de producto y presentación"', false)
            ->assertDontSee('id="retailChickenTypes"', false)
            ->assertDontSee('Una columna para cada producto')
            ->assertDontSee('Selecciona el botón ubicado sobre la columna donde registrarás la pesada.')
            ->assertDontSee('id="retailListSelectionHint"', false)
            ->assertDontSee('class="rd-selection-bar"', false)
            ->assertSee('id="retailPriceCard"', false)
            ->assertSee('Precio asignado')
            ->assertSee('Toca para cambiar el precio de la jornada')
            ->assertSee('id="retailGrossPreview"', false)
            ->assertSee('id="retailNetPreview"', false)
            ->assertSee('id="retailWeighingTotalPreview"', false)
            ->assertSee('<span>Total</span>', false)
            ->assertDontSee('Total de la pesada')
            ->assertDontSee('id="retailTarePreview"', false)
            ->assertDontSee('id="retailTareDetail"', false)
            ->assertSee('id="retailOpenManualWeight"', false)
            ->assertSee('Colocar peso manual')
            ->assertSee('aria-controls="retailTouchKeyboard"', false)
            ->assertDontSee('retailManualWeightModal', false)
            ->assertDontSee('Seleccionar lista 1')
            ->assertDontSee('data-retail-add-list=', false)
            ->assertDontSee('id="retailDefaultPaymentMethod"', false)
            ->assertDontSee('id="retailDefaultPaymentAccount"', false)
            ->assertDontSee('id="retailPaymentModeOptions"', false)
            ->assertDontSee('data-retail-payment-mode=', false)
            ->assertSee('Grabar')
            ->assertDontSee('A crédito · cobro en Finanzas')
            ->assertSee(asset('js/despacho-minorista.js'), false)
            ->assertSee(asset('css/despacho-minorista.css'), false);

        $stationTwoHtml = (string) $response->getContent();
        $this->assertMatchesRegularExpression(
            '/id="retailPriceCard"[\s\S]*id="retailGrossPreview"[\s\S]*id="retailNetPreview"[\s\S]*id="retailWeighingTotalPreview"/',
            $stationTwoHtml
        );

        $this->get('/despacho-minorista')
            ->assertOk()
            ->assertSee('id="retailTarePreview"', false)
            ->assertSee('id="retailTareDetail"', false)
            ->assertDontSee('id="retailWeighingTotalPreview"', false)
            ->assertDontSee('Total de la pesada');

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('station-${RETAIL_STATION}-branch', $javascript);
        $this->assertStringContainsString('station-${RETAIL_STATION}`', $javascript);
        $this->assertStringContainsString('state.catalog.scale?.code || "BALANZA_MINORISTA"', $javascript);
        $this->assertStringContainsString('STATION_2_LIST_ADJUSTMENT_CODES', $javascript);
        $this->assertStringContainsString('"MACHO_CERRADO"', $javascript);
        $this->assertStringContainsString('"MACHO_ABIERTO"', $javascript);
        $this->assertStringContainsString('"HEMBRA_CERRADA"', $javascript);
        $this->assertStringContainsString('"HEMBRA_ABIERTA"', $javascript);
        $this->assertStringContainsString('const STATION_2_PROCESSED_LIST_INDEX = 0;', $javascript);
        $this->assertStringContainsString('const STATION_2_LIST_LAYOUT_VERSION = "processed-first-v1";', $javascript);
        $this->assertStringContainsString('data-retail-list-processed=', $javascript);
        $this->assertStringContainsString('elements.priceCard?.addEventListener("click", openDirectPriceEditor)', $javascript);
        $this->assertStringContainsString('async function applyDirectJourneyPrice(', $javascript);
        $this->assertStringContainsString('apiRequest("/operacion/precios-jornada", {', $javascript);
        $this->assertStringContainsString('[chickenTypeCode]: formatMoneyValue(normalizedValue)', $javascript);
        $this->assertStringContainsString('[chickenTypeCode]: expectedJourneyPrice === null', $javascript);
        $this->assertStringContainsString('syncJourneyPrice(chickenTypeCode, savedValue);', $javascript);
        $this->assertStringContainsString('const usesClientPrice = priceSource === "CLIENTE" && Boolean(client);', $javascript);
        $this->assertStringContainsString('priceSource === "GENERAL"', $javascript);
        $this->assertStringContainsString('`Precio de la jornada de ${chickenName}`', $javascript);
        $this->assertStringContainsString('const editorPrice = price || journeyPrice;', $javascript);
        $this->assertStringContainsString('elements.directPriceInput.value = editorPrice ? formatMoneyValue(editorPrice.value) : "";', $javascript);
        $this->assertStringContainsString('async function refreshJourneyPrices(options = {})', $javascript);
        $this->assertStringContainsString('await refreshJourneyPrices({ force: true });', $javascript);
        $this->assertStringContainsString('async function refreshRetailCatalogPrices()', $javascript);
        $this->assertStringContainsString('await refreshRetailCatalogPrices();', $javascript);
        $this->assertStringContainsString('"Revisa el nuevo total antes de grabar"', $javascript);
        $this->assertStringContainsString('function priceChickenTypeForList(list = activeList())', $javascript);
        $this->assertStringContainsString('listIndex: listIndex + 1', $javascript);
        $this->assertStringContainsString('processedButton + adjustmentButtons', $javascript);
        $this->assertStringContainsString('storedPayload?.layoutVersion !== STATION_2_LIST_LAYOUT_VERSION', $javascript);
        $this->assertStringContainsString('syncStation2ChickenTypeWithActiveList()', $javascript);
        $this->assertStringContainsString('selectList(listIndex);', $javascript);
        $this->assertStringContainsString('if (state.activeList !== listIndex) return;', $javascript);
        $this->assertStringContainsString('syncStation2AdjustmentWithActiveList()', $javascript);
        $this->assertStringContainsString('state.adjustmentCode = "";', $javascript);
        $this->assertStringContainsString('data-retail-list-adjustment=', $javascript);
        $this->assertStringContainsString('"Columna activa: Pollo beneficiado sin merma."', $javascript);
        $this->assertStringContainsString('`Pollo pelado · columna activa: ${activeFixedAdjustment.name}.`', $javascript);
        $this->assertStringContainsString('"Columna activa sin presentación disponible."', $javascript);
        $this->assertStringContainsString('"Peso directo de balanza · ajuste no disponible"', $javascript);
        $this->assertStringContainsString('calculationsAvailable && price && values.netWeight > 0', $javascript);
        $this->assertStringContainsString('elements.weighingTotalPreview.textContent = liveAmount === null ? "S/ --" : formatMoney(liveAmount);', $javascript);
        $this->assertStringNotContainsString('fixedAdjustment.additional_grams', $javascript);

        $stylesheet = file_get_contents(public_path('css/despacho-minorista.css'));
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('.rd-product-selection', $stylesheet);
        $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr));', $stylesheet);
        $this->assertStringContainsString('[data-retail-station="2"] .rd-lists-stage', $stylesheet);
        $this->assertStringContainsString('[data-retail-station="2"].rd-station', $stylesheet);
        $this->assertStringContainsString('grid-template-rows: 52px 202px minmax(0, 1fr) 30px;', $stylesheet);
        $this->assertStringContainsString('width: calc(125% + 2px);', $stylesheet);
        $this->assertStringContainsString('.rd-adjustment-buttons button.is-processed.is-active', $stylesheet);
    }

    public function test_both_retail_dispatch_views_offer_direct_buttons_for_one_to_eight_trays(): void
    {
        foreach (['/despacho-minorista', '/despacho-minorista-2'] as $url) {
            $response = $this->get($url)
                ->assertOk()
                ->assertSee('data-retail-quick-tray-option="1"', false)
                ->assertSee('data-retail-quick-tray-option="8"', false)
                ->assertDontSee('data-retail-quick-tray-option="9"', false)
                ->assertDontSee('<span>Cantidad de bandejas</span>', false)
                ->assertDontSee('Toca el número para cambiar');

            $this->assertSame(
                8,
                preg_match_all(
                    '/data-retail-quick-tray-option="[1-8]"/',
                    (string) $response->getContent()
                )
            );
        }

        $javascript = (string) file_get_contents(public_path('js/despacho-minorista.js'));
        $stylesheet = (string) file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertStringContainsString(
            'const quickTrayOption = event.target.closest("[data-retail-quick-tray-option]");',
            $javascript
        );
        $this->assertStringContainsString(
            'elements.trayCount.value = quickTrayOption.dataset.retailQuickTrayOption;',
            $javascript
        );
        $this->assertStringContainsString('.rd-tray-quick-options', $stylesheet);
        $this->assertStringContainsString('.rd-tray-quick-option.is-active', $stylesheet);
    }

    public function test_both_retail_dispatch_views_allow_selecting_up_to_forty_birds(): void
    {
        foreach (['/despacho-minorista', '/despacho-minorista-2'] as $url) {
            $response = $this->get($url)
                ->assertOk()
                ->assertSee('class="rd-modal-card is-compact is-bird-quantity"', false)
                ->assertSee('data-retail-birds-per-tray-option="1"', false)
                ->assertSee('data-retail-birds-per-tray-option="40"', false)
                ->assertDontSee('data-retail-birds-per-tray-option="41"', false);

            $content = (string) $response->getContent();
            $this->assertSame(
                40,
                preg_match_all('/data-retail-birds-per-tray-option="\d+"/', $content),
            );
        }

        $javascript = (string) file_get_contents(public_path('js/despacho-minorista.js'));
        $stylesheet = (string) file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertStringContainsString('const MAX_RETAIL_BIRD_QUANTITY = 40;', $javascript);
        $this->assertStringContainsString('values.birdsPerTray > MAX_RETAIL_BIRD_QUANTITY', $javascript);
        $this->assertStringContainsString('debe estar entre 1 y ${MAX_RETAIL_BIRD_QUANTITY}', $javascript);
        $this->assertStringContainsString('.rd-modal-card.is-bird-quantity', $stylesheet);
        $this->assertStringContainsString('.rd-birds-per-tray-options', $stylesheet);
    }

    public function test_both_retail_dispatches_save_with_deferred_transport_without_opening_the_modal(): void
    {
        foreach (['/despacho-minorista', '/despacho-minorista-2'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Grabar')
                ->assertDontSee('Grabar e imprimir')
                ->assertDontSee('A crédito · cobro en Finanzas');
        }

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString(
            'buildRetailDeliveryPayload(RETAIL_DELIVERY_MODE_PENDING_ASSIGNMENT)',
            $javascript
        );
        $this->assertMatchesRegularExpression(
            '/function continueDispatchRegistration\(\) \{[\s\S]+?const delivery = requiresDelivery\(list\)[\s\S]+?void saveDispatch\(delivery\);[\s\S]+?\}/',
            $javascript
        );
        $this->assertMatchesRegularExpression(
            '/response = await apiRequest\([\s\S]+?catch \(error\) \{[\s\S]+?showRetailError\(presentation\);[\s\S]+?return;[\s\S]+?const ticket = response\.data;[\s\S]+?await printTicketAndReport\(ticket\);/',
            $javascript
        );
        $this->assertStringNotContainsString('state.pendingPayments', $javascript);
    }

    public function test_both_retail_dispatch_views_confirm_before_removing_a_weighing(): void
    {
        foreach (['/despacho-minorista', '/despacho-minorista-2'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('id="retailRemoveWeighingModal"', false)
                ->assertSee('role="alertdialog"', false)
                ->assertSee('id="retailRemoveWeighingPreview"', false)
                ->assertSee('¿Eliminar esta pesada?')
                ->assertSee('Sí, eliminar pesada');
        }

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('function openRemoveWeighingModal()', $javascript);
        $this->assertStringContainsString('function confirmRemoveSelectedWeighing()', $javascript);
        $this->assertStringContainsString('elements.removeWeighing.addEventListener("click", openRemoveWeighingModal)', $javascript);
        $this->assertStringContainsString('elements.confirmRemoveWeighing.addEventListener("click", confirmRemoveSelectedWeighing)', $javascript);
        $this->assertStringContainsString('<dt>Peso leído</dt>', $javascript);
        $this->assertStringContainsString('<dt>Peso neto</dt>', $javascript);
        $this->assertStringContainsString('<dt>Importe</dt>', $javascript);
    }

    public function test_retail_dispatch_views_share_one_editor_with_station_specific_price_scope(): void
    {
        foreach (['/despacho-minorista', '/despacho-minorista-2'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('id="retailAssignPrice"', false)
                ->assertSee('Cambiar precio')
                ->assertSee('aria-controls="retailTouchKeyboard"', false)
                ->assertSee('id="retailDirectPriceInput"', false)
                ->assertSee('Teclado táctil')
                ->assertDontSee('id="retailPriceModal"', false);
        }

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('function basePrice(list, chickenTypeCode)', $javascript);
        $this->assertStringContainsString('return clientPrice || currentGeneralPrice(chickenTypeCode);', $javascript);
        $this->assertMatchesRegularExpression(
            '/function effectivePrice[\s\S]+?const override = list\.priceOverrides[\s\S]+?return basePrice\(list, chickenTypeCode\);/',
            $javascript
        );
        $this->assertStringContainsString('return { value, source: "MANUAL" };', $javascript);
        $this->assertStringContainsString('function applyDirectTicketPrice(listIndex, chickenTypeCode, baseValue, rawValue)', $javascript);
        $this->assertStringNotContainsString('function applyDirectPrice(', $javascript);
        $this->assertStringContainsString('function openDirectPriceEditor()', $javascript);
        $this->assertStringContainsString('function openTicketPriceEditor()', $javascript);
        $this->assertStringContainsString('const editorPrice = price || journeyPrice;', $javascript);
        $this->assertStringContainsString('acceptHandler: (value) => applyDirectJourneyPrice(', $javascript);
        $this->assertStringContainsString('acceptHandler: (value) => applyDirectTicketPrice(', $javascript);
        $this->assertStringContainsString('openTouchKeyboard(elements.directPriceInput, {', $javascript);
        $this->assertStringContainsString('lockStation: true,', $javascript);
        $this->assertStringContainsString('async function handleTouchKeyboardAction(action)', $javascript);
        $this->assertStringContainsString('const accepted = await acceptHandler?.(value);', $javascript);
        $this->assertStringContainsString('if (touchKeyboardState.target !== target || elements.touchKeyboard?.hidden) return;', $javascript);
        $this->assertStringContainsString('if (touchKeyboardState.accepting) {', $javascript);
        $this->assertStringContainsString('button.disabled = touchKeyboardState.accepting || button.classList.contains("is-disabled");', $javascript);
        $this->assertStringContainsString('function trapTabWithin(container, event)', $javascript);
        $this->assertStringContainsString('trapTabWithin(elements.touchKeyboard, event);', $javascript);
        $this->assertStringContainsString(
            '.querySelector(\'[data-retail-keyboard-action="cancel"]\')',
            $javascript
        );
        $this->assertStringContainsString('knownCodes.length === 1', $javascript);
        $this->assertStringContainsString('delete nextOverrides[chickenTypeCode];', $javascript);
        $this->assertStringContainsString('nextOverrides[chickenTypeCode] = normalizedValue;', $javascript);
        $this->assertStringContainsString('moneyToCents(normalizedValue) === moneyToCents(baseValue)', $javascript);
        $this->assertStringContainsString('elements.assignPrice.addEventListener("click", openTicketPriceEditor)', $javascript);
        $this->assertStringContainsString('elements.priceCard?.addEventListener("click", openDirectPriceEditor)', $javascript);
        $this->assertStringNotContainsString('function renderPriceFields()', $javascript);
        $this->assertStringNotContainsString('elements.priceForm', $javascript);
        $this->assertStringContainsString('if (String(list.clientId) !== String(client.id)) {', $javascript);
        $this->assertStringNotContainsString('list.priceOverrides = {};', $javascript);
        $this->assertStringContainsString(
            'const TICKET_PRICE_OVERRIDE_VERSION = RETAIL_STATION === "2" ? 2 : 1;',
            $javascript
        );
        $this->assertStringContainsString(
            'priceOverrides: supportsTicketOverrides ? normalizedPriceOverrides : {}',
            $javascript
        );
        $this->assertStringContainsString(
            'ticketPriceOverrideVersion: TICKET_PRICE_OVERRIDE_VERSION',
            $javascript
        );
        $this->assertStringContainsString('priceOverrides: list.priceOverrides,', $javascript);
        $this->assertStringContainsString('price_overrides: priceOverrides,', $javascript);
        $this->assertStringContainsString('...(RETAIL_STATION === "2" ? { expected_prices: expectedPrices } : {}),', $javascript);
        $this->assertStringContainsString('state.lists[listIndex] = emptyList();', $javascript);
        $this->assertStringNotContainsString('const priceOverrides = list.clientId', $javascript);
        $this->assertStringNotContainsString('El precio del cliente no se puede reemplazar', $javascript);
    }

    public function test_operation_view_is_available_without_database_queries(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('Entrada de Camiones de Pollos')
            ->assertSee('<select id="truckPlate"', false)
            ->assertSee('Seleccionar balanza 1')
            ->assertSee('Seleccionar balanza 2')
            ->assertSee('id="addWeighingBtn"', false)
            ->assertSee('Capturar peso')
            ->assertDontSee('Precio general pollo')
            ->assertDontSee('generalPriceVivoKg', false)
            ->assertSee('type="module"', false)
            ->assertSee(asset('js/app.js'), false);

        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('from "./record-order.js"', $javascript);
        $this->assertStringContainsString('newestRecordsFirst(truck.cages)', $javascript);
        $this->assertStringContainsString('function captureWeightForRegistration(event)', $javascript);
        $this->assertStringContainsString('function handleWeighingAction(event)', $javascript);
        $this->assertStringContainsString('? "Registrar pesada"', $javascript);
        $this->assertStringContainsString(': "Capturar peso"', $javascript);
    }

    public function test_operation_javascript_does_not_render_or_send_prices(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('general_prices', $javascript);
        $this->assertStringNotContainsString('Total S/', $javascript);
        $this->assertStringNotContainsString('Precios cliente', $javascript);
        $this->assertStringNotContainsString('Precios generales', $javascript);
        $this->assertStringNotContainsString('response.data?.prices', $javascript);
    }

    public function test_operation_weighing_has_an_exclusive_touch_sex_selector(): void
    {
        $view = file_get_contents(resource_path('views/operacion.blade.php'));
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($view);
        $this->assertIsString($javascript);
        $this->assertStringContainsString('data-sex="macho"', $view);
        $this->assertStringContainsString('data-sex="hembra"', $view);
        $this->assertStringContainsString('aria-pressed="true"', $view);
        $this->assertStringContainsString('function getSuggestedChickenSex', $javascript);
        $this->assertStringContainsString('birdCount === 7', $javascript);
        $this->assertStringContainsString('birdCount === 9', $javascript);
        $this->assertStringContainsString('chicken_sex: getChickenSexMeta(cage.chickenSex).apiCode', $javascript);
        $this->assertStringContainsString('class="truck-head-sex">Sexo', $javascript);
        $this->assertStringContainsString('chicken-sex-badge', $javascript);
    }

    public function test_selected_dispatch_totals_show_compact_bird_counts_by_sex(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('maleBirds: birdsBySex.macho', $javascript);
        $this->assertStringContainsString('femaleBirds: birdsBySex.hembra', $javascript);
        $this->assertStringContainsString('M: ${totals.maleBirds} | H: ${totals.femaleBirds}', $javascript);
        $this->assertStringContainsString('class="selected-truck-sex-counts"', $javascript);
    }

    public function test_operation_records_show_gross_weight_at_the_left(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);

        $headerId = strpos($javascript, '<th class="truck-head-id">#</th>');
        $headerGross = strpos($javascript, '<th class="truck-head-weight truck-head-gross-weight">Bruto</th>');
        $headerType = strpos($javascript, '<th class="truck-head-type">Tipo</th>');
        $rowId = strpos($javascript, '<td class="truck-cell-id">${escapeHtml(cage.id)}</td>');
        $rowGross = strpos($javascript, '<td class="truck-cell-weight truck-cell-gross-weight">${grossWeight.toFixed(2)}</td>');
        $rowType = strpos($javascript, '<td class="truck-cell-type">${typeTag}</td>');

        $this->assertNotFalse($headerId);
        $this->assertNotFalse($headerGross);
        $this->assertNotFalse($headerType);
        $this->assertNotFalse($rowId);
        $this->assertNotFalse($rowGross);
        $this->assertNotFalse($rowType);
        $this->assertTrue($headerId < $headerGross && $headerGross < $headerType);
        $this->assertTrue($rowId < $rowGross && $rowGross < $rowType);
    }

    public function test_all_operation_numeric_fields_use_the_touch_keypad(): void
    {
        $view = file_get_contents(resource_path('views/operacion.blade.php'));

        $this->assertIsString($view);
        preg_match_all('/<input\\b[^>]*\\btype="number"[^>]*>/i', $view, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $numericInput) {
            $this->assertStringContainsString('readonly', $numericInput);
            $this->assertStringContainsString('inputmode="none"', $numericInput);
            $this->assertStringContainsString('data-keypad-label=', $numericInput);
        }

        $this->assertStringContainsString('id="numericPadMessage"', $view);
        $this->assertStringNotContainsString('touch-number-open', $view);
        $this->assertStringNotContainsString('data-keypad-target=', $view);
    }

    public function test_operation_ticket_type_button_switches_between_dispatch_and_return(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $toggleStart = strpos($javascript, 'function toggleTicketOperation()');
        $toggleEnd = strpos($javascript, 'function openClientModal', $toggleStart);
        $this->assertNotFalse($toggleStart);
        $this->assertNotFalse($toggleEnd);

        $toggle = substr($javascript, $toggleStart, $toggleEnd - $toggleStart);
        $this->assertStringContainsString('TICKET_OPERATIONS.DISPATCH', $toggle);
        $this->assertStringContainsString('TICKET_OPERATIONS.RETURN', $toggle);
        $this->assertStringContainsString('originId: null', $toggle);
        $this->assertStringContainsString('truck.cages = [];', $toggle);

        $this->assertStringContainsString('is-dispatch-action', $javascript);
        $this->assertStringContainsString('is-return-action', $javascript);
    }

    public function test_dispatch_print_template_uses_the_control_weight_format(): void
    {
        $javascript = file_get_contents(public_path('js/ticket-printer.js'));

        $this->assertIsString($javascript);
        $templateStart = strpos($javascript, 'function buildWeightControlTicketHtml');
        $templateEnd = strpos($javascript, 'function printWeightControlTicket', $templateStart);
        $this->assertNotFalse($templateStart);
        $this->assertNotFalse($templateEnd);

        $template = substr($javascript, $templateStart, $templateEnd - $templateStart);

        $this->assertStringContainsString('const ticketTitle = getTicketTitle(ticket);', $template);
        $this->assertStringContainsString('<h1 class="business-name">${escapeTicketHtml(ticketTitle)}</h1>', $template);
        $this->assertStringContainsString('CONTROL DE PESO', $template);
        $this->assertStringContainsString('<th>C/A</th>', $template);
        $this->assertStringContainsString('<th>CJ</th>', $template);
        $this->assertStringContainsString('PESO<br>BRUTO', $template);
        $this->assertStringContainsString('PESO<br>TARA', $template);
        $this->assertStringContainsString('<p>OBSERV:</p>', $template);
        $this->assertStringContainsString('body {', $template);
        $this->assertStringContainsString('font-size: 18px', $template);
        $this->assertStringContainsString('font-size: 17px', $template);
        $this->assertStringContainsString('font-size: 19px', $template);
        $this->assertStringContainsString('font-weight: 700', $template);
        $this->assertStringNotContainsString('font-size: 9.5px', $template);
        $this->assertStringNotContainsString('CONTROL PESO', $template);
        $this->assertStringNotContainsString('P.NETO', $template);
        $this->assertStringNotContainsString('TOTAL AVES:', $template);
        $this->assertStringNotContainsString('PLACA:', $template);
        $this->assertStringNotContainsString('ORIGEN:', $template);
    }

    public function test_retail_dispatch_print_template_matches_the_weight_control_receipt(): void
    {
        $javascript = file_get_contents(public_path('js/ticket-printer.js'));

        $this->assertIsString($javascript);
        $templateStart = strpos($javascript, 'function buildRetailWeightControlTicketHtml');
        $templateEnd = strpos($javascript, 'export function buildWeightControlTicketHtml', $templateStart);
        $this->assertNotFalse($templateStart);
        $this->assertNotFalse($templateEnd);

        $template = substr($javascript, $templateStart, $templateEnd - $templateStart);

        $this->assertStringContainsString('const ticketTitle = getTicketTitle(ticket);', $template);
        $this->assertStringContainsString('<h1 class="business-name">${escapeTicketHtml(ticketTitle)}</h1>', $template);
        $this->assertStringContainsString('GALLINA</p>', $template);
        $this->assertStringContainsString('GD</p>', $template);
        $this->assertStringContainsString('CONTROL DE PESO', $template);
        $this->assertStringContainsString('formatTicketDate(ticket?.operatingDate, safePrintDate)', $template);
        $this->assertStringContainsString('<th>C/A</th>', $template);
        $this->assertStringContainsString('<th>C.J</th>', $template);
        $this->assertStringContainsString('PESO<br>BRUTO', $template);
        $this->assertStringContainsString('PESO<br>TARA', $template);
        $this->assertStringContainsString('CONTROL<br>PESO', $template);
        $this->assertStringContainsString('<th>PESO</th><th>AVES</th><th>MERM</th>', $template);
        $this->assertStringContainsString('<th>P.NETO</th><th>PRE.</th><th>SOLES</th>', $template);
        $this->assertStringContainsString('total + record.readWeight - record.tareWeight', $template);
        $this->assertStringContainsString('total + record.adjustmentWeight', $template);
        $this->assertStringContainsString('total + record.netWeight', $template);
        $this->assertStringContainsString('? "VARIOS"', $template);
        $this->assertStringContainsString('Boolean(deliveryVehicle || deliveryDriver)', $template);
        $this->assertStringContainsString('deliveryVehicle.plate', $template);
        $this->assertStringContainsString('deliveryDriver.name', $template);
        $this->assertStringContainsString('<p>OBSERV:</p>', $template);
        $this->assertStringContainsString('<p>NOMBRE:</p>', $template);
        $this->assertStringContainsString('<p>FIRMA:</p>', $template);
        $this->assertStringNotContainsString('TRANSPORTE: RETIRO DIRECTO', $template);
    }

    public function test_dispatch_print_keeps_the_selected_delivery_truck_and_driver(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $normalizationStart = strpos($javascript, 'function normalizeTicketRegistration');
        $normalizationEnd = strpos($javascript, 'function createDefaultTruck', $normalizationStart);
        $printDataStart = strpos($javascript, 'function buildDispatchTicketData');
        $printDataEnd = strpos($javascript, 'function printDispatchTicket', $printDataStart);
        $this->assertNotFalse($normalizationStart);
        $this->assertNotFalse($normalizationEnd);
        $this->assertNotFalse($printDataStart);
        $this->assertNotFalse($printDataEnd);

        $normalization = substr($javascript, $normalizationStart, $normalizationEnd - $normalizationStart);
        $printData = substr($javascript, $printDataStart, $printDataEnd - $printDataStart);

        $this->assertStringContainsString('delivery: registration?.delivery', $normalization);
        $this->assertStringContainsString('plate: String(registration.delivery.vehicle.plate', $normalization);
        $this->assertStringContainsString('name: String(registration.delivery.driver.name', $normalization);
        $this->assertStringContainsString('delivery: registration?.delivery || null', $printData);
        $this->assertStringContainsString('emittedAt: registration?.registeredAt || null', $printData);
    }

    public function test_printing_a_registered_ticket_clears_its_dispatch_column(): void
    {
        $javascript = file_get_contents(public_path('js/app.js'));

        $this->assertIsString($javascript);
        $printStart = strpos($javascript, 'function printDispatchTicket');
        $printEnd = strpos($javascript, 'function buildDispatchTicketPayload', $printStart);
        $this->assertNotFalse($printStart);
        $this->assertNotFalse($printEnd);

        $printFlow = substr($javascript, $printStart, $printEnd - $printStart);
        $this->assertStringContainsString('onSuccess: () => clearRegisteredTruckColumn(', $printFlow);
        $this->assertStringContainsString('onError:', $printFlow);
    }

    public function test_directory_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio')
            ->assertOk()
            ->assertSee('Clientes y proveedores')
            ->assertSee(asset('js/clientes.js'), false);
    }

    public function test_company_fleet_view_shows_all_trucks_with_their_provider_assignment(): void
    {
        $this->get('/flota')
            ->assertOk()
            ->assertSee('Flota de la empresa')
            ->assertSee('Camiones registrados')
            ->assertSee('Camiones de la empresa')
            ->assertSee('Choferes de la empresa')
            ->assertSee('Todos son propios; con o sin proveedor')
            ->assertSee('incluidos los asignados a proveedores')
            ->assertSee('id="truckPlate"', false)
            ->assertSee('id="driverName"', false)
            ->assertSee(asset('js/flota.js'), false)
            ->assertSee(route('menu'), false);

        $javascript = file_get_contents(public_path('js/flota.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('apiRequest(`/${state.activeType}', $javascript);
        $this->assertStringContainsString('camiones', $javascript);
        $this->assertStringContainsString('choferes', $javascript);
        $this->assertStringContainsString('record.assigned_provider?.name', $javascript);
        $this->assertStringContainsString('record.assigned_provider?.document', $javascript);
        $this->assertStringContainsString('Asignado a ${providerName}', $javascript);
        $this->assertStringContainsString('Sin proveedor asignado', $javascript);
        $this->assertStringNotContainsString('/proveedores/', $javascript);
        $this->assertStringNotContainsString('/clientes/', $javascript);
    }

    public function test_java_control_view_is_available_without_database_queries(): void
    {
        $this->get('/control-javas')
            ->assertOk()
            ->assertSee('Control de javas y bandejas')
            ->assertSee('id="trayCompanyInside"', false)
            ->assertSee('id="trayCompanyOutside"', false)
            ->assertSee('id="trayReceivedToday"', false)
            ->assertSee('Inventario y conteo')
            ->assertSee('Pendientes y devoluciones')
            ->assertSee('Trazabilidad por jornada')
            ->assertSee(route('control-javas.inventario'), false)
            ->assertSee(route('control-javas.devoluciones'), false)
            ->assertSee(route('control-javas.trazabilidad'), false)
            ->assertDontSee('id="javaInventoryOpen"', false)
            ->assertDontSee('id="javaReceiptForm"', false)
            ->assertDontSee('id="javaJourneyFilter"', false)
            ->assertSee(asset('js/control-javas.js'), false)
            ->assertSee(route('menu'), false);

        $this->get('/control-javas/inventario')
            ->assertOk()
            ->assertSee('Inventario y conteo físico')
            ->assertSee('Total propiedad de la empresa')
            ->assertSee('Para conteo directo')
            ->assertSee('id="javaInventoryOpen"', false)
            ->assertSee('id="javaInventoryModal"', false)
            ->assertSee('id="javaInventoryQuantity"', false)
            ->assertSee('id="trayInventoryQuantity"', false)
            ->assertSee('id="trayCompanyTotal"', false)
            ->assertSee('Local y camiones de la empresa')
            ->assertSee('Clientes que tienen javas o bandejas')
            ->assertSee('id="javaExternalHolderList"', false)
            ->assertSee('id="javaInternalHolderList"', false)
            ->assertSee('id="javaDailyForm"', false)
            ->assertSee('id="javaDailyLocalQuantity"', false)
            ->assertSee('id="trayDailyLocalQuantity"', false)
            ->assertSee('id="javaDailyTruckInputs"', false)
            ->assertSee('id="javaDailyAccountedTotal"', false)
            ->assertSee('id="trayDailyDifference"', false)
            ->assertSee('id="javaCountJourneyEyebrow"', false)
            ->assertSee('id="javaJourneyFilter"', false)
            ->assertSee('Seleccionar jornada del conteo')
            ->assertDontSee('id="javaDailyModal"', false)
            ->assertDontSee('id="javaClientRows"', false)
            ->assertSee(route('control-javas'), false);

        $this->get('/control-javas/devoluciones')
            ->assertOk()
            ->assertSee('Pendientes y devoluciones')
            ->assertSee('Javas y bandejas por devolver')
            ->assertSee('Registrar devolución')
            ->assertSee('id="javaReceiptClient"', false)
            ->assertSee('id="javaReceiptTruck"', false)
            ->assertSee('id="javaReceiptDriver"', false)
            ->assertSee('id="javaReceiptQuantity"', false)
            ->assertSee('id="trayReceiptQuantity"', false)
            ->assertSee('id="trayTotalPending"', false)
            ->assertSee('id="javaClientPagination"', false)
            ->assertSee('id="javaBalanceEditModal"', false)
            ->assertSee('id="javaBalanceEditForm"', false)
            ->assertSee('id="javaBalanceNewJavas"', false)
            ->assertSee('id="javaBalanceNewTrays"', false)
            ->assertSee('id="javaBalanceEditReason"', false)
            ->assertSee('Guardar corrección')
            ->assertDontSee('id="javaReceiptDate"', false)
            ->assertDontSee('id="javaInventoryOpen"', false)
            ->assertDontSee('id="javaJourneyFilter"', false)
            ->assertSee(route('control-javas'), false);

        $this->get('/control-javas/trazabilidad')
            ->assertOk()
            ->assertSee('Trazabilidad por jornada')
            ->assertSee('Activos que salieron')
            ->assertSee('Activos que entraron')
            ->assertSee('id="trayJourneyDispatched"', false)
            ->assertSee('id="trayJourneyReceived"', false)
            ->assertSee('id="trayJourneyNet"', false)
            ->assertSee('id="javaJourneyFilter"', false)
            ->assertSee('id="javaTruckActivityRows"', false)
            ->assertSee('<th>Chofer</th>', false)
            ->assertSee('<th>Registrado por</th>', false)
            ->assertSee('id="javaMovementRows"', false)
            ->assertSee('data-java-trace-tab="activity"', false)
            ->assertSee('data-java-trace-tab="movements"', false)
            ->assertDontSee('id="javaReceiptForm"', false)
            ->assertSee(route('control-javas'), false);

        $javascript = file_get_contents(public_path('js/control-javas.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('received_at', $javascript);
        $this->assertStringContainsString('data.clients_pagination', $javascript);
        $this->assertStringContainsString('data.client_options', $javascript);
        $this->assertStringContainsString('new URLSearchParams', $javascript);
        $this->assertStringContainsString('journey_id', $javascript);
        $this->assertStringContainsString('java_quantity: javaQuantity', $javascript);
        $this->assertStringContainsString('tray_quantity: trayQuantity', $javascript);
        $this->assertStringContainsString('local_java_quantity: draft.localJavas', $javascript);
        $this->assertStringContainsString('local_tray_quantity: draft.localTrays', $javascript);
        $this->assertStringContainsString('truck_counts: draft.trucks', $javascript);
        $this->assertStringContainsString('renderClientHolders()', $javascript);
        $this->assertStringContainsString('updateDailyReconciliation()', $javascript);
        $this->assertStringContainsString('java_balance: numericValue', $javascript);
        $this->assertStringContainsString('tray_balance: numericValue', $javascript);
        $this->assertStringContainsString('javaQuantity === 0 && trayQuantity === 0', $javascript);
        $this->assertStringContainsString('movement?.java_quantity, movement?.quantity', $javascript);
        $this->assertStringContainsString('data-edit-balance-client', $javascript);
        $this->assertStringContainsString('expected_java_balance: edit.javaBalance', $javascript);
        $this->assertStringContainsString('expected_tray_balance: edit.trayBalance', $javascript);
        $this->assertStringContainsString('method: "PATCH"', $javascript);
        $this->assertStringContainsString('movement.is_adjustment', $javascript);
        $this->assertStringContainsString('movement.created_by?.name', $javascript);
    }

    public function test_daily_tickets_view_is_available_without_database_queries(): void
    {
        $this->get('/tickets-dia')
            ->assertOk()
            ->assertSee('Resumen de la jornada')
            ->assertSee('id="dailyJourneyFilter"', false)
            ->assertSee('id="dailyJourneyDate"', false)
            ->assertSee('id="dailyJourneyWindow"', false)
            ->assertSee('Jornada a consultar')
            ->assertSee('id="dailyJourneyPrint"', false)
            ->assertSee('Imprimir jornada')
            ->assertSee('dailyClientTotals', false)
            ->assertSee('Bandejas')
            ->assertSee('id="dailyClientGrandTotal"', false)
            ->assertSee(route('menu'), false)
            ->assertSee('Menú')
            ->assertSee(asset('js/tickets-dia.js'), false)
            ->assertDontSee('id="dailyTicketDate"', false)
            ->assertDontSee('dailyOperationSummary', false)
            ->assertDontSee('dailyTicketsFilters', false)
            ->assertDontSee('dailyTypeTotals', false)
            ->assertDontSee('dailyTicketList', false)
            ->assertDontSee('Importe')
            ->assertDontSee('Precio/kg');

        $javascript = file_get_contents(public_path('js/tickets-dia.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('from "./daily-summary-printer.js"', $javascript);
        $this->assertStringContainsString('params.set("date", journeyDate.value)', $javascript);
        $this->assertStringContainsString('journeyDate?.value !== loadedJourneyDate', $javascript);
        $this->assertStringContainsString('windowLabel: loadedJourneyWindow', $javascript);
        $this->assertStringContainsString('data-print-weight=', $javascript);
        $this->assertStringContainsString('data-print-price=', $javascript);
        $this->assertStringContainsString('item.print_rows', $javascript);
        $this->assertStringContainsString('cloneNode(true)', $javascript);
        $this->assertStringContainsString('renderClientTotals(loadedClientSummaries, loadedPrintTotals)', $javascript);
        $this->assertStringContainsString('TOTAL GENERAL', $javascript);
        $this->assertStringContainsString('data.summary?.print_totals', $javascript);
        $this->assertStringContainsString('"VARIOS"', $javascript);
        $this->assertStringContainsString('/operacion/tickets-dia/impresion?', $javascript);
    }

    public function test_journey_configuration_view_is_available_without_database_queries(): void
    {
        $this->get('/jornada')
            ->assertOk()
            ->assertSee('Orígenes de la jornada')
            ->assertDontSee('Precios globales')
            ->assertDontSee('precios de proveedor', false)
            ->assertSee(asset('js/jornada.js'), false)
            ->assertSee(asset('css/style.css'), false);

        $javascript = file_get_contents(public_path('js/jornada.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('truck.prices', $javascript);
        $this->assertStringNotContainsString('Precio proveedor/kg', $javascript);
        $this->assertStringNotContainsString('global_prices', $javascript);
    }

    public function test_journey_prices_have_an_independent_retail_view(): void
    {
        $this->get('/precios-jornada')
            ->assertOk()
            ->assertSee('Precios de la jornada')
            ->assertSee('despacho minorista 1 y 2')
            ->assertSee('id="ticketTitleForm"', false)
            ->assertSee('id="ticketTitleInput"', false)
            ->assertSee('name="ticket_title"', false)
            ->assertSee('maxlength="120"', false)
            ->assertSee('required', false)
            ->assertSee('id="ticketTitleStatus"', false)
            ->assertSee('id="ticketTitleSave"', false)
            ->assertSee('Guardar título')
            ->assertSee('id="ticketMessageForm"', false)
            ->assertSee('id="ticketMessageInput"', false)
            ->assertSee('name="ticket_message"', false)
            ->assertSee('maxlength="255"', false)
            ->assertSee('id="ticketMessageStatus"', false)
            ->assertSee('id="ticketMessageSave"', false)
            ->assertSee('Guardar mensaje')
            ->assertSee(asset('js/precios-jornada.js'), false)
            ->assertDontSee('journeyRows', false)
            ->assertDontSee('journeySelectAll', false);

        $javascript = file_get_contents(public_path('js/precios-jornada.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('let currentPrices = {};', $javascript);
        $this->assertStringContainsString('expected_prices: Object.fromEntries(', $javascript);
        $this->assertStringContainsString('field.startsWith("expected_prices")', $javascript);
        $this->assertStringContainsString('Se cargaron los valores vigentes para que los revises.', $javascript);
        $this->assertStringContainsString('renderTicketTitle(data.ticket_title);', $javascript);
        $this->assertStringContainsString('renderTicketMessage(data.ticket_message);', $javascript);
        $this->assertStringContainsString('elements.ticketTitleForm.addEventListener("submit", saveTicketTitle);', $javascript);
        $this->assertStringContainsString('elements.ticketMessageForm.addEventListener("submit", saveTicketMessage);', $javascript);
        $this->assertStringContainsString('event.preventDefault();', $javascript);
        $this->assertStringContainsString('apiRequest("/operacion/precios-jornada/titulo-ticket", {', $javascript);
        $this->assertStringContainsString('ticket_title: elements.ticketTitleInput.value', $javascript);
        $this->assertStringContainsString('apiRequest("/operacion/precios-jornada/mensaje-ticket", {', $javascript);
        $this->assertStringContainsString('ticket_message: elements.ticketMessageInput.value', $javascript);
    }

    public function test_weighing_management_view_is_available_without_database_queries(): void
    {
        $this->get('/gestion-pesadas')
            ->assertOk()
            ->assertSee('Gestión de pesadas')
            ->assertSee('tickets mayoristas, minoristas o ventas externas')
            ->assertSee('ticketSearchInput', false)
            ->assertSee('selectedTicketPanel', false)
            ->assertSee('editTicketDeliveryModal', false)
            ->assertSee('editTicketVehicle', false)
            ->assertSee('editTicketDriver', false)
            ->assertSee('editOriginTruck', false)
            ->assertSee('editChickenVariant', false)
            ->assertSee('editWeightLabel', false)
            ->assertSee('editWeightHelp', false)
            ->assertSee('MACHO_ABIERTO', false)
            ->assertSee('POLLO_BENEFICIADO', false)
            ->assertSee('voidTicketModal', false)
            ->assertSee('voidTicketReason', false)
            ->assertSee('Acción exclusiva de administrador')
            ->assertSee('Sí, anular ticket')
            ->assertSee('Solo aparecen camiones incluidos en la jornada de este ticket.')
            ->assertSee(asset('js/gestion-pesadas.js'), false);

        $dispatchJavascript = file_get_contents(public_path('js/app.js'));
        $managementJavascript = file_get_contents(public_path('js/gestion-pesadas.js'));

        $this->assertIsString($dispatchJavascript);
        $this->assertIsString($managementJavascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $dispatchJavascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $managementJavascript);
        $this->assertStringContainsString('data-print-selected-ticket', $managementJavascript);
        $this->assertStringContainsString('data-edit-ticket-delivery', $managementJavascript);
        $this->assertStringContainsString('ticket.delivery_editable', $managementJavascript);
        $this->assertStringContainsString('delivery_assignment_deferred', $managementJavascript);
        $this->assertStringContainsString('Pendiente de agregar camión y chofer', $managementJavascript);
        $this->assertStringContainsString('Pendiente de agregar camión', $managementJavascript);
        $this->assertStringContainsString('Pendiente de agregar chofer', $managementJavascript);
        $this->assertStringContainsString('ticket.can_void', $managementJavascript);
        $this->assertStringContainsString('data-void-selected-ticket', $managementJavascript);
        $this->assertStringContainsString('`/operacion/tickets/${ticketId}/anular`', $managementJavascript);
        $this->assertStringContainsString('JSON.stringify({ motivo: reason })', $managementJavascript);
        $this->assertStringContainsString('/transporte', $managementJavascript);
        $this->assertStringContainsString('origin_trucks', $managementJavascript);
        $this->assertStringContainsString('origin_program_detail_id', $managementJavascript);
        $this->assertStringContainsString('editSelectProviderBtn', $dispatchJavascript);
        $this->assertStringContainsString('selectedVehicleIds.has(String(vehicle.id))', $dispatchJavascript);
        $this->assertStringContainsString('printWeightControlTicket(buildSelectedTicketPrintData(ticket)', $managementJavascript);
        $this->assertStringContainsString('Despacho minorista', $managementJavascript);
        $this->assertStringContainsString('Venta externa', $managementJavascript);
        $this->assertStringContainsString('weighing.price_kg', $managementJavascript);
        $this->assertStringContainsString('birds: Number(weighing.birds) || 0', $managementJavascript);
        $this->assertStringContainsString('operatingDate: ticket.operating_date', $managementJavascript);
        $this->assertStringContainsString('destinationName: retail ? (ticket.client?.name || "Venta externa") : ticketCustomerName(ticket)', $managementJavascript);
        $this->assertStringContainsString('cages: Number(retail ? weighing.trays : weighing.cages) || 0', $managementJavascript);
        $this->assertStringContainsString('readWeight: Number(weighing.read_weight_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('ticket.prices', $managementJavascript);
        $this->assertStringContainsString('summary.amount', $managementJavascript);
        $this->assertStringContainsString('delivery: ticket.delivery', $managementJavascript);
        $this->assertStringContainsString('MODULO_DESPACHO_MAYORISTA_2', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "PV-M"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "PV-H"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "MA"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "MC"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "HA"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "HC"', $managementJavascript);
        $this->assertStringContainsString('shortLabel: "PB"', $managementJavascript);
        $this->assertStringContainsString('payload.chicken_variant_code = selectedVariant.code', $managementJavascript);
        $this->assertStringContainsString('payload.read_weight_kg = Number(elements.grossWeight.value)', $managementJavascript);
        $this->assertStringContainsString('weight_adjustments', $managementJavascript);
        $this->assertStringContainsString('sourceModule: ticket.source_module', $managementJavascript);
        $this->assertStringContainsString('adjustment: weighing.adjustment || null', $managementJavascript);
        $this->assertStringContainsString('Merma aplicada', $managementJavascript);
        $this->assertStringContainsString('return wholesaleTwoVariantForWeighing(weighing)?.shortLabel || "--";', $managementJavascript);
    }

    public function test_provider_report_view_is_available_without_database_queries(): void
    {
        $this->get('/reporte-proveedores')
            ->assertOk()
            ->assertSee('Reporte de proveedores')
            ->assertSee('Jornada operativa actual')
            ->assertSee('Consulta de pesadas')
            ->assertSee('Resumen por proveedor y camión')
            ->assertSee('A dónde fueron los pollos')
            ->assertSee('Detalle de pesadas')
            ->assertSee('id="providerJourneyFilter"', false)
            ->assertSee('id="providerNameFilter"', false)
            ->assertSee('id="providerTruckFilter"', false)
            ->assertSee(asset('css/reporte-proveedores.css'), false)
            ->assertSee(asset('js/reporte-proveedores.js'), false);

        $javascript = file_get_contents(public_path('js/reporte-proveedores.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('/operacion/reporte-proveedores?', $javascript);
        $this->assertStringContainsString('reportData?.current_operating_date', $javascript);
        $this->assertStringContainsString('renderTruckOptions', $javascript);
    }

    public function test_retail_ticket_print_and_reprint_keep_the_sale_weight_and_delivery_data(): void
    {
        $printerJavascript = file_get_contents(public_path('js/ticket-printer.js'));
        $retailJavascript = file_get_contents(public_path('js/despacho-minorista.js'));
        $managementJavascript = file_get_contents(public_path('js/gestion-pesadas.js'));

        $this->assertIsString($printerJavascript);
        $this->assertIsString($retailJavascript);
        $this->assertIsString($managementJavascript);

        $this->assertStringContainsString('record?.readWeight', $printerJavascript);
        $this->assertStringContainsString('record?.grossWeight', $printerJavascript);
        $this->assertStringContainsString('record?.tareWeight', $printerJavascript);
        $this->assertStringContainsString('record?.netWeight', $printerJavascript);
        $this->assertStringContainsString('record?.priceKg', $printerJavascript);
        $this->assertStringContainsString('record?.amount', $printerJavascript);
        $this->assertStringContainsString('record?.adjustment?.total_weight_kg', $printerJavascript);
        $this->assertStringContainsString('ticket?.sourceModule === "MODULO_DESPACHO_MAYORISTA_2"', $printerJavascript);

        $this->assertStringContainsString('buildRetailTicketPrintData,', $retailJavascript);
        $this->assertStringContainsString('buildRetailTicketPrintData(ticket, ticketMessage, ticketTitle)', $retailJavascript);
        $this->assertStringContainsString('operatingDate: ticket?.operating_date', $printerJavascript);
        $this->assertStringContainsString('birds: Number(weighing.birds) || 0', $printerJavascript);
        $this->assertStringContainsString('readWeight: Number(weighing.read_weight_kg) || 0', $printerJavascript);
        $this->assertStringContainsString('grossWeight: Number(weighing.gross_weight_kg) || 0', $printerJavascript);
        $this->assertStringContainsString('tareWeight: Number(weighing.tare_weight_kg) || 0', $printerJavascript);
        $this->assertStringContainsString('netWeight: Number(weighing.net_weight_kg) || 0', $printerJavascript);
        $this->assertStringContainsString('delivery: ticket?.delivery', $printerJavascript);
        $this->assertStringContainsString('cages: Number(weighing.tray_count) || 0', $printerJavascript);
        $this->assertStringContainsString('priceKg: roundTicketMoney(weighing.price_kg)', $printerJavascript);
        $this->assertStringContainsString('amount: roundTicketMoney(weighing.amount)', $printerJavascript);

        $this->assertStringContainsString('operatingDate: ticket.operating_date', $managementJavascript);
        $this->assertStringContainsString('birds: Number(weighing.birds) || 0', $managementJavascript);
        $this->assertStringContainsString('readWeight: Number(weighing.read_weight_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('grossWeight: Number(weighing.gross_weight_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('tareWeight: Number(weighing.tare_weight_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('netWeight: Number(weighing.net_weight_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('delivery: ticket.delivery', $managementJavascript);
        $this->assertStringContainsString('cages: Number(retail ? weighing.trays : weighing.cages) || 0', $managementJavascript);
        $this->assertStringContainsString('priceKg: Number(weighing.price_kg) || 0', $managementJavascript);
        $this->assertStringContainsString('amount: Number(weighing.amount) || 0', $managementJavascript);
    }

    public function test_customer_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/clientes/15')
            ->assertOk()
            ->assertSee('Histórico de precios')
            ->assertSee('data-client-id="15"', false)
            ->assertSee('id="customerFinanceSection"', false)
            ->assertSee('tipo=DEUDA_ANTERIOR_CLIENTE&amp;cliente_id=15', false)
            ->assertSee('tipo=COBRO_CLIENTE&amp;cliente_id=15', false)
            ->assertSee(asset('js/cliente-detalle.js'), false);
    }

    public function test_provider_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/proveedores/20')
            ->assertOk()
            ->assertSee('Camiones de mi empresa asignados')
            ->assertSee('Busca un camión registrado en Mi flota')
            ->assertSee('Asignar camión')
            ->assertSee('id="providerVehicleSearch"', false)
            ->assertSee('id="providerVehicleSearchResults"', false)
            ->assertSee('Pesadas del proveedor')
            ->assertSee('data-provider-id="20"', false)
            ->assertSee('id="providerFinanceSection"', false)
            ->assertSee('id="providerDirectDepositsSection"', false)
            ->assertSee('id="providerFinanceCurrency"', false)
            ->assertSee('Registrar compra')
            ->assertSee(route('compras.create').'?proveedor_id=20', false)
            ->assertSee('tipo=PAGO_PROVEEDOR&amp;proveedor_id=20', false)
            ->assertSee(asset('js/proveedor-detalle.js'), false);

        $javascript = file_get_contents(public_path('js/proveedor-detalle.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('Camión de mi empresa · Asignado desde', $javascript);
        $this->assertStringContainsString('El camión seguirá en Mi flota.', $javascript);
        $this->assertStringContainsString('params.set("moneda", elements.financeCurrency.value)', $javascript);
    }

    public function test_legacy_html_urls_redirect_to_laravel_routes(): void
    {
        $this->get('/menu.html')->assertRedirect('/');
        $this->get('/index.html')->assertRedirect('/operacion');
        $this->get('/clientes.html')->assertRedirect('/directorio');
    }
}
