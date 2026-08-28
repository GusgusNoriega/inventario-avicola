<?php

namespace App\Console\Commands;

use App\Services\ModuleAvailabilityService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ManageModules extends Command
{
    protected $signature = 'modulos
        {accion=listar : Acción a ejecutar: listar, activar o desactivar}
        {modulo? : Código del módulo que se desea activar o desactivar}';

    protected $description = 'Lista, activa o desactiva módulos completos del sistema';

    public function handle(ModuleAvailabilityService $modules): int
    {
        $action = mb_strtolower(trim((string) $this->argument('accion')), 'UTF-8');

        if (in_array($action, ['listar', 'lista', 'estado', 'estados'], true)) {
            if ($this->argument('modulo') !== null) {
                $this->error('La acción listar no recibe un código de módulo.');
                $this->showInstructions();

                return self::INVALID;
            }

            $this->showStatuses($modules);
            $this->showInstructions();

            return self::SUCCESS;
        }

        if (! in_array($action, ['activar', 'desactivar'], true)) {
            $this->error("La acción '{$action}' no existe.");
            $this->showInstructions();

            return self::INVALID;
        }

        $module = trim((string) $this->argument('modulo'));

        if ($module === '') {
            $this->error("Debes indicar el código del módulo que deseas {$action}.");
            $this->showInstructions();

            return self::INVALID;
        }

        $enabled = $action === 'activar';

        try {
            $code = $modules->normalizeCode($module);
            $changed = $modules->setEnabled($code, $enabled);
            $status = $modules->status($code);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($changed) {
            $this->components->info(sprintf(
                'Módulo %s: %s (%s)',
                $enabled ? 'activado' : 'desactivado',
                $status['name'],
                $status['code'],
            ));
        } else {
            $this->components->warn(sprintf(
                'Sin cambios: %s (%s) ya estaba %s.',
                $status['name'],
                $status['code'],
                $enabled ? 'activo' : 'inactivo',
            ));
        }

        return self::SUCCESS;
    }

    private function showStatuses(ModuleAvailabilityService $modules): void
    {
        $this->newLine();
        $this->components->info('Estado de los módulos del sistema');
        $this->table(
            ['Código', 'Módulo', 'Ruta principal', 'Estado'],
            collect($modules->statuses())
                ->map(fn (array $module): array => [
                    $module['code'],
                    $module['name'],
                    $module['path'],
                    $module['enabled'] ? 'ACTIVO' : 'INACTIVO',
                ])
                ->all(),
        );
    }

    private function showInstructions(): void
    {
        $this->newLine();
        $this->components->info('Instrucciones');
        $this->line('  Ver el estado:        php artisan modulos');
        $this->line('  Activar un módulo:    php artisan modulos activar MODULO_FINANZAS');
        $this->line('  Desactivar un módulo: php artisan modulos desactivar MODULO_FINANZAS');
        $this->newLine();
        $this->comment('Usa exactamente uno de los códigos mostrados en la tabla.');
    }
}
