<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
/** URL repairdata подтверждён строками APK. Синхронизация сохраняет текст, не серверный поиск/JavaScript. */
final class RepairDataModule extends ContentModule
{
    public function key(): string { return 'repairdata'; }
    public function title(): string { return 'Ремонтная информация'; }
    public function paths(): array { return ['repairdata/newmain']; }
    public function sources(): array { return ['http://repairdata.xdiagpro.com/newmain/?source=app']; }
}
