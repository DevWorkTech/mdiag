<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Models;
use Illuminate\Database\Eloquent\Model;

/** Отдельная сессия APK, не Laravel browser session. */
final class LocalSession extends Model
{
    protected $table = 'mdiag_sessions';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash', 'token_ciphertext'];
    protected $casts = ['expires_at' => 'immutable_datetime', 'token_ciphertext' => 'encrypted'];
}
