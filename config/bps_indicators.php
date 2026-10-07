<?php

return [
    // Hanya var_id terverifikasi; setiap indikator utama dipetakan satu kali.
    'groups' => [
        [
            'terms' => ['miskin', 'kemiskinan'],
            'variables' => [
                ['var_id' => 608, 'title' => 'Persentase Penduduk Miskin Maret', 'terms' => ['miskin', 'kemiskinan', 'persentase'], 'excludes' => ['jumlah']],
            ],
        ],
        [
            'terms' => ['ipm', 'pembangunan', 'manusia'],
            'variables' => [
                ['var_id' => 980, 'title' => 'Indeks Pembangunan Manusia Menurut Kabupaten/Kota dan Jenis Kelamin (Menggunakan UHH Hasil Long Form SP2020)', 'terms' => ['ipm', 'indeks', 'pembangunan', 'manusia']],
            ],
        ],
        [
            'terms' => ['penduduk', 'warga', 'proyeksi'],
            'variables' => [
                [
                    'var_id' => 51,
                    'title' => 'Proyeksi Jumlah Penduduk',
                    'terms' => ['penduduk', 'warga', 'proyeksi'],
                    'excludes' => ['miskin', 'kemiskinan', 'kabupaten', 'kota', 'kecamatan', 'jenis', 'kelamin', 'laki-laki', 'perempuan', 'angkatan', 'kerja', 'ketenagakerjaan', 'penganggur', 'tpt', 'umur', 'usia', 'bekerja'],
                ],
            ],
        ],
    ],
];
