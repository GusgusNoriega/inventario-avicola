<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class ModuleAvailabilityService
{
    private const TABLE = 'modulos_sistema';

    /** @var list<string>|null */
    private ?array $resolvedDisabledCodes = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allModules(): array
    {
        return config('access_modules.modules', []);
    }

    /**
     * @return list<string>
     */
    public function allCodes(): array
    {
        return array_keys($this->allModules());
    }

    /**
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        $disabled = $this->disabledCodes();

        return collect($this->allCodes())
            ->reject(fn (string $code): bool => in_array($code, $disabled, true))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function disabledCodes(): array
    {
        if ($this->resolvedDisabledCodes !== null) {
            return $this->resolvedDisabledCodes;
        }

        if (! Schema::hasTable(self::TABLE)) {
            return $this->resolvedDisabledCodes = [];
        }

        $codes = DB::table(self::TABLE)
            ->where('activo', false)
            ->pluck('codigo')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        return $this->resolvedDisabledCodes = collect($codes)
            ->intersect($this->allCodes())
            ->values()
            ->all();
    }

    public function isEnabled(string $code): bool
    {
        $code = $this->normalizeCode($code);

        return in_array($code, $this->enabledCodes(), true);
    }

    /**
     * At least one of the supplied modules must be enabled. This preserves the
     * existing OR semantics used by routes shared between two modules.
     *
     * @param  iterable<int, string>  $codes
     */
    public function anyEnabled(iterable $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->isEnabled((string) $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code: string, name: string, enabled: bool, path: string}
     */
    public function status(string $code): array
    {
        $code = $this->normalizeCode($code);
        $module = $this->allModules()[$code];

        return [
            'code' => $code,
            'name' => (string) ($module['name'] ?? $code),
            'enabled' => $this->isEnabled($code),
            'path' => (string) ($module['path'] ?? ''),
        ];
    }

    /**
     * @return list<array{code: string, name: string, enabled: bool, path: string}>
     */
    public function statuses(): array
    {
        return collect($this->allCodes())
            ->map(fn (string $code): array => $this->status($code))
            ->all();
    }

    public function setEnabled(string $code, bool $enabled): bool
    {
        $code = $this->normalizeCode($code);

        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException(
                'No existe la tabla de módulos del sistema. Ejecuta primero: php artisan migrate'
            );
        }

        if ($this->isEnabled($code) === $enabled) {
            return false;
        }

        $now = now();

        DB::table(self::TABLE)->upsert(
            [[
                'codigo' => $code,
                'activo' => $enabled,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['codigo'],
            ['activo', 'updated_at'],
        );

        $this->resolvedDisabledCodes = null;

        return true;
    }

    public function normalizeCode(string $code): string
    {
        $normalized = mb_strtoupper(trim($code), 'UTF-8');

        if (! array_key_exists($normalized, $this->allModules())) {
            throw new InvalidArgumentException("El módulo {$normalized} no existe.");
        }

        return $normalized;
    }
}
