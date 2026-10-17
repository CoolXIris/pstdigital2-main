<?php

namespace App\Console\Commands;

use App\Services\WilayahService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

class SyncWilayahCatalog extends Command
{
    protected $signature = 'wilayah:sync';

    protected $description = 'Ambil katalog wilayah.id dan simpan ke database SQL.';

    public function handle(WilayahService $wilayah): int
    {
        if ($wilayah->provinces() !== []) {
            $this->info('Katalog wilayah sudah ada di database; pengambilan API dilewati.');

            return self::SUCCESS;
        }

        try {
            $counts = $wilayah->synchronize();
        } catch (ConnectionException|RequestException|UnexpectedValueException $exception) {
            $this->error('Katalog wilayah gagal disinkronkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Katalog wilayah tersimpan: {$counts['provinces']} provinsi dan {$counts['regencies']} kabupaten/kota.");

        return self::SUCCESS;
    }
}
