<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class WilayahService
{
    private const BASE_URL = 'https://wilayah.id/api';

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function provinces(): array
    {
        return DB::table('wilayah_provinces')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($province) => ['code' => $province->code, 'name' => $province->name])
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function regencies(string $provinceCode): array
    {
        abort_unless(DB::table('wilayah_provinces')->where('code', $provinceCode)->exists(), 404, 'Kode provinsi tidak ditemukan.');

        return DB::table('wilayah_regencies')
            ->where('province_code', $provinceCode)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($regency) => ['code' => $regency->code, 'name' => $regency->name])
            ->all();
    }

    /**
     * @return array{
     *   provinces: array<int, array{code: string, name: string}>,
     *   regencies: array<string, array<int, array{code: string, name: string}>>
     * }
     */
    public function catalog(): array
    {
        $regencies = DB::table('wilayah_regencies')
            ->orderBy('name')
            ->get(['province_code', 'code', 'name'])
            ->groupBy('province_code')
            ->map(fn ($areas) => $areas
                ->map(fn ($area) => ['code' => $area->code, 'name' => $area->name])
                ->values()
                ->all())
            ->all();

        return [
            'provinces' => $this->provinces(),
            'regencies' => $regencies,
        ];
    }

    /**
     * Fetch the external catalog once and replace the local SQL snapshot atomically.
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function synchronize(): array
    {
        $provinces = $this->fetchAreas(self::BASE_URL.'/provinces.json');
        if ($provinces === []) {
            throw new UnexpectedValueException('API wilayah.id tidak mengembalikan data provinsi.');
        }

        $regencies = [];
        foreach ($provinces as $province) {
            if (! preg_match('/^\d{2}$/', $province['code'])) {
                throw new UnexpectedValueException('Kode provinsi dari API wilayah.id tidak valid.');
            }

            foreach ($this->fetchAreas(self::BASE_URL.'/regencies/'.$province['code'].'.json') as $regency) {
                if (! preg_match('/^\d{2}\.\d{2}$/', $regency['code'])) {
                    throw new UnexpectedValueException('Kode kabupaten/kota dari API wilayah.id tidak valid.');
                }

                $regencies[] = [
                    'code' => $regency['code'],
                    'province_code' => $province['code'],
                    'name' => $regency['name'],
                ];
            }
        }

        DB::transaction(function () use ($provinces, $regencies): void {
            DB::table('wilayah_regencies')->delete();
            DB::table('wilayah_provinces')->delete();
            DB::table('wilayah_provinces')->insert($provinces);

            foreach (array_chunk($regencies, 100) as $chunk) {
                DB::table('wilayah_regencies')->insert($chunk);
            }
        });

        return [
            'provinces' => count($provinces),
            'regencies' => count($regencies),
        ];
    }

    /**
     * @return array<int, array{code: string, name: string}>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    private function fetchAreas(string $url): array
    {
        $areas = Http::timeout(8)->get($url)->throw()->json('data');

        if (! is_array($areas)) {
            throw new UnexpectedValueException('Format data wilayah.id tidak sesuai.');
        }

        foreach ($areas as $area) {
            if (! is_array($area) || ! isset($area['code'], $area['name']) || ! is_string($area['code']) || ! is_string($area['name'])) {
                throw new UnexpectedValueException('Format data wilayah.id tidak sesuai.');
            }
        }

        return $areas;
    }
}
