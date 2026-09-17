<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Марка автомобиля или общий программный компонент конкретного приложения. */
final class DiagnosticBrand extends Model
{
    protected $table = 'mdiag_brands';

    protected $fillable = [
        'provider',
        'kind',
        'code',
        'name',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(DiagnosticPackageVersion::class, 'brand_id');
    }
}
