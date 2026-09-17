<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Создаёт каталог марок и историю локально сохранённых версий. */
    public function up(): void
    {
        Schema::create('mdiag_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('kind', 32)->default('diagnostic');
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'kind', 'code']);
            $table->index(['provider', 'kind', 'enabled']);
        });

        Schema::create('mdiag_package_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')
                ->constrained('mdiag_brands')
                ->cascadeOnDelete();
            $table->string('version', 80);
            $table->string('source_serial', 128)->default('');
            $table->string('relative_path', 1024);
            $table->unsignedBigInteger('size_bytes');
            $table->char('md5', 32);
            $table->char('sha256', 64);
            $table->string('source_version_detail_id', 160)->nullable();
            $table->string('source_soft_id', 160)->nullable();
            $table->text('source_url')->nullable();
            $table->text('release_notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['brand_id', 'version', 'source_serial']);
            $table->index(['brand_id', 'active', 'published_at']);
            $table->index('source_version_detail_id');
        });
    }

    /** Удаляет таблицы в порядке внешних ключей. */
    public function down(): void
    {
        Schema::dropIfExists('mdiag_package_versions');
        Schema::dropIfExists('mdiag_brands');
    }
};
