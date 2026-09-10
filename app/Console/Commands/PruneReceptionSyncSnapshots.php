<?php

namespace App\Console\Commands;

use App\Services\ReceptionSyncSnapshotService;
use Illuminate\Console\Command;

class PruneReceptionSyncSnapshots extends Command
{
    protected $signature = 'reception-sync:prune-snapshots';

    protected $description = 'Elimina las descargas de recepción que ya cumplieron sus 24 horas de vigencia';

    public function handle(ReceptionSyncSnapshotService $snapshots): int
    {
        $count = $snapshots->pruneExpired();
        $this->info("Descargas vencidas eliminadas: {$count}.");

        return self::SUCCESS;
    }
}
