<?php

namespace Tests\Feature;

use App\Models\ProductoDespacho;
use App\Models\TicketDespachoProducto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class ProductDispatchGeneralReportTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    private int $branchId;

    private int $productId;

    private int $ticketSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'directory.public_access' => false]);
        $this->user = User::factory()->create();
        $this->branchId = $this->createBranch((int) $this->user->empresa_id);
        $this->user->update(['sucursal_id' => $this->branchId]);
        $this->grantModules($this->user, ['MODULO_DESPACHO_PRODUCTOS']);
        Sanctum::actingAs($this->user, ['api']);
        $this->productId = $this->createProduct('Pavo');
    }

    public function test_report_groups_products_and_variations_by_registration_day_with_exact_totals_and_separate_currencies(): void
    {
        $eggs = $this->createProduct('Huevo');
        $largeVariation = $this->createVariation('Grande');
        $smallVariation = $this->createVariation('Pequeño');
        $this->createTicket('2026-09-05 15:00:00', [
            ['quantity' => 2, 'read' => '5.123', 'waste' => 60, 'tare' => 100, 'net' => '5.083', 'amount' => '61.00', 'variation_id' => $largeVariation, 'variation' => 'Grande'],
            ['quantity' => 3, 'read' => '3.111', 'waste' => 90, 'tare' => 101, 'net' => '3.100', 'amount' => '37.20', 'variation_id' => $smallVariation, 'variation' => 'Pequeño'],
            ['product_id' => $eggs, 'name' => 'Huevo', 'quantity' => 10, 'read' => '1.000', 'net' => '1.000', 'amount' => '16.10'],
        ]);
        $this->createTicket('2026-09-05 23:00:00', [
            ['quantity' => 1, 'read' => '2.001', 'tare' => 1, 'net' => '2.000', 'amount' => '12.30'],
        ], ['moneda' => 'USD']);
        $this->createTicket('2026-09-06 18:00:00', [
            ['quantity' => 4, 'read' => '4.234', 'waste' => 120, 'tare' => 200, 'net' => '4.154', 'amount' => '49.85'],
        ]);

        $response = $this->getJson($this->reportUrl('2026-09-05', '2026-09-06'))
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-09-05', 'to' => '2026-09-06'])
            ->assertJsonPath('data.branch.id', $this->branchId)
            ->assertJsonPath('data.branch.timezone', 'America/Lima')
            ->assertJsonPath('data.summary.day_count', 2)
            ->assertJsonPath('data.summary.product_count', 4)
            ->assertJsonPath('data.summary.ticket_count', 3)
            ->assertJsonPath('data.summary.weighing_count', 5)
            ->assertJsonPath('data.summary.quantity', '20')
            ->assertJsonPath('data.summary.read_weight_kg', '15.469')
            ->assertJsonPath('data.summary.waste_weight_kg', '0.270')
            ->assertJsonPath('data.summary.tare_weight_kg', '0.402')
            ->assertJsonPath('data.summary.net_weight_kg', '15.337')
            ->assertJsonPath('data.summary.amounts', [
                ['currency' => 'PEN', 'amount' => '164.15'],
                ['currency' => 'USD', 'amount' => '12.30'],
            ])
            ->assertJsonCount(2, 'data.days')
            ->assertJsonPath('data.days.0.date', '2026-09-05')
            ->assertJsonPath('data.days.0.ticket_count', 2)
            ->assertJsonPath('data.days.0.quantity', '16')
            ->assertJsonPath('data.days.0.net_weight_kg', '11.183')
            ->assertJsonPath('data.days.0.product_count', 4)
            ->assertJsonCount(4, 'data.days.0.products')
            ->assertJsonPath('data.days.0.products.0.product_name', 'Huevo')
            ->assertJsonPath('data.days.0.products.1.product_name', 'Pavo')
            ->assertJsonPath('data.days.0.products.1.variation_id', null)
            ->assertJsonPath('data.days.0.products.1.variation_name', null)
            ->assertJsonPath('data.days.0.products.1.display_name', 'Pavo')
            ->assertJsonPath('data.days.0.products.1.quantity', '1')
            ->assertJsonPath('data.days.0.products.1.weighing_count', 1)
            ->assertJsonPath('data.days.0.products.1.amounts', [
                ['currency' => 'USD', 'amount' => '12.30'],
            ])
            ->assertJsonPath('data.days.0.products.2.product_id', $this->productId)
            ->assertJsonPath('data.days.0.products.2.product_name', 'Pavo')
            ->assertJsonPath('data.days.0.products.2.variation_id', $largeVariation)
            ->assertJsonPath('data.days.0.products.2.variation_name', 'Grande')
            ->assertJsonPath('data.days.0.products.2.display_name', 'Pavo · Grande')
            ->assertJsonPath('data.days.0.products.2.quantity', '2')
            ->assertJsonPath('data.days.0.products.2.read_weight_kg', '5.123')
            ->assertJsonPath('data.days.0.products.2.waste_weight_kg', '0.060')
            ->assertJsonPath('data.days.0.products.2.tare_weight_kg', '0.100')
            ->assertJsonPath('data.days.0.products.2.net_weight_kg', '5.083')
            ->assertJsonPath('data.days.0.products.2.amounts', [['currency' => 'PEN', 'amount' => '61.00']])
            ->assertJsonPath('data.days.0.products.3.variation_id', $smallVariation)
            ->assertJsonPath('data.days.0.products.3.display_name', 'Pavo · Pequeño')
            ->assertJsonPath('data.days.0.products.3.quantity', '3')
            ->assertJsonPath('data.days.0.products.3.amounts', [['currency' => 'PEN', 'amount' => '37.20']])
            ->assertJsonPath('data.days.1.date', '2026-09-06')
            ->assertJsonPath('data.days.1.products.0.product_id', $this->productId)
            ->assertJsonPath('data.days.1.products.0.quantity', '4');

        $this->assertNotEmpty($response->json('data.generated_at'));
    }

    public function test_report_includes_public_sales_and_preserves_snapshots_with_distinct_product_ids(): void
    {
        $otherProduct = $this->createProduct('Otro catálogo');
        $this->createTicket('2026-09-05 12:00:00', [
            ['quantity' => 2, 'amount' => '0.10'],
            ['product_id' => $otherProduct, 'name' => 'Pavo', 'quantity' => 3, 'amount' => '0.20'],
        ]);
        DB::table('productos_despacho')->where('id', $this->productId)->update([
            'nombre' => 'Nombre cambiado',
            'estado' => ProductoDespacho::STATUS_INACTIVE,
        ]);

        $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.summary.ticket_count', 1)
            ->assertJsonPath('data.summary.product_count', 2)
            ->assertJsonPath('data.summary.amounts.0.amount', '0.30')
            ->assertJsonCount(2, 'data.days.0.products')
            ->assertJsonPath('data.days.0.products.0.product_id', $this->productId)
            ->assertJsonPath('data.days.0.products.0.product_name', 'Pavo')
            ->assertJsonPath('data.days.0.products.1.product_id', $otherProduct)
            ->assertJsonPath('data.days.0.products.1.product_name', 'Pavo');
    }

    public function test_report_keeps_distinct_variation_ids_and_parent_products_with_the_same_snapshot_name(): void
    {
        $firstVariation = $this->createVariation('Grande');
        $secondVariation = $this->createVariation('Pequeño');
        $otherProduct = $this->createProduct('Otro catálogo');
        $otherVariation = $this->createVariation('Especial', $otherProduct);
        $this->createTicket('2026-09-05 12:00:00', [
            ['variation_id' => $firstVariation, 'variation' => 'Especial histórico', 'quantity' => 2, 'amount' => '0.10'],
            ['variation_id' => $secondVariation, 'variation' => 'Especial histórico', 'quantity' => 3, 'amount' => '0.20'],
            ['product_id' => $otherProduct, 'variation_id' => $otherVariation, 'variation' => 'Especial histórico', 'quantity' => 5, 'amount' => '0.30'],
        ]);
        $this->createTicket('2026-09-05 13:00:00', [
            ['variation_id' => $firstVariation, 'variation' => 'Especial histórico', 'quantity' => 4, 'amount' => '0.40'],
        ]);
        DB::table('variaciones_producto_despacho')->where('id', $firstVariation)->update([
            'nombre' => 'Nombre cambiado',
            'estado' => 'INACTIVO',
        ]);

        $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.summary.product_count', 3)
            ->assertJsonPath('data.summary.ticket_count', 2)
            ->assertJsonPath('data.summary.weighing_count', 4)
            ->assertJsonPath('data.summary.quantity', '14')
            ->assertJsonPath('data.summary.amounts.0.amount', '1.00')
            ->assertJsonCount(3, 'data.days.0.products')
            ->assertJsonPath('data.days.0.products.0.variation_id', $firstVariation)
            ->assertJsonPath('data.days.0.products.0.display_name', 'Pavo · Especial histórico')
            ->assertJsonPath('data.days.0.products.0.quantity', '6')
            ->assertJsonPath('data.days.0.products.0.weighing_count', 2)
            ->assertJsonPath('data.days.0.products.0.amounts.0.amount', '0.50')
            ->assertJsonPath('data.days.0.products.1.variation_id', $secondVariation)
            ->assertJsonPath('data.days.0.products.1.quantity', '3')
            ->assertJsonPath('data.days.0.products.2.product_id', $otherProduct)
            ->assertJsonPath('data.days.0.products.2.variation_id', $otherVariation)
            ->assertJsonPath('data.days.0.products.2.quantity', '5');
    }

    public function test_report_preserves_variation_snapshots_without_an_id_and_does_not_merge_matching_base_names(): void
    {
        $otherProduct = $this->createProduct('Otro catálogo');
        $matchingBaseProduct = $this->createProduct('Pavo · Grande');
        $this->createTicket('2026-09-05 12:00:00', [
            ['quantity' => 1],
            ['variation' => 'Grande', 'quantity' => 2],
            ['variation' => 'Grande', 'quantity' => 3],
            ['variation' => 'Pequeño', 'quantity' => 4],
            ['product_id' => $otherProduct, 'variation' => 'Grande', 'quantity' => 5],
            ['product_id' => $matchingBaseProduct, 'name' => 'Pavo · Grande', 'quantity' => 6],
        ]);

        $response = $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.summary.product_count', 5)
            ->assertJsonPath('data.summary.ticket_count', 1)
            ->assertJsonPath('data.summary.weighing_count', 6)
            ->assertJsonPath('data.summary.quantity', '21')
            ->assertJsonPath('data.summary.amounts.0.amount', '72.00')
            ->assertJsonCount(5, 'data.days.0.products');
        $rows = collect($response->json('data.days.0.products'));
        $parentProductRows = $rows->where('product_id', $this->productId);
        $this->assertCount(3, $parentProductRows);
        $this->assertSame('1', $parentProductRows->whereNull('variation_name')->sole()['quantity']);
        $historicalVariation = $parentProductRows->where('variation_name', 'Grande')->sole();
        $this->assertNull($historicalVariation['variation_id']);
        $this->assertSame('Pavo · Grande', $historicalVariation['display_name']);
        $this->assertSame('5', $historicalVariation['quantity']);
        $this->assertSame(2, $historicalVariation['weighing_count']);
        $this->assertSame('4', $parentProductRows->where('variation_name', 'Pequeño')->sole()['quantity']);
        $this->assertSame('5', $rows->where('product_id', $otherProduct)->sole()['quantity']);
        $this->assertSame('6', $rows->where('product_name', 'Pavo · Grande')->sole()['quantity']);
    }

    public function test_report_uses_a_label_for_variations_without_a_snapshot_and_keeps_empty_base_snapshots_together(): void
    {
        $variation = $this->createVariation('Grande');
        $sameNameVariation = $this->createVariation('Otra presentación');
        $this->createTicket('2026-09-05 12:00:00', [
            ['quantity' => 1],
            ['variation' => '   ', 'quantity' => 2],
            ['variation_id' => $variation, 'variation' => null, 'quantity' => 3],
            ['variation_id' => $variation, 'variation' => '', 'quantity' => 4],
            ['variation_id' => $sameNameVariation, 'variation' => 'Subproducto #'.$variation, 'quantity' => 5],
        ]);

        $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.summary.product_count', 3)
            ->assertJsonPath('data.summary.weighing_count', 5)
            ->assertJsonPath('data.summary.quantity', '15')
            ->assertJsonPath('data.days.0.products.0.variation_name', null)
            ->assertJsonPath('data.days.0.products.0.quantity', '3')
            ->assertJsonPath('data.days.0.products.1.variation_id', $variation)
            ->assertJsonPath('data.days.0.products.1.display_name', 'Pavo · Subproducto #'.$variation)
            ->assertJsonPath('data.days.0.products.1.quantity', '7')
            ->assertJsonPath('data.days.0.products.2.variation_id', $sameNameVariation)
            ->assertJsonPath('data.days.0.products.2.quantity', '5');
    }

    public function test_report_excludes_deleted_tickets_other_branches_and_other_companies(): void
    {
        $this->createTicket('2026-09-05 12:00:00', [['amount' => '10.00']]);
        $this->createTicket('2026-09-05 12:00:00', [['amount' => '20.00']], [
            'estado' => TicketDespachoProducto::STATUS_DELETED,
        ]);
        $this->createTicket('2026-09-05 12:00:00', [['amount' => '30.00']], [
            'sucursal_id' => $this->createBranch((int) $this->user->empresa_id),
        ]);
        $foreignUser = User::factory()->create();
        $this->createTicket('2026-09-05 12:00:00', [['amount' => '40.00']], [
            'empresa_id' => $foreignUser->empresa_id,
            'sucursal_id' => $this->createBranch((int) $foreignUser->empresa_id),
            'created_by' => $foreignUser->id,
        ]);

        $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.summary.ticket_count', 1)
            ->assertJsonPath('data.summary.weighing_count', 1)
            ->assertJsonPath('data.summary.amounts', [['currency' => 'PEN', 'amount' => '10.00']]);
    }

    public function test_report_uses_inclusive_branch_calendar_days_instead_of_operational_date(): void
    {
        $this->createTicket('2026-09-05 04:59:59', [['amount' => '100.00']]);
        $this->createTicket('2026-09-05 05:00:00', [['amount' => '1.00']], ['fecha_operativa' => '2026-09-04']);
        $this->createTicket('2026-09-06 04:59:59', [['amount' => '2.00']], ['fecha_operativa' => '2026-09-06']);
        $this->createTicket('2026-09-06 05:00:00', [['amount' => '200.00']]);

        $this->getJson($this->reportUrl('2026-09-05'))
            ->assertOk()
            ->assertJsonPath('data.period.to', '2026-09-05')
            ->assertJsonPath('data.summary.day_count', 1)
            ->assertJsonPath('data.summary.ticket_count', 2)
            ->assertJsonPath('data.days.0.date', '2026-09-05')
            ->assertJsonPath('data.days.0.amounts.0.amount', '3.00');
    }

    public function test_report_defaults_to_today_in_branch_timezone_and_empty_until_uses_from(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 02:00:00', 'UTC'));
        $this->createTicket('2026-09-05 05:00:00', [['amount' => '5.00']]);

        foreach (['/api/v1/despacho-productos/reporte-general', $this->reportUrl('', '')] as $url) {
            $this->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.today', '2026-09-05')
                ->assertJsonPath('data.period', ['from' => '2026-09-05', 'to' => '2026-09-05'])
                ->assertJsonPath('data.summary.ticket_count', 1);
        }
        $this->getJson($this->reportUrl('2026-09-04', ''))
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-09-04', 'to' => '2026-09-04'])
            ->assertJsonPath('data.summary.day_count', 0)
            ->assertJsonPath('data.summary.quantity', '0')
            ->assertJsonPath('data.summary.net_weight_kg', '0.000')
            ->assertJsonPath('data.summary.amounts', [])
            ->assertJsonPath('data.days', []);
    }

    public function test_report_rejects_invalid_and_reversed_date_ranges(): void
    {
        $this->getJson($this->reportUrl('2026-09-06', '2026-09-05'))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson($this->reportUrl('2026-02-30', ''))
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson($this->reportUrl('no-es-fecha', '2026-09-05'))
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson($this->reportUrl('2026-09-05', '2026-9-6'))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson($this->reportUrl('2026-09-05').'&preview=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('preview');
    }

    public function test_report_requires_dispatch_ticket_management_permission(): void
    {
        $restrictedUser = $this->createUserForCompany($this->user, ['sucursal_id' => $this->branchId]);
        Sanctum::actingAs($restrictedUser, ['api']);

        $this->getJson($this->reportUrl('2026-09-05'))->assertForbidden();
    }

    public function test_pdf_download_defaults_to_branch_today_and_blank_until_reuses_from(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 02:00:00', 'UTC'));
        $this->createTicket('2026-09-05 12:00:00', [['name' => 'Pavo histórico', 'amount' => '15.20']]);
        $this->actingAs($this->user);

        foreach ([
            '/despacho-productos/reporte-general/pdf' => '2026-09-05',
            '/despacho-productos/reporte-general/pdf?date_from=2026-09-04&date_to=' => '2026-09-04',
        ] as $url => $date) {
            $download = $this->get($url)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'attachment; filename="reporte-general-despacho-productos-'.$date.'-'.$date.'.pdf"')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('%PDF-', $download->getContent());
            $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        }

        $preview = $this->get('/despacho-productos/reporte-general/pdf?date_from=2026-09-05&preview=1')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline; filename="', (string) $preview->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $preview->getContent());
    }

    public function test_pdf_receives_the_same_scoped_report_as_the_api(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-06 18:00:00', 'UTC'));
        $largeVariation = $this->createVariation('Grande');
        $smallVariation = $this->createVariation('Pequeño');
        $this->createTicket('2026-09-05 12:00:00', [
            ['quantity' => 1, 'amount' => '0.04'],
            ['variation_id' => $largeVariation, 'variation' => 'Grande', 'quantity' => 1, 'amount' => '0.06'],
        ]);
        $this->createTicket('2026-09-06 12:00:00', [
            ['variation_id' => $smallVariation, 'variation' => 'Pequeño', 'quantity' => 3, 'amount' => '0.20'],
        ], ['moneda' => 'USD']);
        $this->createTicket('2026-09-05 12:00:00', [['name' => 'Producto ajeno', 'amount' => '900.00']], [
            'sucursal_id' => $this->createBranch((int) $this->user->empresa_id),
        ]);
        $foreignUser = User::factory()->create();
        $this->createTicket('2026-09-05 12:00:00', [['name' => 'Producto otra empresa', 'amount' => '800.00']], [
            'empresa_id' => $foreignUser->empresa_id,
            'sucursal_id' => $this->createBranch((int) $foreignUser->empresa_id),
            'created_by' => $foreignUser->id,
        ]);
        $apiReport = $this->getJson($this->reportUrl('2026-09-05', '2026-09-06'))
            ->assertOk()->json('data');
        $pdfData = null;
        View::composer('reports.product-dispatch-general', function (\Illuminate\View\View $view) use (&$pdfData): void {
            $pdfData = $view->getData();
        });

        $this->actingAs($this->user)
            ->get('/despacho-productos/reporte-general/pdf?date_from=2026-09-05&date_to=2026-09-06&empresa_id='.$foreignUser->empresa_id)
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertIsArray($pdfData);
        $this->assertSame((int) $this->user->empresa_id, (int) $pdfData['company']->id);
        $this->assertSame($apiReport, $pdfData['report']);
        $this->assertSame(2, $pdfData['report']['summary']['ticket_count']);
        $this->assertSame(3, $pdfData['report']['summary']['product_count']);
        $this->assertSame(['Pavo', 'Pavo · Grande'], array_column($pdfData['report']['days'][0]['products'], 'display_name'));
        $this->assertSame(['Pavo · Pequeño'], array_column($pdfData['report']['days'][1]['products'], 'display_name'));
        $this->assertSame([
            ['currency' => 'PEN', 'amount' => '0.10'],
            ['currency' => 'USD', 'amount' => '0.20'],
        ], $pdfData['report']['summary']['amounts']);
    }

    public function test_pdf_rejects_invalid_dates_before_rendering(): void
    {
        $this->actingAs($this->user)
            ->getJson('/despacho-productos/reporte-general/pdf?date_from=2026-09-06&date_to=2026-09-05')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/despacho-productos/reporte-general/pdf?date_from=2026-02-30')
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }

    public function test_report_view_and_module_menu_expose_date_filters_and_download(): void
    {
        $this->actingAs($this->user)->get('/despacho-productos/reporte-general')
            ->assertOk()
            ->assertSee('Reporte general')
            ->assertSee('name="date_from"', false)
            ->assertSee('name="date_to"', false)
            ->assertSee('Hasta en blanco')
            ->assertSee('Descargar PDF')
            ->assertSee('despacho-productos-reporte-general.js', false)
            ->assertSee('inputmode="none"', false);
        $this->get('/despacho-productos')
            ->assertOk()
            ->assertSee(route('despacho-productos.reporte-general'), false)
            ->assertSee('Reporte general');
    }

    private function reportUrl(string $from, ?string $to = null): string
    {
        return '/api/v1/despacho-productos/reporte-general?'.http_build_query([
            'date_from' => $from,
            ...($to === null ? [] : ['date_to' => $to]),
        ]);
    }

    private function createBranch(int $companyId): int
    {
        return (int) DB::table('sucursales')->insertGetId([
            'empresa_id' => $companyId,
            'codigo' => 'RG-'.Str::random(8),
            'nombre' => 'Sucursal reporte general',
            'zona_horaria' => 'America/Lima',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProduct(string $name): int
    {
        return (int) DB::table('productos_despacho')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_venta' => '12.0000',
            'merma_gramos_unidad' => 0,
            'estado' => ProductoDespacho::STATUS_ACTIVE,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVariation(string $name, ?int $productId = null): int
    {
        return (int) DB::table('variaciones_producto_despacho')->insertGetId([
            'producto_despacho_id' => $productId ?? $this->productId,
            'nombre' => $name,
            'nombre_normalizado' => mb_strtolower($name),
            'modo_precio' => ProductoDespacho::PRICE_MODE_KG,
            'precio_venta' => '12.0000',
            'merma_gramos_unidad' => 0,
            'orden' => 1,
            'estado' => 'ACTIVO',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function createTicket(string $registeredAt, array $lines, array $attributes = []): int
    {
        $sequence = ++$this->ticketSequence;
        $ticketId = (int) DB::table('tickets_despacho_productos')->insertGetId([
            'empresa_id' => $this->user->empresa_id,
            'sucursal_id' => $this->branchId,
            'referencia_externa' => (string) Str::uuid(),
            'numero_lista' => $sequence,
            'codigo' => 'RG-'.$sequence,
            'titulo_ticket_snapshot' => 'CONTROL DE DESPACHO',
            'fecha_operativa' => substr($registeredAt, 0, 10),
            'tipo_cliente' => TicketDespachoProducto::CUSTOMER_PUBLIC,
            'cliente_nombre_snapshot' => TicketDespachoProducto::PUBLIC_SALE_LABEL,
            'moneda' => 'PEN',
            'cantidad_total' => 0,
            'peso_leido_total_kg' => '0.000',
            'merma_total_gramos' => 0,
            'tara_total_gramos' => 0,
            'peso_neto_total_kg' => '0.000',
            'subtotal' => '0.00',
            'total' => '0.00',
            'estado' => TicketDespachoProducto::STATUS_REGISTERED,
            'registrado_at' => $registeredAt,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
        foreach ($lines as $index => $line) {
            DB::table('pesadas_despacho_productos')->insert([
                'ticket_despacho_producto_id' => $ticketId,
                'numero' => $index + 1,
                'producto_despacho_id' => $line['product_id'] ?? $this->productId,
                'variacion_producto_despacho_id' => $line['variation_id'] ?? null,
                'producto_nombre_snapshot' => $line['name'] ?? 'Pavo',
                'variacion_nombre_snapshot' => array_key_exists('variation', $line) ? $line['variation'] : (isset($line['variation_id']) ? 'Variación histórica' : null),
                'modo_precio_snapshot' => ProductoDespacho::PRICE_MODE_KG,
                'precio_catalogo_snapshot' => '12.0000',
                'precio_venta_snapshot' => '12.0000',
                'origen_precio' => 'CATALOGO',
                'cantidad' => $line['quantity'] ?? 1,
                'origen_peso' => 'MANUAL',
                'peso_leido_kg' => $line['read'] ?? '1.000',
                'merma_catalogo_gramos_unidad' => 0,
                'merma_aplicada_gramos_unidad' => 0,
                'merma_total_gramos' => $line['waste'] ?? 0,
                'tara_gramos' => $line['tare'] ?? 0,
                'peso_neto_kg' => $line['net'] ?? '1.000',
                'importe' => $line['amount'] ?? '12.00',
                'pesada_at' => $registeredAt,
                'created_by' => $attributes['created_by'] ?? $this->user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ticketId;
    }
}
