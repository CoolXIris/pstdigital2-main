<?php

namespace App\Console\Commands;

use App\Services\BpsWebApiService;
use Illuminate\Console\Command;

class DebugBpsFallback extends Command
{
    protected $signature = 'bps:debug-fallback {question : The complete question to diagnose}';

    protected $description = 'Inspect fallback topic, region, variable, period, score, and vervar selection';

    public function handle(BpsWebApiService $bps): int
    {
        $diagnosis = $bps->diagnoseQuestion((string) $this->argument('question'));
        if (isset($diagnosis['error'])) {
            $this->error($diagnosis['error']);

            return self::FAILURE;
        }

        $this->line('Topics: '.($diagnosis['topics'] === [] ? 'none' : implode(', ', $diagnosis['topics'])));
        $this->line('Regions: '.($diagnosis['regions'] === [] ? 'none' : implode(', ', $diagnosis['regions'])));
        foreach ($diagnosis['combinations'] as $combination) {
            $this->newLine();
            $this->info(ucfirst($combination['topic']).' / '.$combination['region']
                .($combination['province_only'] ? ' (province-only topic)' : ''));
            if ($combination['variables'] === []) {
                $this->warn('No configured candidate variable.');

                continue;
            }
            $rows = [];
            foreach ($combination['variables'] as $variable) {
                $rows[] = [
                    $variable['var_id'],
                    $variable['label'],
                    $variable['level'],
                    $variable['score'],
                    $variable['selected'] ? 'yes' : 'no',
                    implode(', ', $variable['years']),
                    $variable['matching_vervar'] === null
                        ? 'not found'
                        : json_encode($variable['matching_vervar'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
            $this->table(['var_id', 'Variable', 'Level', 'Score', 'Selected', 'Years', 'Matching vervar'], $rows);
        }

        return self::SUCCESS;
    }
}
