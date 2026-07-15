<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebViewsTest extends TestCase
{
    public function test_main_menu_is_the_application_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Menú principal')
            ->assertSee(route('operacion').'#despacho', false)
            ->assertSee('Despacho mayorista')
            ->assertSee(route('despacho-minorista'), false)
            ->assertSee('Despacho minorista')
            ->assertDontSee('Registro de pesadas y balanzas')
            ->assertSee(route('tickets-dia'), false)
            ->assertSee('Resumen de la jornada')
            ->assertSee('Consolidado diario por cliente')
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
            ->assertSee('Control de javas y bandejas')
            ->assertSee('Mi flota y choferes')
            ->assertDontSee('Facturación')
            ->assertDontSee('Ingresos y despachos')
            ->assertDontSee('data-future-view', false);
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
            ->assertSee(route('finanzas.movimientos.nuevo'), false)
            ->assertSee('Registrar cobro o pago')
            ->assertSee(route('menu'), false)
            ->assertSee(asset('css/finanzas.css'), false)
            ->assertDontSee('id="financeAvailableBalance"', false)
            ->assertDontSee('id="financeAuthDialog"', false)
            ->assertDontSee(asset('js/finanzas-dashboard.js'), false);

        $this->assertSame(4, substr_count($financeMenu->getContent(), 'class="fin-module-card fin-card"'));

        $this->get('/finanzas/saldos')
            ->assertOk()
            ->assertSee('Saldos y trazabilidad')
            ->assertSee('id="financeAvailableBalance"', false)
            ->assertSee('id="financeTraceRows"', false)
            ->assertSee('id="financeAdvanceList"', false)
            ->assertSee('id="financeAdvanceDialog"', false)
            ->assertSee('aria-describedby="financeAdvanceDialogDescription"', false)
            ->assertSee('Anticipos por aplicar')
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

        $this->get('/finanzas/movimientos/nuevo')
            ->assertOk()
            ->assertSee('Registrar movimiento')
            ->assertSee('value="COBRO_CLIENTE"', false)
            ->assertSee('value="PAGO_DIRECTO"', false)
            ->assertSee('value="PAGO_PROVEEDOR"', false)
            ->assertSee('value="COBRO_MINORISTA"', false)
            ->assertSee('value="REEMBOLSO_CLIENTE"', false)
            ->assertSee('id="financeCxcList"', false)
            ->assertSee('id="financeCxpList"', false)
            ->assertSee('css/finanzas.css?v=', false)
            ->assertSee(asset('js/finanzas-movimiento.js'), false);

        $financeStylesheet = file_get_contents(public_path('css/finanzas.css'));
        $dashboardJavascript = file_get_contents(public_path('js/finanzas-dashboard.js'));
        $entitiesJavascript = file_get_contents(public_path('js/finanzas-entidades.js'));
        $movementJavascript = file_get_contents(public_path('js/finanzas-movimiento.js'));

        $this->assertIsString($financeStylesheet);
        $this->assertIsString($dashboardJavascript);
        $this->assertIsString($entitiesJavascript);
        $this->assertIsString($movementJavascript);
        $this->assertMatchesRegularExpression(
            '/html\.fin-root,\s*body\.fin-page\s*\{[^}]*height:\s*auto;[^}]*overflow-y:\s*auto;/s',
            $financeStylesheet,
        );
        $this->assertStringContainsString('/finanzas/saldos', $dashboardJavascript);
        $this->assertStringContainsString('/finanzas/trazabilidad', $dashboardJavascript);
        $this->assertStringContainsString('/finanzas/movimientos?per_page=6', $dashboardJavascript);
        $this->assertStringContainsString('aplicacion_estado=CON_SALDO', $dashboardJavascript);
        $this->assertStringContainsString('/aplicaciones', $dashboardJavascript);
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
        $this->assertStringContainsString('importe_aplicado:', $movementJavascript);
        $this->assertStringContainsString('idempotency_key:', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("tipo")', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("cliente_id")', $movementJavascript);
        $this->assertStringContainsString('queryParameters.get("proveedor_id")', $movementJavascript);
        $this->assertStringContainsString('method: "POST"', $movementJavascript);
    }

    public function test_purchase_views_are_available_without_database_queries(): void
    {
        $this->get('/compras')
            ->assertOk()
            ->assertSee('Compras a proveedores')
            ->assertSee('id="purchaseTotalAmount"', false)
            ->assertSee('id="purchaseLegacyAmount"', false)
            ->assertSee('id="purchaseFilters"', false)
            ->assertSee('id="purchaseFilterCurrency"', false)
            ->assertSee('value="LEGADO"', false)
            ->assertSee('Histórica sin clasificar')
            ->assertSee('id="purchaseRows"', false)
            ->assertSee('id="purchaseDetailDialog"', false)
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

        $purchaseJavascript = file_get_contents(public_path('js/compras.js'));
        $purchaseFormJavascript = file_get_contents(public_path('js/compra-form.js'));
        $financeStylesheet = file_get_contents(public_path('css/finanzas.css'));

        $this->assertIsString($purchaseJavascript);
        $this->assertIsString($purchaseFormJavascript);
        $this->assertIsString($financeStylesheet);
        $this->assertStringContainsString('/compras/catalogo', $purchaseJavascript);
        $this->assertStringContainsString('/compras?', $purchaseJavascript);
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
        $this->assertStringContainsString('cuentas_propias', $purchaseFormJavascript);
        $this->assertStringContainsString('cuentas_proveedores', $purchaseFormJavascript);
        $this->assertStringContainsString('condicion: condition()', $purchaseFormJavascript);
        $this->assertStringContainsString('payload.pago =', $purchaseFormJavascript);
        $this->assertStringContainsString('peso_kg:', $purchaseFormJavascript);
        $this->assertStringContainsString('function roundMoney(value)', $purchaseFormJavascript);
        $this->assertStringContainsString('idempotency_key:', $purchaseFormJavascript);
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
            ->assertSee('class="rd-lists-stage"', false)
            ->assertSee('aria-label="Seleccionar lista de destino"', false)
            ->assertSee('Selecciona una columna y captura; la pesada se agregará directamente.')
            ->assertSee('class="is-active" data-retail-add-list="0" aria-pressed="true"', false)
            ->assertSee('Seleccionar lista 1')
            ->assertDontSee('Agregar a lista 1')
            ->assertSee('data-retail-add-list="3"', false)
            ->assertSee('id="retailSettingsModal"', false)
            ->assertSee('Balanza y ajustes minoristas')
            ->assertSee('id="retailOpenTypography"', false)
            ->assertSee('id="retailTypographyDrawer"', false)
            ->assertSee('id="retailTypographyControls"', false)
            ->assertSee('id="retailTypographyReset"', false)
            ->assertSee('id="retailTypographyClose"', false)
            ->assertSee('Tamaños de tipografía')
            ->assertSee('id="retailDeliveryModal"', false)
            ->assertSee('id="retailDeliveryTruck"', false)
            ->assertSee('id="retailDeliveryDriver"', false)
            ->assertSee('Guardar e imprimir / PDF')
            ->assertSee('Grabar')
            ->assertSee(asset('js/despacho-minorista.js'), false)
            ->assertSee(asset('css/despacho-minorista.css'), false)
            ->assertSee(route('menu'), false)
            ->assertDontSee('id="retailAdjustmentPreview"', false)
            ->assertDontSee('id="retailSex"', false);

        $javascript = file_get_contents(public_path('js/despacho-minorista.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('/despacho-minorista/catalogo', $javascript);
        $this->assertStringContainsString('/despacho-minorista/configuracion', $javascript);
        $this->assertStringContainsString('/despacho-minorista/tickets', $javascript);
        $this->assertStringContainsString('adjustment_code', $javascript);
        $this->assertStringContainsString('read_weight_kg', $javascript);
        $this->assertStringContainsString('tray_type_code', $javascript);
        $this->assertStringContainsString('additional_grams', $javascript);
        $this->assertStringContainsString('new Set(["POLLO_PELADO", "POLLO_BENEFICIADO"])', $javascript);
        $this->assertStringContainsString('availableChickenTypeCodes.has(item.chickenTypeCode)', $javascript);
        $this->assertStringContainsString('const birds = trayCount * birdsPerTray;', $javascript);
        $this->assertStringContainsString('const totalAdjustmentGrams = adjustmentGrams * birds;', $javascript);
        $this->assertStringContainsString('readWeight + totalAdjustmentGrams / 1000', $javascript);
        $this->assertStringContainsString('priceEditingListIndex', $javascript);
        $this->assertStringContainsString('general_prices', $javascript);
        $this->assertStringContainsString('data-retail-clear-client', $javascript);
        $this->assertStringContainsString('list.clientId ? Number(list.clientId) : null', $javascript);
        $this->assertStringContainsString('if (client) return currentClientPrice(list, chickenTypeCode);', $javascript);
        $this->assertStringContainsString('sistema-pollos-retail-typography-v1', $javascript);
        $this->assertStringContainsString('data-typography-step', $javascript);
        $this->assertStringContainsString('document.documentElement.style.setProperty', $javascript);
        $this->assertStringContainsString('localStorage.setItem(TYPOGRAPHY_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('addWeighingToList(state.activeList, capturedReading)', $javascript);
        $this->assertStringContainsString('selectList(addButton.dataset.retailAddList)', $javascript);
        $this->assertStringContainsString('values.trayCount < 0', $javascript);
        $this->assertStringContainsString('elements.birdsPerTray.value = birdsOption.dataset.retailBirdsPerTrayOption', $javascript);
        $this->assertStringContainsString('openModal(elements.birdsPerTrayModal)', $javascript);
        $this->assertStringContainsString('elements.birdsPerTrayModal,', $javascript);
        $this->assertStringContainsString('from "./ticket-printer.js"', $javascript);
        $this->assertStringContainsString('function requiresDelivery(list)', $javascript);
        $this->assertStringContainsString('delivery_trucks', $javascript);
        $this->assertStringContainsString('delivery_drivers', $javascript);
        $this->assertStringContainsString('delivery,', $javascript);
        $this->assertStringContainsString('await printRegisteredTicket(ticket, listIndex, list.draftId)', $javascript);
        $this->assertStringContainsString('clearRegisteredList(listIndex, draftId, ticket)', $javascript);
        $this->assertStringNotContainsString('state.captured', $javascript);
        $this->assertStringNotContainsString('g adicionales', $javascript);
        $this->assertStringNotContainsString('type.code.replaceAll', $javascript);
        $this->assertStringNotContainsString('cage_type_code', $javascript);
        $this->assertStringNotContainsString('cantidad_javas', $javascript);

        $scaleJavascript = file_get_contents(public_path('js/despacho-minorista-balanza.js'));

        $this->assertIsString($scaleJavascript);
        $this->assertStringContainsString('sistema-pollos-retail-scale-v1', $scaleJavascript);
        $this->assertStringContainsString('connectBle', $scaleJavascript);
        $this->assertStringContainsString('connectSerial', $scaleJavascript);

        $stylesheet = file_get_contents(public_path('css/despacho-minorista.css'));

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('--rd-font-base:', $stylesheet);
        $this->assertStringContainsString('--rd-font-chicken-type:', $stylesheet);
        $this->assertStringContainsString('--rd-font-presentation:', $stylesheet);
        $this->assertStringContainsString('--rd-font-table-cell:', $stylesheet);
        $this->assertStringContainsString('.rd-typography-drawer', $stylesheet);
        $this->assertDoesNotMatchRegularExpression('/font-size:\s*(?:\d|\.)/', $stylesheet);
    }

    public function test_operation_view_is_available_without_database_queries(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('Entrada de Camiones de Pollos')
            ->assertSee('<select id="truckPlate"', false)
            ->assertDontSee('Precio general pollo')
            ->assertDontSee('generalPriceVivoKg', false)
            ->assertSee('type="module"', false)
            ->assertSee(asset('js/app.js'), false);
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

        $this->assertStringContainsString('DISTRIBUIDORA<br>DIEGO ALBERTO', $template);
        $this->assertStringContainsString('CONTROL DE PESO', $template);
        $this->assertStringContainsString('<th>C/A</th>', $template);
        $this->assertStringContainsString('<th>CJ</th>', $template);
        $this->assertStringContainsString('PESO<br>BRUTO', $template);
        $this->assertStringContainsString('PESO<br>TARA', $template);
        $this->assertStringContainsString('<p>OBSERV:</p>', $template);
        $this->assertStringContainsString('font-size: 11px', $template);
        $this->assertStringContainsString('font-weight: 700', $template);
        $this->assertStringNotContainsString('CONTROL PESO', $template);
        $this->assertStringNotContainsString('P.NETO', $template);
        $this->assertStringNotContainsString('TOTAL AVES:', $template);
        $this->assertStringNotContainsString('PLACA:', $template);
        $this->assertStringNotContainsString('ORIGEN:', $template);
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
            ->assertSee('Disponibles dentro')
            ->assertSee('id="javaInventoryOpen"', false)
            ->assertSee('id="javaInventoryModal"', false)
            ->assertSee('id="javaInventoryQuantity"', false)
            ->assertSee('id="trayInventoryQuantity"', false)
            ->assertSee('id="trayCompanyTotal"', false)
            ->assertSee('Registrar conteo físico')
            ->assertSee('id="javaDailyModal"', false)
            ->assertSee('id="javaDailyCountQuantity"', false)
            ->assertSee('id="trayDailyCountQuantity"', false)
            ->assertSee('id="trayDailyDifference"', false)
            ->assertDontSee('id="javaClientRows"', false)
            ->assertDontSee('id="javaJourneyFilter"', false)
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
        $this->assertStringContainsString('java_balance: numericValue', $javascript);
        $this->assertStringContainsString('tray_balance: numericValue', $javascript);
        $this->assertStringContainsString('javaQuantity === 0 && trayQuantity === 0', $javascript);
        $this->assertStringContainsString('movement?.java_quantity, movement?.quantity', $javascript);
    }

    public function test_daily_tickets_view_is_available_without_database_queries(): void
    {
        $this->get('/tickets-dia')
            ->assertOk()
            ->assertSee('Resumen de la jornada')
            ->assertSee('dailyClientTotals', false)
            ->assertSee(route('menu'), false)
            ->assertSee('Menú')
            ->assertSee(asset('js/tickets-dia.js'), false)
            ->assertDontSee('dailyOperationSummary', false)
            ->assertDontSee('dailyTicketsFilters', false)
            ->assertDontSee('dailyTypeTotals', false)
            ->assertDontSee('dailyTicketList', false)
            ->assertDontSee('Importe')
            ->assertDontSee('Precio/kg');
    }

    public function test_journey_configuration_view_is_available_without_database_queries(): void
    {
        $this->get('/jornada')
            ->assertOk()
            ->assertSee('Orígenes de la jornada')
            ->assertSee('Precios globales')
            ->assertDontSee('precios de proveedor', false)
            ->assertSee(asset('js/jornada.js'), false);

        $javascript = file_get_contents(public_path('js/jornada.js'));

        $this->assertIsString($javascript);
        $this->assertStringNotContainsString('truck.prices', $javascript);
        $this->assertStringNotContainsString('Precio proveedor/kg', $javascript);
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
        $this->assertStringContainsString('/transporte', $managementJavascript);
        $this->assertStringContainsString('origin_trucks', $managementJavascript);
        $this->assertStringContainsString('origin_program_detail_id', $managementJavascript);
        $this->assertStringContainsString('editSelectProviderBtn', $dispatchJavascript);
        $this->assertStringContainsString('selectedVehicleIds.has(String(vehicle.id))', $dispatchJavascript);
        $this->assertStringContainsString('printWeightControlTicket(buildSelectedTicketPrintData(ticket)', $managementJavascript);
        $this->assertStringContainsString('Despacho minorista', $managementJavascript);
        $this->assertStringContainsString('Venta externa', $managementJavascript);
        $this->assertStringContainsString('weighing.price_kg', $managementJavascript);
        $this->assertStringContainsString('ticket.prices', $managementJavascript);
        $this->assertStringContainsString('summary.amount', $managementJavascript);
        $this->assertStringContainsString('delivery: ticket.delivery', $managementJavascript);
    }

    public function test_retail_ticket_reprint_keeps_the_applied_price_and_customer_kind(): void
    {
        $javascript = file_get_contents(public_path('js/ticket-printer.js'));

        $this->assertIsString($javascript);
        $this->assertStringContainsString('DESPACHO MINORISTA', $javascript);
        $this->assertStringContainsString('VENTA EXTERNA', $javascript);
        $this->assertStringContainsString('PRECIO<br>/KG', $javascript);
        $this->assertStringContainsString('TOTAL TICKET', $javascript);
        $this->assertStringContainsString('record?.priceKg', $javascript);
        $this->assertStringContainsString('record?.amount', $javascript);
        $this->assertStringContainsString('deliveryVehicle.plate', $javascript);
        $this->assertStringContainsString('deliveryDriver.name', $javascript);
    }

    public function test_customer_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/clientes/15')
            ->assertOk()
            ->assertSee('Histórico de precios')
            ->assertSee('data-client-id="15"', false)
            ->assertSee('id="customerFinanceSection"', false)
            ->assertSee('tipo=COBRO_CLIENTE&amp;cliente_id=15', false)
            ->assertSee(asset('js/cliente-detalle.js'), false);
    }

    public function test_provider_history_view_is_available_without_database_queries(): void
    {
        $this->get('/directorio/proveedores/20')
            ->assertOk()
            ->assertSee('Camiones de mi empresa asignados')
            ->assertSee('Cada placa que agregues pertenecerá a Mi flota')
            ->assertSee('Crear y asignar camión')
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
