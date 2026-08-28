<?php

namespace Tests\Feature;

use App\Models\ProductoDespacho;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithAccessControl;
use Tests\TestCase;

class DispatchProductCatalogApiTest extends TestCase
{
    use InteractsWithAccessControl;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->grantModules($this->user, ['MODULO_DESPACHO_PRODUCTOS']);
        Sanctum::actingAs($this->user, ['api']);
    }

    public function test_product_and_variations_keep_independent_prices_and_waste(): void
    {
        $response = $this->postJson('/api/v1/productos-despacho', [
            'nombre' => ' Huevo ',
            'descripcion' => 'Producto vendido sin bandeja',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '0.7500',
            'merma_gramos_unidad' => 0,
            'sincronizar_variaciones' => true,
            'variaciones' => [
                [
                    'nombre' => 'Rojo grande',
                    'modo_precio' => 'POR_UNIDAD',
                    'precio_venta' => '0.9500',
                    'merma_gramos_unidad' => 2,
                ],
                [
                    'nombre' => 'Selección por peso',
                    'modo_precio' => 'POR_KG',
                    'precio_venta' => '8.5000',
                    'merma_gramos_unidad' => 4,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Huevo')
            ->assertJsonPath('data.price_mode', 'POR_UNIDAD')
            ->assertJsonPath('data.price', '0.7500')
            ->assertJsonPath('data.waste_grams_per_unit', 0)
            ->assertJsonPath('data.variations.0.name', 'Rojo grande')
            ->assertJsonPath('data.variations.0.price', '0.9500')
            ->assertJsonPath('data.variations.0.waste_grams_per_unit', 2)
            ->assertJsonPath('data.variations.1.price_mode', 'POR_KG')
            ->assertJsonPath('data.variations.1.price', '8.5000');

        $productId = $response->json('data.id');

        $this->assertDatabaseHas('productos_despacho', [
            'id' => $productId,
            'empresa_id' => $this->user->empresa_id,
            'nombre_normalizado' => 'huevo',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => 0.7500,
        ]);
        $this->assertDatabaseHas('variaciones_producto_despacho', [
            'producto_despacho_id' => $productId,
            'nombre_normalizado' => 'selección por peso',
            'modo_precio' => 'POR_KG',
            'precio_venta' => 8.5000,
            'merma_gramos_unidad' => 4,
        ]);

        $this->getJson('/api/v1/productos-despacho?buscar=Selección')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productId)
            ->assertJsonPath('summary.active_products', 1)
            ->assertJsonPath('summary.active_variations', 2);
    }

    public function test_updating_the_variation_list_deactivates_removed_variations(): void
    {
        $created = $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Gallina',
            'modo_precio' => 'POR_KG',
            'precio_venta' => '12.0000',
            'merma_gramos_unidad' => 10,
            'variaciones' => [
                $this->variation('Roja', '13.0000', 12),
                $this->variation('Doble', '14.0000', 15),
            ],
        ])->assertCreated();

        $productId = $created->json('data.id');
        $keptId = $created->json('data.variations.0.id');
        $removedId = $created->json('data.variations.1.id');

        $this->putJson("/api/v1/productos-despacho/{$productId}", [
            'nombre' => 'Gallina criolla',
            'modo_precio' => 'POR_KG',
            'precio_venta' => '12.5000',
            'merma_gramos_unidad' => 11,
            'sincronizar_variaciones' => true,
            'variaciones' => [[
                'id' => $keptId,
                ...$this->variation('Roja premium', '15.0000', 20),
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gallina criolla')
            ->assertJsonCount(1, 'data.variations')
            ->assertJsonPath('data.variations.0.id', $keptId)
            ->assertJsonPath('data.variations.0.price', '15.0000');

        $this->assertDatabaseHas('variaciones_producto_despacho', [
            'id' => $removedId,
            'estado' => VariacionProductoDespacho::STATUS_INACTIVE,
        ]);
    }

    public function test_images_are_optional_replaceable_and_served_without_a_public_symlink(): void
    {
        Storage::fake('public');

        $created = $this->post('/api/v1/productos-despacho', [
            'nombre' => 'Pavo',
            'modo_precio' => 'POR_KG',
            'precio_venta' => '18.0000',
            'merma_gramos_unidad' => 25,
            'imagen' => UploadedFile::fake()->image('pavo.jpg', 640, 480),
            'variaciones' => [[
                ...$this->variation('Pavo grande', '20.0000', 30),
                'imagen' => UploadedFile::fake()->image('pavo-grande.png', 320, 320),
            ]],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pavo');

        $product = ProductoDespacho::query()->findOrFail($created->json('data.id'));
        $variation = VariacionProductoDespacho::query()->where('producto_despacho_id', $product->id)->firstOrFail();
        Storage::disk('public')->assertExists($product->imagen_path);
        Storage::disk('public')->assertExists($variation->imagen_path);

        $this->get($created->json('data.image_url'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get($created->json('data.variations.0.image_url'))->assertOk();

        $oldPath = $product->imagen_path;
        $this->post("/api/v1/productos-despacho/{$product->id}", [
            '_method' => 'PUT',
            'nombre' => 'Pavo',
            'modo_precio' => 'POR_KG',
            'precio_venta' => '18.5000',
            'merma_gramos_unidad' => 25,
            'imagen' => UploadedFile::fake()->image('pavo-nuevo.jpg', 500, 400),
        ], ['Accept' => 'application/json'])->assertOk();

        $product->refresh();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($product->imagen_path);

        $this->putJson("/api/v1/productos-despacho/{$product->id}", [
            'nombre' => 'Pavo',
            'modo_precio' => 'POR_KG',
            'precio_venta' => '18.5000',
            'merma_gramos_unidad' => 25,
            'eliminar_imagen' => true,
        ])->assertOk()->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing($product->imagen_path);
    }

    public function test_readding_an_inactive_variation_does_not_restore_its_hidden_image(): void
    {
        Storage::fake('public');

        $created = $this->post('/api/v1/productos-despacho', [
            'nombre' => 'Huevo fértil',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '1.5000',
            'merma_gramos_unidad' => 0,
            'variaciones' => [[
                ...$this->variation('Grande', '1.8000', 1),
                'imagen' => UploadedFile::fake()->image('grande.jpg', 320, 320),
            ]],
        ], ['Accept' => 'application/json'])->assertCreated();

        $productId = $created->json('data.id');
        $variationId = $created->json('data.variations.0.id');
        $variation = VariacionProductoDespacho::query()->findOrFail($variationId);
        $oldImagePath = $variation->imagen_path;

        $this->putJson("/api/v1/productos-despacho/{$productId}", [
            'nombre' => 'Huevo fértil',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '1.5000',
            'merma_gramos_unidad' => 0,
            'sincronizar_variaciones' => true,
            'variaciones' => [],
        ])->assertOk()->assertJsonCount(0, 'data.variations');

        $this->putJson("/api/v1/productos-despacho/{$productId}", [
            'nombre' => 'Huevo fértil',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '1.5000',
            'merma_gramos_unidad' => 0,
            'variaciones' => [$this->variation('Grande', '1.9000', 1)],
        ])
            ->assertOk()
            ->assertJsonPath('data.variations.0.id', $variationId)
            ->assertJsonPath('data.variations.0.image_url', null);

        Storage::disk('public')->assertMissing($oldImagePath);
        $this->assertDatabaseHas('variaciones_producto_despacho', [
            'id' => $variationId,
            'estado' => VariacionProductoDespacho::STATUS_ACTIVE,
            'imagen_path' => null,
        ]);
    }

    public function test_delete_is_reversible_and_preserves_catalog_information(): void
    {
        $created = $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Codorniz',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '7.0000',
            'merma_gramos_unidad' => 0,
        ])->assertCreated();
        $productId = $created->json('data.id');

        $this->deleteJson("/api/v1/productos-despacho/{$productId}")
            ->assertOk();
        $this->assertDatabaseHas('productos_despacho', [
            'id' => $productId,
            'estado' => ProductoDespacho::STATUS_INACTIVE,
        ]);
        $this->getJson('/api/v1/productos-despacho')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/productos-despacho?estado=INACTIVO')
            ->assertJsonPath('data.0.id', $productId);

        $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'CODORNIZ',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '7.5000',
            'merma_gramos_unidad' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');

        $this->putJson("/api/v1/productos-despacho/{$productId}", [
            'nombre' => 'CODORNIZ',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => '7.5000',
            'merma_gramos_unidad' => 1,
            'estado' => ProductoDespacho::STATUS_ACTIVE,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $productId)
            ->assertJsonPath('data.status', ProductoDespacho::STATUS_ACTIVE);
    }

    public function test_validation_rejects_invalid_prices_waste_duplicate_variations_and_images(): void
    {
        $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Producto inválido',
            'modo_precio' => 'OTRO',
            'precio_venta' => 0,
            'merma_gramos_unidad' => -1,
            'variaciones' => [
                $this->variation('Grande', '1.0000', 0),
                $this->variation(' grande ', '2.0000', 0),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'modo_precio',
                'precio_venta',
                'merma_gramos_unidad',
                'variaciones',
            ]);

        $this->post('/api/v1/productos-despacho', [
            'nombre' => 'Imagen inválida',
            'modo_precio' => 'POR_KG',
            'precio_venta' => 10,
            'merma_gramos_unidad' => 0,
            'imagen' => UploadedFile::fake()->create('archivo.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('imagen');

        $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Variación malformada',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => 1,
            'merma_gramos_unidad' => 0,
            'variaciones' => ['texto'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variaciones.0');

        $tooManyVariations = collect(range(1, 20))
            ->map(fn (int $number): array => $this->variation("Tamaño {$number}", '1.0000', 0))
            ->all();
        $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Demasiadas variaciones',
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => 1,
            'merma_gramos_unidad' => 0,
            'variaciones' => $tooManyVariations,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variaciones');

        $this->postJson('/api/v1/productos-despacho', [
            'nombre' => ['no', 'es', 'texto'],
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => 1,
            'merma_gramos_unidad' => 0,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nombre');
    }

    public function test_catalog_is_isolated_by_company_and_requires_its_module(): void
    {
        $created = $this->postJson('/api/v1/productos-despacho', [
            'nombre' => 'Producto privado',
            'modo_precio' => 'POR_KG',
            'precio_venta' => 5,
            'merma_gramos_unidad' => 0,
        ])->assertCreated();
        $productId = $created->json('data.id');

        $otherUser = User::factory()->create();
        $this->grantModules($otherUser, ['MODULO_DESPACHO_PRODUCTOS'], 'OTRA_EMPRESA');
        Sanctum::actingAs($otherUser, ['api']);
        $this->getJson('/api/v1/productos-despacho')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/productos-despacho/{$productId}")->assertNotFound();
        $this->putJson("/api/v1/productos-despacho/{$productId}", [
            'nombre' => 'Producto privado',
            'modo_precio' => 'POR_KG',
            'precio_venta' => 5,
            'merma_gramos_unidad' => 0,
        ])->assertNotFound();
        $this->deleteJson("/api/v1/productos-despacho/{$productId}")->assertNotFound();

        $unauthorized = User::factory()->create();
        $this->grantModules($unauthorized, ['MODULO_DIRECTORIO'], 'SIN_PRODUCTOS');
        Sanctum::actingAs($unauthorized, ['api']);
        $this->getJson('/api/v1/productos-despacho')->assertForbidden();
        $this->postJson('/api/v1/productos-despacho', [])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function variation(string $name, string $price, int $waste): array
    {
        return [
            'nombre' => $name,
            'modo_precio' => 'POR_UNIDAD',
            'precio_venta' => $price,
            'merma_gramos_unidad' => $waste,
        ];
    }
}
