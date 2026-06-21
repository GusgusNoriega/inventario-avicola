<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tercero_id', 'rol'])]
class TerceroRole extends Model
{
    public const CLIENT = 'CLIENTE';

    public const PROVIDER = 'PROVEEDOR';

    public $timestamps = false;

    protected $table = 'tercero_roles';

    protected $primaryKey = 'tercero_id';

    public $incrementing = false;

    /**
     * @return BelongsTo<Tercero, $this>
     */
    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }
}
