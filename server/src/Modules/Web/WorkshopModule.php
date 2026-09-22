<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
/** Заготовка информации мастерской. Для приватных реквизитов используйте LocalAuth,
 * таблицу с owner_id и проверку доступа. Публичное описание можно добавить как
 * текстовую страницу, но автоматически копировать личные данные аккаунта нельзя. */
final class WorkshopModule extends ContentModule
{
    public function key(): string { return 'workshop'; }
    public function title(): string { return 'Информация о мастерской'; }
    public function paths(): array { return ['workshop']; }
}
