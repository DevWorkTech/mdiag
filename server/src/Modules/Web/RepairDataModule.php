<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
/** URL repairdata подтверждён строками APK. CLI сохраняет HTML и ресурсы; серверный поиск требует отдельного адаптера. */
final class RepairDataModule extends ContentModule
{
    public function key(): string { return 'repairdata'; }
    public function title(): string { return 'Ремонтная информация'; }
    public function paths(): array { return ['repairdata']; }
    public function sources(): array { return ['http://repairdata.xdiagpro.com/newmain/?source=app']; }
}
