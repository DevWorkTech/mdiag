<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Таблицы не связаны с users/sessions основного Laravel-проекта. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mdiag_users', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('login', 128);
            $table->string('password'); // Только Laravel Hash, исходный пароль не хранится.
            $table->boolean('active')->default(false);
            $table->boolean('downloads_allowed')->default(false);
            $table->timestamp('expires_at')->nullable(); // null — локальный доступ без срока.
            $table->json('allowed_modules')->nullable(); // null — все, [] — ни одного.
            $table->text('denied_message')->nullable();
            $table->text('expired_message')->nullable();
            $table->text('module_denied_message')->nullable();
            $table->text('notification_text')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'login']);
        });
        Schema::create('mdiag_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('mdiag_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            // Зашифрованный APP_KEY токен нужен для штатной MD5 SOAP-подписи APK.
            $table->text('token_ciphertext');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('mdiag_scanner_access_rules', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained('mdiag_users')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->text('denied_message')->nullable();
        });
        Schema::create('mdiag_error_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('key', 80);
            $table->integer('code');
            $table->unsignedSmallInteger('http_status')->default(200);
            $table->text('message');
            $table->timestamps();
            $table->unique(['provider', 'key']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mdiag_error_messages');
        Schema::table('mdiag_scanner_access_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['expires_at', 'denied_message']);
        });
        Schema::dropIfExists('mdiag_sessions');
        Schema::dropIfExists('mdiag_users');
    }
};
