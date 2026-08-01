<?php

namespace App\Services;

use App\Models\Empresa;

class TicketMessageService
{
    public function current(int $companyId): ?string
    {
        $message = Empresa::query()->findOrFail($companyId)->mensaje_ticket;

        return is_string($message) ? $this->normalize($message) : null;
    }

    public function save(int $companyId, ?string $message): ?string
    {
        $message = $this->normalize($message);

        Empresa::query()->findOrFail($companyId)->update([
            'mensaje_ticket' => $message,
        ]);

        return $message;
    }

    public function normalize(?string $message): ?string
    {
        $message = $message === null ? null : trim($message);

        return $message === '' ? null : $message;
    }
}
