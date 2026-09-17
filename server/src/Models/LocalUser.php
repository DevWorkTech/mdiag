<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Models;
use Illuminate\Database\Eloquent\Model;

/** Локальная учётная запись; никогда не передаётся в клиент синхронизации. */
final class LocalUser extends Model
{
    protected $table = 'mdiag_users';
    protected $guarded = ['id'];
    protected $hidden = ['password'];
    protected $casts = [
        'active' => 'boolean', 'downloads_allowed' => 'boolean',
        'expires_at' => 'immutable_datetime', 'allowed_modules' => 'array',
    ];
}
