<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;

/**
 * Контракт расширения: раздел интерфейса не считается реализованным только от замены URL.
 * sources — проверенные публичные страницы для текстового офлайн-снимка.
 * paths — локальные префиксы; для API с личными данными создайте отдельный handler,
 * проверяйте LocalAuth и владельца записи перед чтением/изменением. HTTP proxy запрещён.
 */
abstract class ContentModule
{
    abstract public function key(): string;
    abstract public function title(): string;
    public function paths(): array { return []; }
    public function sources(): array { return []; }
}
