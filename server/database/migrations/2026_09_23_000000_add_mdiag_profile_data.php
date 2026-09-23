<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Профиль/мастерская из штатных экранов APK. Таблицы основного сайта не меняем. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mdiag_users', fn (Blueprint $table) => $table->json('profile_data')->nullable());
    }
    public function down(): void
    {
        Schema::table('mdiag_users', fn (Blueprint $table) => $table->dropColumn('profile_data'));
    }
};
