<?php

namespace App\Services;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GeneralConfigurationService
{
    /** @return array{company_name: string, cutoff: string, timezone: string} */
    public function current(int $companyId): array
    {
        return $this->serialize(Empresa::query()->findOrFail($companyId));
    }

    /** @return array{company_name: string, cutoff: string, timezone: string, reclassified: array<string, int>} */
    public function update(int $companyId, string $cutoff, string $expectedCutoff, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $cutoff, $expectedCutoff, $actorId): array {
            $company = Empresa::query()->lockForUpdate()->findOrFail($companyId);

            if ($this->serialize($company)['cutoff'] !== $expectedCutoff) {
                throw ValidationException::withMessages([
                    'expected_cutoff' => 'La hora fue modificada desde otra estación. Recarga la configuración y revisa el horario vigente antes de guardar.',
                ]);
            }

            $summary = app(JourneyReclassificationService::class)->reclassify($companyId, $cutoff.':00', $actorId);
            $before = ['hora_corte_operativo' => $company->hora_corte_operativo];
            $company->update(['hora_corte_operativo' => $cutoff.':00']);
            app(FinancialAuditService::class)->record($companyId, $actorId, 'empresas', $companyId,
                'RECALCULAR_JORNADA', $before, ['hora_corte_operativo' => $cutoff.':00', 'reclassified' => $summary]);

            return [...$this->serialize($company), 'reclassified' => $summary];
        }, 3);
    }

    /** @return array{company_name: string, cutoff: string, timezone: string} */
    private function serialize(Empresa $company): array
    {
        return [
            'company_name' => (string) ($company->nombre_comercial ?: $company->razon_social),
            'cutoff' => substr((string) ($company->hora_corte_operativo ?: '21:00:00'), 0, 5),
            'timezone' => (string) $company->zona_horaria,
        ];
    }
}
