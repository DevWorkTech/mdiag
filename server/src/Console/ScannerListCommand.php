<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Console;

use DevWorkTech\MDiag\Models\ScannerAccessRule;
use Illuminate\Console\Command;

/** Показывает все сохранённые правила доступа сканеров. */
final class ScannerListCommand extends Command
{
    protected $signature = 'mdiag:scanner:list
        {provider? : Optional application profile}';

    protected $description = 'List scanner package-download access rules';

    public function handle(): int
    {
        $query = ScannerAccessRule::query()->orderBy('provider')->orderBy('serial');
        $provider = $this->argument('provider');

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', strtolower($provider));
        }

        $rows = $query->get()->map(static function (ScannerAccessRule $rule): array {
            return [
                $rule->provider,
                $rule->serial,
                $rule->downloads_allowed ? 'allow' : 'deny',
                $rule->allowed_modules === null
                    ? '*'
                    : implode(',', $rule->allowed_modules),
                optional($rule->last_seen_at)->toDateTimeString() ?? '-',
                $rule->note ?? '',
            ];
        })->all();

        $this->table(
            ['Profile', 'Serial', 'Downloads', 'Modules', 'Last seen', 'Note'],
            $rows
        );

        return self::SUCCESS;
    }
}
