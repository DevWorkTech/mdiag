<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Создаёт ACL по профилю, SN и необязательному списку марок. */
    public function up(): void
    {
        Schema::create('mdiag_scanner_access_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('serial', 128);
            $table->boolean('downloads_allowed')->default(true);
            $table->json('allowed_modules')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'serial']);
            $table->index(['provider', 'downloads_allowed']);
        });
    }

    /** Удаляет таблицу правил доступа. */
    public function down(): void
    {
        Schema::dropIfExists('mdiag_scanner_access_rules');
    }
};
