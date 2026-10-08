<?php

namespace App\Console\Commands;

use App\Services\BpsWebApiService;
use Illuminate\Console\Command;
use Throwable;

class ChatbotEvalTemplate extends Command
{
    protected $signature = 'chatbot:eval-template';

    protected $description = 'Uji jalur katalog dan template fallback dari questions.csv';

    public function handle(BpsWebApiService $bps): int
    {
        $inputPath = storage_path('app/questions.csv');
        if (! is_file($inputPath)) {
            $this->error("Salin questions.csv ke {$inputPath}");

            return self::FAILURE;
        }

        $input = fopen($inputPath, 'r');
        if ($input === false) {
            $this->error("questions.csv tidak dapat dibuka: {$inputPath}");

            return self::FAILURE;
        }

        $outputPath = storage_path('app/hasil_template.csv');
        $output = fopen($outputPath, 'w');
        if ($output === false) {
            fclose($input);
            $this->error("File hasil tidak dapat dibuat: {$outputPath}");

            return self::FAILURE;
        }

        try {
            fgetcsv($input, null, ',', '"', '');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'id', 'kategori', 'pertanyaan', 'jalur', 'detik', 'balasan',
                'relevansi', 'kesegaran', 'wilayah', 'keparahan', 'catatan',
            ], ',', '"', '');
            $failures = 0;

            while (($row = fgetcsv($input, null, ',', '"', '')) !== false) {
                if (count($row) < 3) {
                    continue;
                }

                [$id, $category, $question] = $row;
                $started = microtime(true);
                try {
                    $direct = $bps->catalogAnswerFor($question);
                    $reply = $direct ?? $bps->fallbackAnswerFor($question);
                    $route = $direct !== null ? 'katalog' : 'template';
                } catch (Throwable $exception) {
                    report($exception);
                    $failures++;
                    $reply = 'Layanan BPS tidak dapat diuji untuk pertanyaan ini.';
                    $route = 'error';
                    $this->warn("{$id} gagal diproses");
                }

                fputcsv($output, [
                    $id,
                    $category,
                    $question,
                    $route,
                    round(microtime(true) - $started, 1),
                    $reply,
                    '',
                    '',
                    '',
                    '',
                    '',
                ], ',', '"', '');
                $this->line("{$id} selesai");
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($failures > 0) {
            $this->warn("Selesai dengan {$failures} kegagalan: storage/app/hasil_template.csv");

            return self::FAILURE;
        }

        $this->info('Selesai: storage/app/hasil_template.csv');

        return self::SUCCESS;
    }
}
