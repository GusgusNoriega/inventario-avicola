<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class CustomerDisplayTest extends TestCase
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

    public function test_wholesale_view_opens_the_customer_display(): void
    {
        $this->get('/operacion')
            ->assertOk()
            ->assertSee('id="openCustomerDisplayBtn"', false)
            ->assertSee(route('operacion.pantalla-cliente'), false)
            ->assertSee('target="pantalla-cliente"', false);
    }

    public function test_customer_display_only_exposes_live_ticket_summary(): void
    {
        $this->get('/operacion/pantalla-cliente')
            ->assertOk()
            ->assertSee('id="customerDisplayName"', false)
            ->assertSee('id="customerDisplayTicket"', false)
            ->assertSee('id="customerDisplayScale1"', false)
            ->assertSee('id="customerDisplayScale2"', false)
            ->assertSee('id="customerDisplayRecords"', false)
            ->assertSee('id="customerDisplayCages"', false)
            ->assertSee('id="customerDisplayBirds"', false)
            ->assertSee('id="customerDisplayAnnouncement"', false)
            ->assertSee('Balanza 1')
            ->assertSee('Balanza 2')
            ->assertSee('Cantidad actual de aves en el ticket')
            ->assertSee('id="customerDisplayChooseScreen"', false)
            ->assertSee('id="customerDisplayScreenDialog"', false)
            ->assertSee(asset('js/pantalla-cliente.js'), false)
            ->assertDontSee('Precio')
            ->assertDontSee('Registrar');
    }

    public function test_customer_display_uses_realtime_channel_with_storage_fallback(): void
    {
        $operationJavascript = (string) file_get_contents(public_path('js/app.js'));
        $displayJavascript = (string) file_get_contents(public_path('js/pantalla-cliente.js'));

        $this->assertStringContainsString('new BroadcastChannel(CUSTOMER_DISPLAY_CHANNEL_NAME)', $operationJavascript);
        $this->assertStringContainsString('function isInstalledDesktopApplication()', $operationJavascript);
        $this->assertStringContainsString('(display-mode: ${mode})', $operationJavascript);
        $this->assertStringContainsString('function openCustomerDisplay(event)', $operationJavascript);
        $this->assertStringContainsString('window.open(', $operationJavascript);
        $this->assertStringContainsString('CUSTOMER_DISPLAY_PRODUCER_ID', $operationJavascript);
        $this->assertStringContainsString('function getCustomerDisplayProducerInstance()', $operationJavascript);
        $this->assertStringContainsString('producerInstance: CUSTOMER_DISPLAY_PRODUCER_INSTANCE', $operationJavascript);
        $this->assertStringContainsString('displayUrl.searchParams.set("source", CUSTOMER_DISPLAY_PRODUCER_ID)', $operationJavascript);
        $this->assertStringContainsString('function getCurrentGrossWeight()', $operationJavascript);
        $this->assertStringContainsString('weightKg: getCurrentGrossWeight()', $operationJavascript);
        $this->assertStringContainsString('weightSource: elements.weightSource.value', $operationJavascript);
        $this->assertStringContainsString('const ticketTotals = calculateTruckTotals(', $operationJavascript);
        $this->assertStringContainsString('label: ticketLabel', $operationJavascript);
        $this->assertStringContainsString('records: ticketTotals.records', $operationJavascript);
        $this->assertStringContainsString('javas: ticketTotals.javas', $operationJavascript);
        $this->assertStringContainsString('birds: ticketTotals.birds', $operationJavascript);
        $this->assertStringContainsString('1: { weightKg: getScaleWeight(1) }', $operationJavascript);
        $this->assertStringContainsString('2: { weightKg: getScaleWeight(2) }', $operationJavascript);
        $this->assertStringContainsString('revision: ++customerDisplayRevision', $operationJavascript);
        $this->assertStringContainsString('publishCustomerDisplayState();', $operationJavascript);
        $this->assertStringContainsString('pendingCustomerDisplayStoragePayload', $operationJavascript);
        $this->assertStringContainsString('customerName:', $operationJavascript);
        $this->assertStringContainsString('new BroadcastChannel(CHANNEL_NAME)', $displayJavascript);
        $this->assertStringContainsString('payload.producerId !== PRODUCER_ID', $displayJavascript);
        $this->assertStringContainsString('window.addEventListener("storage"', $displayJavascript);
        $this->assertStringContainsString('customer-display-request', $displayJavascript);
        $this->assertStringContainsString('payloadTimestamp < lastPayloadTimestamp', $displayJavascript);
        $this->assertStringContainsString('payloadRevision <= lastRevision', $displayJavascript);
        $this->assertStringContainsString('payloadProducerInstance < lastProducerInstance', $displayJavascript);
        $this->assertStringContainsString('function getPayloadScaleWeight(payload, scaleId)', $displayJavascript);
        $this->assertStringContainsString('renderScale(payload, 1)', $displayJavascript);
        $this->assertStringContainsString('renderScale(payload, 2)', $displayJavascript);
        $this->assertStringContainsString('weight === null ? "---"', $displayJavascript);
        $this->assertStringContainsString('ticket.birds ?? payload.ticketBirds ?? payload.birds', $displayJavascript);
        $this->assertStringContainsString('window.getScreenDetails()', $displayJavascript);
        $this->assertStringContainsString('requestFullscreen({', $displayJavascript);
        $this->assertStringContainsString('screen', $displayJavascript);

        $requestStart = strpos($displayJavascript, 'function requestCurrentState()');
        $requestEnd = strpos($displayJavascript, "\n}", $requestStart);
        $this->assertNotFalse($requestStart);
        $this->assertNotFalse($requestEnd);
        $requestFlow = substr($displayJavascript, $requestStart, $requestEnd - $requestStart);
        $this->assertStringContainsString('channel?.postMessage', $requestFlow);
        $this->assertStringNotContainsString('readStoredState()', $requestFlow);

        $grossWeightStart = strpos($operationJavascript, 'function getCurrentGrossWeight()');
        $grossWeightEnd = strpos($operationJavascript, "\n}", $grossWeightStart);
        $this->assertNotFalse($grossWeightStart);
        $this->assertNotFalse($grossWeightEnd);
        $grossWeightFlow = substr($operationJavascript, $grossWeightStart, $grossWeightEnd - $grossWeightStart);
        $this->assertStringContainsString('elements.weightSource.value', $grossWeightFlow);
        $this->assertStringContainsString('getWeightFromSource(source)', $grossWeightFlow);

        $stateStart = strpos($operationJavascript, 'function buildCustomerDisplayState()');
        $stateEnd = strpos($operationJavascript, 'function flushCustomerDisplayStorage()', $stateStart);
        $this->assertNotFalse($stateStart);
        $this->assertNotFalse($stateEnd);
        $stateFlow = substr($operationJavascript, $stateStart, $stateEnd - $stateStart);
        $this->assertStringContainsString('selectedTruck?.cages || []', $stateFlow);
        $this->assertStringNotContainsString('elements.birdCount', $stateFlow);
        $this->assertStringNotContainsString('elements.javaCount', $stateFlow);
    }

    public function test_customer_display_layout_prioritizes_client_scales_and_ticket_birds(): void
    {
        $displayCss = (string) file_get_contents(public_path('css/pantalla-cliente.css'));

        $this->assertStringContainsString('font-size: clamp(46px, 6.4vw, 116px)', $displayCss);
        $this->assertStringContainsString('.customer-display-scales {', $displayCss);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $displayCss);
        $this->assertStringContainsString('.customer-display-ticket-summary {', $displayCss);
        $this->assertStringContainsString('@media (max-height: 820px) and (min-width: 701px)', $displayCss);
        $this->assertStringContainsString('@media (max-width: 700px)', $displayCss);
        $this->assertStringContainsString('.customer-display-scales { grid-template-columns: 1fr; }', $displayCss);
    }
}
