<?php

namespace App\Hte;

use Illuminate\Database\Eloquent\Model;

/** HTE (2026-10-16): one computer's own password and where it stands. See Passwords. */
class DevicePassword extends Model
{
    protected $table = 'hte_device_passwords';

    protected $guarded = ['id'];

    /** A new row starts at version 0 (none yet), like the column default. */
    protected $attributes = ['current_version' => 0, 'confirmed_version' => 0];

    protected function casts(): array
    {
        return [
            'capable_at' => 'datetime',
            'issued_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'current_version' => 'integer',
            'confirmed_version' => 'integer',
            'reach_user_ids' => 'array',
        ];
    }

    public function pending(): bool
    {
        return $this->current_version > 0 && $this->current_version !== $this->confirmed_version;
    }
}
