<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Одна локально сохранённая официальная версия пакета. */
final class DiagnosticPackageVersion extends Model
{
    protected $table = 'mdiag_package_versions';

    protected $fillable = [
        'brand_id', 'source_serial',
        'version',
        'relative_path',
        'size_bytes',
        'md5',
        'sha256',
        'source_version_detail_id',
        'source_soft_id',
        'source_url',
        'release_notes',
        'published_at',
        'active',
    ];

    protected $casts = [
        'published_at' => 'immutable_datetime',
        'active' => 'boolean',
        'size_bytes' => 'integer',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(DiagnosticBrand::class, 'brand_id');
    }
}
