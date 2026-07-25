<?php

namespace App\Http\Requests\Finance;

use App\Models\Tercero;
use App\Models\TerceroRole;
use Closure;
use Illuminate\Support\Facades\DB;

class UpdateFinancialTicketClientRequest extends FinancialFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cliente_id' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = DB::table('terceros as tercero')
                        ->join('tercero_roles as rol', 'rol.tercero_id', '=', 'tercero.id')
                        ->where('tercero.id', (int) $value)
                        ->where('tercero.empresa_id', $this->companyId())
                        ->where('tercero.estado', Tercero::STATUS_ACTIVE)
                        ->where('rol.rol', TerceroRole::CLIENT)
                        ->exists();

                    if (! $exists) {
                        $fail('Selecciona un cliente activo de esta empresa.');
                    }
                },
            ],
        ];
    }
}
