<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
/** Заготовка FAQ: впишите реальные URL в web_content.modules.faq.sources и точные пути в paths.
 * Не используйте выдуманный endpoint поставщика. Доступны текстовые снимки публичных страниц. */
final class FaqModule extends ContentModule
{
    public function key(): string { return 'faq'; }
    public function title(): string { return 'FAQ'; }
    public function paths(): array { return ['faq']; }
}
