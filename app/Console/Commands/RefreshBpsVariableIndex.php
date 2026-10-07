<?php

namespace App\Console\Commands;

use App\Services\BpsWebApiService;
use Illuminate\Console\Command;

class RefreshBpsVariableIndex extends Command
{
    protected $signature = 'bps:refresh-variable-index';

    protected $description = 'Refresh the BPS variable index for South Sumatra domain 1600';

    public function handle(BpsWebApiService $bps): int
    {
        $summary = $bps->refreshVariableIndex();
        $this->info('Indexed '.$summary['count'].' variables from WebAPI BPS domain 1600.');

        foreach ($summary['vertical_evidence'] as $vertical => $evidenceByLevel) {
            $levels = array_keys($evidenceByLevel);
            $inferred = $summary['vertical_levels'][$vertical] ?? ($levels === [] ? 'unknown' : 'ambiguous');
            $evidence = [];
            foreach ($evidenceByLevel as $level => $subjects) {
                $evidence[] = $level.' in '.implode(', ', $subjects);
            }
            $evidence = $evidence === [] ? 'no explicit level title' : implode('; ', $evidence);
            $count = $summary['vertical_counts'][$vertical] ?? 0;
            $this->line("vertical {$vertical} ({$count} variables): {$evidence} (inferred: {$inferred})");
        }

        return self::SUCCESS;
    }
}
