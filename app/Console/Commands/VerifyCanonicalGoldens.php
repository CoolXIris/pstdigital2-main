<?php

namespace App\Console\Commands;

use App\Services\BpsWebApiService;
use Illuminate\Console\Command;
use RuntimeException;

class VerifyCanonicalGoldens extends Command
{
    protected $signature = 'bps:verify-canonical-goldens';

    protected $description = 'Verify canonical question fixtures directly against WebAPI BPS';

    public function handle(BpsWebApiService $bps): int
    {
        $fixturePath = base_path('tests/Fixtures/bps-golden-questions.json');
        $contents = file_get_contents($fixturePath);
        if ($contents === false) {
            $this->error('Golden question fixture is unavailable.');

            return self::FAILURE;
        }
        $cases = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($cases) || $cases === []) {
            $this->error('Golden question fixture is empty or invalid.');

            return self::FAILURE;
        }

        try {
            $results = $bps->verifyCanonicalGoldens($cases);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $actual = $result['actual'];
            $this->line(sprintf(
                '%s | %s | expected var_id %d | %s | %s %s',
                $result['passed'] ? 'PASS' : 'FAIL',
                $result['question'],
                $result['expected_var_id'],
                $result['region'],
                $actual['latest_year'] ?? 'no year',
                isset($actual['value']) ? (string) $actual['value'] : 'no value'
            ));
        }

        return count(array_filter($results, fn (array $result) => ! $result['passed'])) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
