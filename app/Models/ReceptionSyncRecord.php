<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceptionSyncRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'revision' => 'integer', 'operating_date' => 'date:Y-m-d'];
    }

    public function document(): array
    {
        return [
            'id' => (int) $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'operating_date' => $this->operating_date->format('Y-m-d'),
            'status' => $this->status,
            'revision' => $this->revision,
            'device_id' => $this->device_id,
            'payload' => $this->payload,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
