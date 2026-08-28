<?php

namespace App\Services;

use App\Http\Requests\ProductDispatch\SaveDispatchProductRequest;
use App\Models\ProductoDespacho;
use App\Models\User;
use App\Models\VariacionProductoDespacho;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DispatchProductCatalogService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): ProductoDespacho
    {
        $normalizedName = SaveDispatchProductRequest::normalizeName((string) $data['nombre']);
        $product = ProductoDespacho::query()
            ->where('empresa_id', $user->empresa_id)
            ->where('nombre_normalizado', $normalizedName)
            ->first();

        if ($product) {
            throw ValidationException::withMessages([
                'nombre' => $product->estado === ProductoDespacho::STATUS_ACTIVE
                    ? 'Ya existe un producto activo con este nombre.'
                    : 'Ya existe un producto eliminado con este nombre. Ábrelo desde el filtro de eliminados para restaurarlo.',
            ]);
        }

        $product = new ProductoDespacho([
            'empresa_id' => $user->empresa_id,
            'created_by' => $user->id,
        ]);

        return $this->persist($product, $user, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductoDespacho $product, User $user, array $data): ProductoDespacho
    {
        $normalizedName = SaveDispatchProductRequest::normalizeName((string) $data['nombre']);
        $duplicate = ProductoDespacho::query()
            ->where('empresa_id', $user->empresa_id)
            ->where('nombre_normalizado', $normalizedName)
            ->whereKeyNot($product->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe otro producto con este nombre.',
            ]);
        }

        return $this->persist($product, $user, $data);
    }

    public function deactivate(ProductoDespacho $product, User $user): void
    {
        $product->update([
            'estado' => ProductoDespacho::STATUS_INACTIVE,
            'updated_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(ProductoDespacho $product, User $user, array $data): ProductoDespacho
    {
        $createdImagePaths = [];
        $obsoleteImagePaths = [];

        try {
            $saved = DB::transaction(function () use (
                $product,
                $user,
                $data,
                &$createdImagePaths,
                &$obsoleteImagePaths,
            ): ProductoDespacho {
                $productImage = $this->resolvedImagePath(
                    $data['imagen'] ?? null,
                    (bool) ($data['eliminar_imagen'] ?? false),
                    $product->imagen_path,
                    (int) $user->empresa_id,
                    'productos',
                    $createdImagePaths,
                    $obsoleteImagePaths,
                );

                $product->fill([
                    'nombre' => $data['nombre'],
                    'nombre_normalizado' => SaveDispatchProductRequest::normalizeName($data['nombre']),
                    'descripcion' => $data['descripcion'] ?? null,
                    'modo_precio' => $data['modo_precio'],
                    'precio_venta' => $data['precio_venta'],
                    'merma_gramos_unidad' => $data['merma_gramos_unidad'],
                    'imagen_path' => $productImage,
                    'estado' => $data['estado'] ?? ProductoDespacho::STATUS_ACTIVE,
                    'updated_by' => $user->id,
                ]);

                if (! $product->exists) {
                    $product->empresa_id = $user->empresa_id;
                    $product->created_by = $user->id;
                }

                $product->save();

                if (array_key_exists('variaciones', $data)
                    || (bool) ($data['sincronizar_variaciones'] ?? false)) {
                    $this->syncVariations(
                        $product,
                        $user,
                        $data['variaciones'] ?? [],
                        $createdImagePaths,
                        $obsoleteImagePaths,
                    );
                }

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteImages($createdImagePaths);

            throw $exception;
        }

        $this->deleteImages($obsoleteImagePaths);

        return $saved->refresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $variations
     * @param  list<string>  $createdImagePaths
     * @param  list<string>  $obsoleteImagePaths
     */
    private function syncVariations(
        ProductoDespacho $product,
        User $user,
        array $variations,
        array &$createdImagePaths,
        array &$obsoleteImagePaths,
    ): void {
        $existing = VariacionProductoDespacho::query()
            ->where('producto_despacho_id', $product->id)
            ->get();
        $byId = $existing->keyBy('id');
        $byName = $existing->keyBy('nombre_normalizado');
        $touchedIds = [];

        foreach (array_values($variations) as $order => $data) {
            $normalizedName = SaveDispatchProductRequest::normalizeName((string) $data['nombre']);
            $variationId = isset($data['id']) ? (int) $data['id'] : null;

            if ($variationId !== null && ! $byId->has($variationId)) {
                throw ValidationException::withMessages([
                    "variaciones.{$order}.id" => 'La variación seleccionada no pertenece a este producto.',
                ]);
            }

            $variation = $variationId !== null
                ? $byId->get($variationId)
                : $byName->get($normalizedName);
            $restoringInactiveVariation = $variationId === null
                && $variation?->estado === VariacionProductoDespacho::STATUS_INACTIVE;

            $sameNameVariation = $byName->get($normalizedName);
            if ($variationId !== null
                && $sameNameVariation
                && (int) $sameNameVariation->id !== $variationId) {
                throw ValidationException::withMessages([
                    "variaciones.{$order}.nombre" => 'Ya existe otra variación con este nombre.',
                ]);
            }

            $variation ??= new VariacionProductoDespacho([
                'producto_despacho_id' => $product->id,
                'created_by' => $user->id,
            ]);

            if (in_array((int) $variation->id, $touchedIds, true)) {
                throw ValidationException::withMessages([
                    'variaciones' => 'No puedes enviar dos veces la misma variación.',
                ]);
            }

            $uploadedImage = $data['imagen'] ?? null;
            $imagePath = $this->resolvedImagePath(
                $uploadedImage,
                (bool) ($data['eliminar_imagen'] ?? false)
                    || ($restoringInactiveVariation && ! ($uploadedImage instanceof UploadedFile)),
                $variation->imagen_path,
                (int) $user->empresa_id,
                'variaciones',
                $createdImagePaths,
                $obsoleteImagePaths,
            );

            $variation->fill([
                'nombre' => $data['nombre'],
                'nombre_normalizado' => $normalizedName,
                'modo_precio' => $data['modo_precio'],
                'precio_venta' => $data['precio_venta'],
                'merma_gramos_unidad' => $data['merma_gramos_unidad'],
                'imagen_path' => $imagePath,
                'orden' => $order,
                'estado' => VariacionProductoDespacho::STATUS_ACTIVE,
                'updated_by' => $user->id,
            ]);

            if (! $variation->exists) {
                $variation->created_by = $user->id;
            }

            $variation->save();
            $touchedIds[] = (int) $variation->id;
        }

        VariacionProductoDespacho::query()
            ->where('producto_despacho_id', $product->id)
            ->where('estado', VariacionProductoDespacho::STATUS_ACTIVE)
            ->when(
                $touchedIds !== [],
                fn ($query) => $query->whereNotIn('id', $touchedIds),
            )
            ->update([
                'estado' => VariacionProductoDespacho::STATUS_INACTIVE,
                'updated_by' => $user->id,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $createdImagePaths
     * @param  list<string>  $obsoleteImagePaths
     */
    private function resolvedImagePath(
        mixed $uploadedImage,
        bool $removeImage,
        ?string $currentPath,
        int $companyId,
        string $folder,
        array &$createdImagePaths,
        array &$obsoleteImagePaths,
    ): ?string {
        if ($uploadedImage instanceof UploadedFile) {
            $path = $uploadedImage->store(
                "productos-despacho/{$companyId}/{$folder}",
                'public',
            );

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('No se pudo guardar la imagen del producto.');
            }

            $createdImagePaths[] = $path;

            if ($currentPath) {
                $obsoleteImagePaths[] = $currentPath;
            }

            return $path;
        }

        if ($removeImage && $currentPath) {
            $obsoleteImagePaths[] = $currentPath;

            return null;
        }

        return $currentPath;
    }

    /** @param list<string> $paths */
    private function deleteImages(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
