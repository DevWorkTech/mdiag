<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Console;

use DevWorkTech\MDiag\Models\ScannerAccessRule;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Создаёт или изменяет правило скачивания для серийного номера сканера. */
final class ScannerAccessCommand extends Command
{
    protected $signature = 'mdiag:scanner:access
        {provider : xdiag, xpro7, xpro5, diagzone or prodiag}
        {serial : Scanner serial number}
        {mode : allow or deny}
        {--module=* : Allowed module code; repeat for a whitelist}
        {--note= : Administrative note}';

    protected $description = 'Allow or deny package downloads for a scanner';

    public function handle(): int
    {
        $provider = strtolower((string) $this->argument('provider'));
        $serial = strtoupper(trim((string) $this->argument('serial')));
        $mode = strtolower((string) $this->argument('mode'));

        if (!is_array(config("mdiag-dwt.profiles.{$provider}"))) {
            throw new InvalidArgumentException("Unknown profile: {$provider}");
        }

        if (!in_array($mode, ['allow', 'deny'], true)) {
            throw new InvalidArgumentException('Mode must be allow or deny.');
        }

        if (preg_match('/^[A-Z0-9._:-]{4,128}$/', $serial) !== 1) {
            throw new InvalidArgumentException('Invalid scanner serial number.');
        }

        $modules = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('module')
        ))));

        $rule = ScannerAccessRule::query()->updateOrCreate(
            ['provider' => $provider, 'serial' => $serial],
            [
                'downloads_allowed' => $mode === 'allow',
                'allowed_modules' => $mode === 'allow' && $modules !== []
                    ? $modules
                    : null,
                'note' => $this->option('note'),
            ],
        );

        $scope = !$rule->downloads_allowed
            ? 'all downloads denied'
            : ($rule->allowed_modules === null
                ? 'all modules allowed'
                : 'allowed modules: ' . implode(', ', $rule->allowed_modules));

        $this->components->info(
            "{$provider}/{$serial}: {$scope}"
        );

        return self::SUCCESS;
    }
}
