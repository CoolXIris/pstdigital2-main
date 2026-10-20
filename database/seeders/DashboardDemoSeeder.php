<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DashboardDemoSeeder extends Seeder
{
    /**
     * Seed clearly marked demo records for the admin dashboard.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
            $users = $this->seedUsers($userRole);

            $topics = [
                'Konsultasi Data Statistik',
                'Akses dan Permintaan Data',
                'Metodologi Survei',
                'Analisis Data',
                'Publikasi dan Tabel Statistik',
            ];
            $reviews = [
                'Penjelasan sangat jelas dan membantu pekerjaan saya.',
                'Respons petugas cepat, data yang diberikan sesuai kebutuhan.',
                'Sesi konsultasi informatif. Contoh pengolahan datanya mudah diikuti.',
                'Terbantu memahami metadata dan cara membaca tabel.',
                'Pelayanan baik, semoga jadwal konsultasi tersedia lebih banyak.',
                'Materinya lengkap dan petugas menjawab pertanyaan dengan sabar.',
                'Sangat bermanfaat untuk mendukung penelitian.',
                'Perlu tambahan contoh visualisasi, selebihnya sudah baik.',
                'Prosesnya mudah dan hasil konsultasi sesuai harapan.',
                'Penjelasan cukup baik, tindak lanjut datanya juga jelas.',
            ];
            $summaries = [
                'Meninjau kebutuhan data, definisi indikator, dan sumber tabel yang sesuai.',
                'Membahas metadata, cakupan wilayah, serta langkah permintaan data.',
                'Mengulas rancangan sampel, unit observasi, dan interpretasi hasil survei.',
                'Mendampingi pengolahan data dan pemeriksaan konsistensi hasil analisis.',
                'Menjelaskan struktur publikasi dan cara menemukan tabel statistik terkait.',
            ];
            $staff = ['Rina Puspita', 'Dimas Pratama', 'Siti Rahmawati', 'Budi Santoso'];
            $feedbackNumber = 0;

            foreach (range(1, 10) as $month) {
                $count = $month === 10 ? 7 : 5;
                $days = $month === 10 ? [2, 5, 9, 13, 16, 22, 27] : [3, 7, 12, 18, 24];

                for ($index = 0; $index < $count; $index++) {
                    $status = $month === 10
                        ? ($index < 4 ? 1 : ($index === 4 ? 9 : 0))
                        : ($index === 4 ? 9 : 1);
                    $topicIndex = ($month + $index - 1) % count($topics);
                    $userIndex = (($month - 1) * 5 + $index) % count($users);
                    $date = Carbon::create(2026, $month, $days[$index])->toDateString();
                    $timeHour = 9 + (($month + $index) % 6);
                    $startTime = sprintf('%02d:00:00', $timeHour);
                    $endTime = sprintf('%02d:00:00', $timeHour + 1);
                    $hasReview = $status === 1 && !(($month + $index) % 4 === 0);
                    $feedback = $hasReview ? $reviews[$feedbackNumber % count($reviews)] : null;
                    $rating = $hasReview ? (($feedbackNumber % 5) + 1) : null;

                    Meeting::updateOrCreate(
                        ['google_event_id' => sprintf('demo-dashboard-2026-%02d-%02d', $month, $index + 1)],
                        [
                            'name' => $topics[$topicIndex],
                            'status' => $status,
                            'user_id' => $users[$userIndex]->id,
                            'description' => 'Data dummy dashboard: pengguna membutuhkan arahan terkait '.mb_strtolower($topics[$topicIndex]).'.',
                            'tanggal' => $date,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'assigned_staff' => $status === 1 ? $staff[($month + $index) % count($staff)] : null,
                            'rating' => $rating,
                            'kritik_saran' => $feedback,
                            'ringkasan' => $status === 1 ? $summaries[$topicIndex] : null,
                            'cancellation_reason' => $status === 9 ? 'Jadwal pengguna berubah; permintaan dibatalkan.' : null,
                            'updated_at' => now(),
                        ]
                    );

                    if ($hasReview) {
                        $feedbackNumber++;
                    }
                }
            }
        });
    }

    /**
     * @return list<User>
     */
    private function seedUsers(Role $userRole): array
    {
        $names = [
            'Aditya Pranata', 'Ayu Lestari', 'Bagas Saputra', 'Citra Maharani',
            'Dewi Anggraini', 'Eko Firmansyah', 'Fitri Handayani', 'Galih Ramadhan',
            'Hana Permata', 'Indra Kurniawan', 'Jihan Amelia', 'Kevin Maulana',
            'Laila Nuraini', 'Mira Oktaviani', 'Naufal Hidayat', 'Olivia Putri',
            'Putra Wicaksono', 'Qori Azzahra', 'Rafi Setiawan', 'Salsa Aprilia',
            'Teguh Purnomo', 'Ulfa Nabila', 'Vina Safitri', 'Wahyu Nugroho',
        ];
        $jobs = [
            'Pelajar/Mahasiswa', 'Peneliti/Dosen', 'Pegawai Swasta',
            'Pegawai BUMN/BUMD', 'Wiraswasta', 'ASN/TNI/Polri',
        ];
        $provinces = ['DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'DI Yogyakarta', 'Jawa Timur', 'Banten'];
        $cities = ['Jakarta Selatan', 'Bandung', 'Semarang', 'Yogyakarta', 'Surabaya', 'Tangerang'];

        $users = [];
        foreach ($names as $index => $name) {
            $number = $index + 1;
            $user = User::updateOrCreate(
                ['email' => sprintf('demo.dashboard.%02d@pstdigital.test', $number)],
                [
                    'name' => $name,
                    'password' => Hash::make('demo-dashboard-2026'),
                    'pekerjaan' => $jobs[$index % count($jobs)],
                    'jenis_kelamin' => $index % 2 === 0 ? 1 : 2,
                    'tanggal_lahir' => Carbon::create(1985 + ($index % 17), 1 + ($index % 12), 1 + ($index % 27))->toDateString(),
                    'asal_prov' => $provinces[$index % count($provinces)],
                    'asal_kab' => $cities[$index % count($cities)],
                    'no_hp' => sprintf('08120000%04d', $number),
                    'pendidikan' => ['SMA/SMK', 'Diploma', 'S1', 'S2'][$index % 4],
                    'email_verified_at' => now(),
                ]
            );
            $user->assignRole($userRole);
            $users[] = $user;
        }

        return $users;
    }
}
