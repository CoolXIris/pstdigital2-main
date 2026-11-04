<?php

namespace App\Console\Commands;

use App\Services\BpsWebApiService;
use Illuminate\Console\Command;
use RuntimeException;

class CheckCanonicalIndicators extends Command
{
    protected $signature = 'bps:check-canonical-indicators';

    protected $description = 'Check latest WebAPI BPS data for canonical indicators and all 17 regencies/cities';

    public function handle(BpsWebApiService $bps): int
    {
        try {
            $observations = $bps->checkCanonicalCoverage();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $warnings = array_values(array_filter(
            $observations,
            fn (array $observation) => in_array($observation['status'], ['stale', 'missing'], true)
        ));
        $this->info(sprintf(
            'Checked %d indicator-region observations; %d stale and %d missing.',
            count($observations),
            count(array_filter($warnings, fn (array $item) => $item['status'] === 'stale')),
            count(array_filter($warnings, fn (array $item) => $item['status'] === 'missing'))
        ));
        foreach ($warnings as $warning) {
            $this->warn(sprintf(
                '%s: %s (var_id %d), %s, latest year: %s',
                strtoupper($warning['status']),
                $warning['name'],
                $warning['var_id'],
                $warning['region'],
                $warning['latest_year'] ?? 'none'
            ));
        }

        return self::SUCCESS;
    }
}
