<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Models;

use Illuminate\Database\Eloquent\Model;

/** Разрешение скачивания для отдельного SN и необязательный whitelist марок. */
final class ScannerAccessRule extends Model
{
    protected $table = 'mdiag_scanner_access_rules';

    protected $fillable = [
        'provider',
        'serial',
        'downloads_allowed',
        'allowed_modules',
        'note', 'user_id', 'expires_at', 'denied_message',
        'last_seen_at',
    ];

    protected $casts = [
        'downloads_allowed' => 'boolean',
        'allowed_modules' => 'array',
        'expires_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
    ];
}
