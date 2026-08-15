<?php

namespace App\Services;

use App\Models\Empresa;

class TicketTitleService
{
    public const DEFAULT_TITLE = 'DISTRIBUIDORA DIEGO ALBERTO';

    public function current(int $companyId): string
    {
        $title = Empresa::query()->findOrFail($companyId)->titulo_ticket;

        return $this->normalize(is_string($title) ? $title : null);
    }

    public function save(int $companyId, string $title): string
    {
        $title = $this->normalize($title);

        Empresa::query()->findOrFail($companyId)->update([
            'titulo_ticket' => $title,
        ]);

        return $title;
    }

    public function normalize(?string $title): string
    {
        $title = $title === null ? '' : trim($title);

        return $title === '' ? self::DEFAULT_TITLE : $title;
    }
}
