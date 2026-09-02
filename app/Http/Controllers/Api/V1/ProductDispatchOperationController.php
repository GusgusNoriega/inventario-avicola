<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductDispatch\StoreProductDispatchTicketRequest;
use App\Http\Requests\ProductDispatch\UpdateProductDispatchConfigurationRequest;
use App\Models\Balanza;
use App\Models\ProductoDespacho;
use App\Models\Tercero;
use App\Models\TerceroRole;
use App\Models\TicketDespachoProducto;
use App\Models\VariacionProductoDespacho;
use App\Services\OperationContextService;
use App\Services\ProductDispatchConfigurationService;
use App\Services\ProductDispatchOperationService;
use App\Services\TicketMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductDispatchOperationController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly ProductDispatchConfigurationService $configuration,
        private readonly ProductDispatchOperationService $dispatches,
        private readonly TicketMessageService $ticketMessages,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $clients = Tercero::query()
            ->where('empresa_id', $companyId)
            ->where('estado', Tercero::STATUS_ACTIVE)
            ->conRol(TerceroRole::CLIENT)
            ->orderBy('nombre_razon_social')
            ->get([
                'id',
                'tipo_documento',
                'numero_documento',
                'nombre_razon_social',
                'telefono',
            ]);
        $products = ProductoDespacho::query()
            ->where('empresa_id', $companyId)
            ->where('estado', ProductoDespacho::STATUS_ACTIVE)
            ->with(['variaciones' => fn ($query) => $query
                ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
                ->orderBy('orden')
                ->orderBy('nombre')])
            ->orderBy('nombre')
            ->get();
        $scale = Balanza::query()
            ->where('sucursal_id', $branch->id)
            ->where('codigo', Balanza::CODE_PRODUCT_DISPATCH)
            ->first();
        $serialConfiguration = [
            'baudRate' => 9600,
            'dataBits' => 8,
            'stopBits' => 1,
            'parity' => 'none',
            'flowControl' => 'none',
            ...(is_array($scale?->configuracion) ? $scale->configuracion : []),
        ];
        $dispatchConfiguration = $this->configuration->configuration(
            $companyId,
            (int) $branch->id,
        );
        $formattedProducts = $products->map(fn (ProductoDespacho $product): array => [
            'id' => (int) $product->id,
            'name' => $product->nombre,
            'description' => $product->descripcion,
            'price_mode' => $product->modo_precio,
            'price' => round((float) $product->precio_venta, 4, PHP_ROUND_HALF_UP),
            'waste_grams_per_unit' => (int) $product->merma_gramos_unidad,
            'image_url' => $this->productImageUrl($product),
            'variations' => $product->variaciones
                ->map(fn (VariacionProductoDespacho $variation): array => [
                    'id' => (int) $variation->id,
                    'name' => $variation->nombre,
                    'price_mode' => $variation->modo_precio,
                    'price' => round((float) $variation->precio_venta, 4, PHP_ROUND_HALF_UP),
                    'waste_grams_per_unit' => (int) $variation->merma_gramos_unidad,
                    'image_url' => $this->variationImageUrl($variation),
                    'order' => (int) $variation->orden,
                ])->values(),
        ])->values();
        $productsById = $formattedProducts->keyBy('id');
        $quickProducts = collect($dispatchConfiguration['quick_product_ids'])
            ->map(fn (int $productId): ?array => $productsById->get($productId))
            ->filter()
            ->values();

        return response()->json([
            'data' => [
                'product_ticket_title' => $dispatchConfiguration['product_ticket_title'],
                'ticket_title' => $dispatchConfiguration['product_ticket_title'],
                'ticket_message' => $this->ticketMessages->current($companyId),
                'currency' => (string) (DB::table('empresas')->where('id', $companyId)->value('moneda') ?: 'PEN'),
                'waste_presets' => $dispatchConfiguration['waste_presets'],
                'quick_product_ids' => $dispatchConfiguration['quick_product_ids'],
                'quick_products' => $quickProducts,
                'quick_products_configured' => $dispatchConfiguration['quick_products_configured'],
                'customer_display_title' => $dispatchConfiguration['customer_display_title'],
                'branch' => [
                    'id' => (int) $branch->id,
                    'name' => $branch->nombre,
                    'timezone' => $branch->zona_horaria,
                ],
                'public_customer' => [
                    'type' => TicketDespachoProducto::CUSTOMER_PUBLIC,
                    'name' => TicketDespachoProducto::PUBLIC_SALE_LABEL,
                ],
                'clients' => $clients->map(fn (Tercero $client): array => [
                    'id' => (int) $client->id,
                    'document_type' => $client->tipo_documento,
                    'document' => $client->numero_documento,
                    'name' => $client->nombre_razon_social,
                    'phone' => $client->telefono,
                ])->values(),
                'products' => $formattedProducts,
                'scale' => [
                    'code' => Balanza::CODE_PRODUCT_DISPATCH,
                    'name' => $scale?->nombre ?? Balanza::logicalName(Balanza::CODE_PRODUCT_DISPATCH),
                    'connection_mode' => $scale?->modo_conexion ?? 'SERIAL',
                    'device' => $scale?->dispositivo,
                    'configuration' => $serialConfiguration,
                ],
            ],
        ]);
    }

    public function updateConfiguration(
        UpdateProductDispatchConfigurationRequest $request,
    ): JsonResponse {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $configuration = $this->configuration->update(
            $companyId,
            (int) $branch->id,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Configuración de despacho actualizada correctamente.',
            'data' => $configuration,
        ]);
    }

    public function store(StoreProductDispatchTicketRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $dispatchConfiguration = $this->configuration->configuration(
            $companyId,
            (int) $branch->id,
        );
        $result = $this->dispatches->register(
            $companyId,
            $branch,
            $this->context->actor($request, (int) $branch->id),
            $request->validated(),
            $dispatchConfiguration['product_ticket_title'],
        );

        return response()->json([
            'message' => $result['already_registered']
                ? 'El despacho de productos ya estaba registrado.'
                : 'Despacho de productos registrado correctamente.',
            'already_registered' => $result['already_registered'],
            'data' => $this->formatTicket(
                $result['ticket'],
                $dispatchConfiguration['product_ticket_title'],
                $this->ticketMessages->current($companyId),
            ),
        ], $result['already_registered'] ? 200 : 201);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $dispatch = TicketDespachoProducto::query()
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branch->id)
            ->with([
                'sucursal',
                'cliente',
                'pesadas.producto',
                'pesadas.variacion',
            ])
            ->findOrFail($ticket);
        $dispatchConfiguration = $this->configuration->configuration(
            $companyId,
            (int) $branch->id,
        );

        return response()->json([
            'data' => $this->formatTicket(
                $dispatch,
                $dispatchConfiguration['product_ticket_title'],
                $this->ticketMessages->current($companyId),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function formatTicket(
        TicketDespachoProducto $ticket,
        string $ticketTitle,
        ?string $ticketMessage,
    ): array {
        $effectiveTicketTitle = trim((string) $ticket->titulo_ticket_snapshot)
            ?: $ticketTitle;

        return [
            'id' => (int) $ticket->id,
            'draft_id' => $ticket->referencia_externa,
            'code' => $ticket->codigo,
            'list_number' => $ticket->numero_lista !== null
                ? (int) $ticket->numero_lista
                : null,
            'status' => $ticket->estado,
            'operating_date' => $ticket->fecha_operativa?->format('Y-m-d'),
            'registered_at' => $ticket->registrado_at?->toISOString(),
            'product_ticket_title' => $effectiveTicketTitle,
            'ticket_title' => $effectiveTicketTitle,
            'ticket_message' => $ticketMessage,
            'currency' => $ticket->moneda,
            'customer_type' => $ticket->tipo_cliente,
            'customer_label' => $ticket->cliente_nombre_snapshot,
            'client' => $ticket->cliente_id
                ? [
                    'id' => (int) $ticket->cliente_id,
                    'document_type' => $ticket->cliente_tipo_documento_snapshot,
                    'document' => $ticket->cliente_numero_documento_snapshot,
                    'name' => $ticket->cliente_nombre_snapshot,
                ]
                : null,
            'totals' => [
                'weighings' => $ticket->pesadas->count(),
                'quantity' => (int) $ticket->cantidad_total,
                'read_weight_kg' => (float) $ticket->peso_leido_total_kg,
                'waste_grams' => (int) $ticket->merma_total_gramos,
                'waste_weight_kg' => round((int) $ticket->merma_total_gramos / 1000, 3),
                'tare_grams' => (int) $ticket->tara_total_gramos,
                'net_weight_kg' => (float) $ticket->peso_neto_total_kg,
                'subtotal' => (float) $ticket->subtotal,
                'amount' => (float) $ticket->total,
            ],
            'weighings' => $ticket->pesadas->map(function ($weighing): array {
                $productImageUrl = $weighing->producto
                    ? $this->productImageUrl($weighing->producto)
                    : null;
                $variationImageUrl = $weighing->variacion
                    ? $this->variationImageUrl($weighing->variacion)
                    : null;

                return [
                    'id' => (int) $weighing->id,
                    'number' => (int) $weighing->numero,
                    'product' => [
                        'id' => (int) $weighing->producto_despacho_id,
                        'name' => $weighing->producto_nombre_snapshot,
                        'image_url' => $productImageUrl,
                    ],
                    'variation' => $weighing->variacion_producto_despacho_id
                        ? [
                            'id' => (int) $weighing->variacion_producto_despacho_id,
                            'name' => $weighing->variacion_nombre_snapshot,
                            'image_url' => $variationImageUrl,
                        ]
                        : null,
                    'quantity' => (int) $weighing->cantidad,
                    'price_mode' => $weighing->modo_precio_snapshot,
                    'catalog_price' => (float) $weighing->precio_catalogo_snapshot,
                    'unit_price' => (float) $weighing->precio_venta_snapshot,
                    'price_origin' => $weighing->origen_precio,
                    'weight_source' => $weighing->origen_peso,
                    'read_weight_kg' => (float) $weighing->peso_leido_kg,
                    'catalog_waste_grams_per_unit' => (int) $weighing->merma_catalogo_gramos_unidad,
                    'waste_grams_per_unit' => (int) $weighing->merma_aplicada_gramos_unidad,
                    'catalog_waste_total_grams' => (int) $weighing->merma_catalogo_gramos_unidad
                        * (int) $weighing->cantidad,
                    'waste_total_grams' => (int) $weighing->merma_total_gramos,
                    'waste_weight_kg' => round((int) $weighing->merma_total_gramos / 1000, 3),
                    'tare_grams' => (int) $weighing->tara_gramos,
                    'net_weight_kg' => (float) $weighing->peso_neto_kg,
                    'amount' => (float) $weighing->importe,
                    'weighed_at' => $weighing->pesada_at?->toISOString(),
                ];
            })->values(),
        ];
    }

    private function productImageUrl(ProductoDespacho $product): ?string
    {
        return $product->imagen_path
            ? "/api/v1/productos-despacho/{$product->id}/imagen?v=".$product->updated_at?->timestamp
            : null;
    }

    private function variationImageUrl(VariacionProductoDespacho $variation): ?string
    {
        return $variation->imagen_path
            ? "/api/v1/productos-despacho/{$variation->producto_despacho_id}/variaciones/{$variation->id}/imagen?v=".$variation->updated_at?->timestamp
            : null;
    }
}
