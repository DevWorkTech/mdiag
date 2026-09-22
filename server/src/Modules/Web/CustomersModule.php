<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
/** Заготовка Customer Management. Реализуйте локальные таблицы с owner_id и CRUD по
 * фактическому протоколу APK. Данные клиентов нельзя публиковать как общий снимок.
 * Импорт из аккаунта поставщика требует отдельного авторизованного адаптера.
 * Пока возвращается честная ошибка unsupported, а не фиктивный success. */
final class CustomersModule extends ContentModule
{
    public function key(): string { return 'customers'; }
    public function title(): string { return 'Управление клиентами'; }
    public function paths(): array { return ['customers']; }
}
