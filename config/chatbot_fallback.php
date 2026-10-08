<?php

return [
    'notice' => 'Mohon maaf, layanan asisten AI sedang tidak tersedia. Sebagai gantinya, kami menampilkan informasi terbaru dari WebAPI BPS secara otomatis.',
    'topic_intro' => 'Berita Resmi Statistik terbaru tentang {topic}:',
    'regional_brs_intro' => 'Berita Resmi Statistik dari BPS {region} tentang {topic}:',
    'regional_province_brs_intro' => 'Berita Resmi Statistik Provinsi Sumatera Selatan yang memuat {region} terkait {topic}:',
    'general_intro' => 'Pertanyaan Anda tidak cocok dengan topik template, sehingga yang ditampilkan adalah Berita Resmi Statistik terbaru secara umum:',
    'closing' => 'Silakan coba lagi beberapa saat lagi untuk mendapat jawaban dari asisten AI, atau kunjungi https://sumsel.bps.go.id.',
    'region_note' => 'Catatan: data berikut berlaku untuk tingkat Provinsi Sumatera Selatan, bukan untuk {region}.',
    'topics' => [
        'inflasi' => [
            'label' => 'inflasi',
            'keywords' => ['inflasi', 'ihk'],
            'title_keywords' => ['inflasi'],
            'province_only' => false,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 976, 'label' => 'Inflasi year-to-year', 'keywords' => ['tahunan', 'year-to-year', 'yoy']],
                ['var_id' => 978, 'label' => 'Inflasi month-to-month', 'keywords' => ['bulanan', 'month-to-month', 'mom']],
            ],
        ],
        'ntp' => [
            'label' => 'Nilai Tukar Petani (NTP)',
            'keywords' => ['ntp', 'nilai tukar petani', 'petani'],
            'title_keywords' => ['NTP', 'nilai tukar petani'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 645, 'label' => 'Perkembangan Nilai Tukar Petani', 'keywords' => ['ntp', 'nilai tukar petani']],
                ['var_id' => 121, 'label' => 'Perkembangan Nilai Tukar Petani menurut sektor', 'keywords' => ['sektor']],
            ],
        ],
        'perdagangan' => [
            'label' => 'ekspor dan impor',
            'keywords' => ['ekspor', 'impor', 'neraca perdagangan', 'perdagangan'],
            'title_keywords' => ['ekspor', 'impor', 'neraca perdagangan'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 895, 'label' => 'Volume Ekspor Bulanan', 'keywords' => ['volume']],
                ['var_id' => 896, 'label' => 'Nilai Ekspor Bulanan', 'keywords' => ['nilai']],
            ],
        ],
        'pariwisata' => [
            'label' => 'pariwisata dan perhotelan',
            'keywords' => ['wisman', 'wisatawan', 'wisata', 'pariwisata', 'hotel', 'tpk'],
            'title_keywords' => ['wisman', 'wisnus', 'hotel', 'TPK'],
            'province_only' => false,
            'province_only_keywords' => ['wisman', 'mancanegara'],
            'variables' => [
                ['var_id' => 984, 'label' => 'Perjalanan Wisatawan Nusantara menurut kabupaten/kota asal', 'keywords' => ['asal', 'berangkat']],
                ['var_id' => 986, 'label' => 'Perjalanan Wisatawan Nusantara menurut kabupaten/kota tujuan', 'keywords' => ['tujuan', 'datang']],
            ],
            'province_variables' => [
                ['var_id' => 876, 'label' => 'Kunjungan Wisatawan Mancanegara melalui Bandara Sultan Mahmud Badaruddin II', 'keywords' => ['wisman', 'mancanegara']],
            ],
        ],
        'transportasi' => [
            'label' => 'transportasi',
            'keywords' => ['penumpang', 'transportasi', 'bandara', 'pelabuhan', 'kereta'],
            'title_keywords' => ['penumpang', 'transportasi'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 672, 'label' => 'Penumpang angkutan laut yang datang dan berangkat', 'keywords' => ['laut', 'pelabuhan']],
                ['var_id' => 824, 'label' => 'Penumpang angkutan udara yang datang dan berangkat', 'keywords' => ['udara', 'bandara']],
            ],
        ],
        'ketenagakerjaan' => [
            'label' => 'ketenagakerjaan',
            'keywords' => ['penganggur', 'tpt', 'ketenagakerjaan', 'tenaga kerja', 'angkatan kerja'],
            'title_keywords' => ['ketenagakerjaan', 'penganggur', 'TPT'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 334, 'label' => 'Tingkat Pengangguran', 'keywords' => ['penganggur', 'tpt']],
            ],
        ],
        'gini' => [
            'label' => 'Rasio Gini',
            'keywords' => ['gini', 'rasio gini', 'ketimpangan'],
            'title_keywords' => ['Gini Ratio', 'Rasio Gini'],
            'brs_only' => true,
            'province_only' => true,
            'variables' => [],
            'province_variables' => [],
        ],
        'kemiskinan' => [
            'label' => 'kemiskinan',
            'keywords' => ['miskin', 'kemiskinan'],
            'title_keywords' => ['kemiskinan', 'penduduk miskin'],
            'province_only' => false,
            'variables' => [
                ['var_id' => 604, 'label' => 'Persentase Penduduk Miskin menurut Kabupaten/Kota', 'keywords' => ['persentase', 'persen']],
                ['var_id' => 679, 'label' => 'Garis Kemiskinan menurut Kabupaten/Kota', 'keywords' => ['garis', 'rupiah']],
                ['var_id' => 683, 'label' => 'Jumlah Penduduk Miskin menurut Kabupaten/Kota', 'keywords' => ['jumlah', 'orang']],
            ],
            'province_variables' => [
                ['var_id' => 608, 'label' => 'Persentase Penduduk Miskin Maret', 'keywords' => ['persentase', 'persen']],
                ['var_id' => 173, 'label' => 'Garis Kemiskinan Maret', 'keywords' => ['garis', 'rupiah']],
                ['var_id' => 157, 'label' => 'Jumlah Penduduk Miskin Maret', 'keywords' => ['jumlah', 'orang']],
            ],
        ],
        'ekonomi' => [
            'label' => 'pertumbuhan ekonomi',
            'keywords' => ['pertumbuhan ekonomi', 'laju ekonomi', 'pdrb', 'ekonomi'],
            'title_keywords' => ['pertumbuhan ekonomi', 'PDRB'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 821, 'label' => 'Laju Pertumbuhan PDRB Tahunan', 'keywords' => ['tahunan', 'pertumbuhan']],
                ['var_id' => 868, 'label' => 'Laju Pertumbuhan PDRB Triwulanan', 'keywords' => ['triwulan', 'pertumbuhan']],
                ['var_id' => 818, 'label' => 'PDRB Tahunan atas Dasar Harga Berlaku', 'keywords' => ['adhb', 'berlaku']],
                ['var_id' => 819, 'label' => 'PDRB Tahunan atas Dasar Harga Konstan', 'keywords' => ['adhk', 'konstan']],
            ],
        ],
        'pertanian' => [
            'label' => 'pertanian',
            'keywords' => ['padi', 'beras', 'pertanian'],
            'title_keywords' => ['padi', 'beras'],
            'province_only' => true,
            'variables' => [],
            'province_variables' => [
                ['var_id' => 782, 'label' => 'Produktivitas Padi', 'keywords' => ['produktivitas']],
                ['var_id' => 783, 'label' => 'Produksi Padi', 'keywords' => ['produksi']],
                ['var_id' => 784, 'label' => 'Luas Panen Padi', 'keywords' => ['luas', 'panen']],
            ],
        ],
        'ipm' => [
            'label' => 'Indeks Pembangunan Manusia (IPM)',
            'keywords' => ['ipm', 'pembangunan manusia'],
            'title_keywords' => ['IPM', 'Indeks Pembangunan Manusia'],
            'province_only' => false,
            'variables' => [
                ['var_id' => 980, 'label' => 'Indeks Pembangunan Manusia menurut Kabupaten/Kota', 'keywords' => ['ipm', 'indeks']],
            ],
            'province_variables' => [],
        ],
    ],
];
