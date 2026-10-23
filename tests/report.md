# Laporan Pengujian Chatbot BPS

## Ringkasan siklus perbaikan

Perbaikan dijalankan pada branch `autofix/2026-10-22` dan dibiarkan lokal, tanpa commit, sesuai arahan untuk meninjau perubahan sebelum diusulkan.

| Tahap | Train | Holdout | Gemini | WebAPI BPS | Catatan |
|---|---:|---:|---:|---:|---|
| Baseline terkoreksi | 44.44% (4/9) | 40.00% (2/5) | 7 | 0 | Baseline pembanding setelah penyesuaian pencatatan R9 |
| Putaran 1, pengukuran pertama | 75.00% (6/8) | 0.00% (0/4) | 7 | 0 | Pengukuran bervariasi pada pengulangan model |
| Putaran 1, pengulangan | 66.67% (10/15) | 50.00% (2/4) | 15 | 0 | R10 lulus seluruh tes train yang diuji |
| Putaran 2, hasil akhir | 100.00% (12/12) | 80.00% (4/5) | 12 | 0 | Tidak ada kegagalan train; satu kegagalan holdout tetap agregat |

Arsip baseline awal `reports/round-00` memuat pengukuran sebelum penyesuaian judge (54.55% train, 33.33% holdout; 8 panggilan Gemini). Pengujian memakai respons Gemini sehingga skor dan jumlah soal yang dapat dinilai berubah antar-run. Putaran 1 dan 2 masing-masing memperbaiki satu kelompok penyebab:

1. **R10 — pertanyaan umum masuk pencarian data:** klasifikasi konsep “apa yang dimaksud”, ucapan terima kasih yang diperluas, dan permintaan saran/panduan kini ditangani sebelum pencarian katalog.
2. **R9 — kandidat katalog tidak relevan:** kandidat katalog harus mencakup minimal 75% kata inti, konsisten dengan batas yang sudah dipakai untuk kandidat variabel. Jika tidak ada kandidat yang memadai, chatbot menyampaikan bahwa data tidak ditemukan tanpa menyebut kandidat acak atau angka.

`python -m unittest discover -s tests -p "test_chat_routing.py" -q`: **77 tes lulus**. Pada hasil akhir, train mencapai 100%, tetapi holdout 80% belum mencapai target 90%. Rincian pertanyaan holdout tidak dianalisis; 92 kasus holdout dan 248 kasus train berstatus `SKIP` karena tabel DB `log_gagal` belum tersedia. Migrasi tidak dijalankan.

Tidak ada kegagalan train tersisa untuk menentukan kelompok perbaikan berikutnya. Kegagalan holdout yang tersisa hanya dicatat sebagai agregat sesuai aturan, jadi siklus dihentikan tanpa menebak penyebab atau mengubah tes/penilai. Total penggunaan seluruh pengukuran yang tercatat: **49 panggilan Gemini dan 0 panggilan WebAPI BPS**, di bawah batas 300 per layanan.

- Panggilan Gemini: 12/300
- Panggilan WebAPI BPS: 0/300
- Penilaian kualitas LLM: tidak dijalankan; hasil status hanya berdasarkan aturan deterministik.

## Train

- Skor: 100.0%
- PASS: 12; FAIL: 0; SKIP: 248

### Per kategori

| Kategori | PASS | FAIL | Total |
|---|---:|---:|---:|
| di_luar_data | 6 | 0 | 6 |
| pertanyaan_umum | 6 | 0 | 6 |

### Per jenis tabel

| Jenis | PASS | FAIL | Total |
|---|---:|---:|---:|
| general | 6 | 0 | 6 |
| no_data | 6 | 0 | 6 |

### Per aturan

| Aturan | PASS | FAIL | Total |
|---|---:|---:|---:|
| R10 | 6 | 0 | 6 |
| R5 | 12 | 0 | 12 |
| R6 | 12 | 0 | 12 |
| R8 | 12 | 0 | 12 |
| R9 | 6 | 0 | 6 |

## Holdout

- Skor: 80.0%
- PASS: 4; FAIL: 1; SKIP: 92

### Per kategori

| Kategori | PASS | FAIL | Total |
|---|---:|---:|---:|
| di_luar_data | 1 | 0 | 1 |
| pertanyaan_umum | 3 | 1 | 4 |

### Per jenis tabel

| Jenis | PASS | FAIL | Total |
|---|---:|---:|---:|
| general | 3 | 1 | 4 |
| no_data | 1 | 0 | 1 |

### Per aturan

| Aturan | PASS | FAIL | Total |
|---|---:|---:|---:|
| R10 | 3 | 1 | 4 |
| R5 | 5 | 0 | 5 |
| R6 | 5 | 0 | 5 |
| R8 | 5 | 0 | 5 |
| R9 | 1 | 0 | 1 |

## Kegagalan train/regression

Tidak ada kegagalan train/regression yang tercatat.
## data_issue

- csv-01-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan pada tahun 2025?
- csv-01-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di wilayah Sumatera Selatan tahun 2025.
- csv-01-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan dan Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2025?
- csv-01-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-01-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan tahun 2025?
- csv-01-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan.
- csv-01-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan pada 2025?
- csv-01-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-01-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan tahun 2025?
- csv-01-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa (Metode Baru) Aspek Indeks Demokrasi Indonesia (IDI) Sumatera Selatan di Sumatera Selatan tahun 2035?
- csv-02-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada tahun 2017?
- csv-02-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di wilayah Sumatera Selatan tahun 2017.
- csv-02-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) dan Jumlah Pelanggan Listrik di Sumatera Selatan tahun 2017?
- csv-02-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-02-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2017?
- csv-02-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan.
- csv-02-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada 2017?
- csv-02-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) yang tersedia di Sumatera Selatan pada tahun 2017?
- csv-02-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2017?
- csv-02-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Jemaah Haji yang Diberangkatkan  ke Tanah Suci  Mekah Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2035?
- csv-03-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pelanggan Listrik di Sumatera Selatan pada tahun 2024?
- csv-03-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Pelanggan Listrik di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2024.
- csv-03-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pelanggan Listrik dan Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan tahun 2024?
- csv-03-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Pelanggan Listrik di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-03-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Pelanggan Listrik di Sumatera Selatan tahun 2024?
- csv-03-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Pelanggan Listrik di Sumatera Selatan.
- csv-03-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Pelanggan Listrik di Sumatera Selatan pada 2024?
- csv-03-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Pelanggan Listrik yang tersedia di Sumatera Selatan pada tahun 2024?
- csv-03-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Pelanggan Listrik di Sumatera Selatan tahun 2024?
- csv-03-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pelanggan Listrik di Sumatera Selatan tahun 2035?
- csv-04-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan pada tahun 2025?
- csv-04-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2025.
- csv-04-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota dan Inflasi di Sumatera Selatan tahun 2025?
- csv-04-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-04-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan tahun 2025?
- csv-04-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan.
- csv-04-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan pada 2025?
- csv-04-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-04-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan tahun 2025?
- csv-04-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase penduduk usia 25 Tahun keatas dengan pendidikan SMA ke atas menurut jenis kelamin dan kabupaten/kota di Sumatera Selatan tahun 2035?
- csv-05-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Inflasi di Kota Palembang pada tahun 2026?
- csv-05-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Inflasi di Kota Palembang tahun 2026.
- csv-05-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Inflasi dan Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Kota Palembang tahun 2026?
- csv-05-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Inflasi di Kota Palembang pada rentang tahun yang tersedia.
- csv-05-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Inflasi di Kota Palembang tahun 2026?
- csv-05-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Inflasi di Kota Palembang.
- csv-05-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Inflasi di Kota Palembang pada 2026?
- csv-05-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Inflasi yang tersedia di Kota Palembang pada tahun 2026?
- csv-05-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Inflasi di Kota Palembang tahun 2026?
- csv-05-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Inflasi di Kota Palembang tahun 2035?
- csv-06-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada tahun 2022?
- csv-06-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di wilayah Sumatera Selatan tahun 2022.
- csv-06-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan dan Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan tahun 2022?
- csv-06-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-06-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-06-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan.
- csv-06-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada 2022?
- csv-06-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan yang tersedia di Sumatera Selatan pada tahun 2022?
- csv-06-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-06-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kejahatan yang Dilaporkan, Risiko Penduduk Terkena Kejahatan per 100.000 Penduduk, Persentase Penyelesaian Kejahatan, dan Selang Waktu Terjadinya Kejahatan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2035?
- csv-07-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan pada tahun 2024?
- csv-07-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2024.
- csv-07-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) dan Indeks Pembangunan Manusia (IPM) Menurut Jenis Kelamin (Tahun) di Sumatera Selatan tahun 2024?
- csv-07-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-07-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan tahun 2024?
- csv-07-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan.
- csv-07-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan pada 2024?
- csv-07-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) yang tersedia di Sumatera Selatan pada tahun 2024?
- csv-07-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan tahun 2024?
- csv-07-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Persentase Penduduk Usia 5 tahun ke atas yang Pernah Mengakses Internet dalam 3 Bulan Terakhir Menurut Kabupaten/Kota dan Jenis Kelamin provinsi Sumatera Selatan (Persen) di Sumatera Selatan tahun 2035?
- csv-09-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada tahun 2026?
- csv-09-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2026.
- csv-09-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) dan Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2026?
- csv-09-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-09-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2026?
- csv-09-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan.
- csv-09-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan pada 2026?
- csv-09-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) yang tersedia di Sumatera Selatan pada tahun 2026?
- csv-09-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2026?
- csv-09-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Penduduk Menurut Kabupaten/Kota (Jiwa) di Sumatera Selatan tahun 2035?
- csv-10-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan pada tahun 2022?
- csv-10-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di wilayah Sumatera Selatan tahun 2022.
- csv-10-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan dan Realisasi Belanja Pemerintah (Ribu Rupiah) di Sumatera Selatan tahun 2022?
- csv-10-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-10-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-10-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan.
- csv-10-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan pada 2022?
- csv-10-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan yang tersedia di Sumatera Selatan pada tahun 2022?
- csv-10-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-10-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Penduduk yang Mempunyai Keluhan Kesehatan Selama Sebulan Terakhir dan Tidak Berobat Jalan Menurut Kabupaten/Kota dan Alasan Utama Tidak Berobat Jalan di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2035?
- csv-11-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah pada tahun 2025?
- csv-11-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Realisasi Belanja Pemerintah (Ribu Rupiah) di 1.1 Belanja Pegawai dan 2.1 Belanja Pegawai tahun 2025.
- csv-11-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Realisasi Belanja Pemerintah (Ribu Rupiah) dan Jumlah Penduduk Miskin Maret (Ribu Jiwa) di Jumlah tahun 2025?
- csv-11-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah pada rentang tahun yang tersedia.
- csv-11-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah tahun 2025?
- csv-11-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah.
- csv-11-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah pada 2025?
- csv-11-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Realisasi Belanja Pemerintah (Ribu Rupiah) yang tersedia di Jumlah pada tahun 2025?
- csv-11-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah tahun 2025?
- csv-11-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Realisasi Belanja Pemerintah (Ribu Rupiah) di Jumlah tahun 2035?
- csv-14-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan pada tahun 2025?
- csv-14-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Pulau Menurut Kabupaten/Kota di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2025.
- csv-14-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pulau Menurut Kabupaten/Kota dan Jumlah Desa/Kelurahan yang Memiliki Kantor Pos Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2025?
- csv-14-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-14-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan tahun 2025?
- csv-14-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan.
- csv-14-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan pada 2025?
- csv-14-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Pulau Menurut Kabupaten/Kota yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-14-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan tahun 2025?
- csv-14-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Pulau Menurut Kabupaten/Kota di Sumatera Selatan tahun 2035?
- csv-17-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan pada tahun 2022?
- csv-17-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di wilayah Sumatera Selatan tahun 2022.
- csv-17-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang dan Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-17-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-17-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan tahun 2022?
- csv-17-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan.
- csv-17-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan pada 2022?
- csv-17-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang yang tersedia di Sumatera Selatan pada tahun 2022?
- csv-17-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan tahun 2022?
- csv-17-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Akomodasi, Kamar, dan Tempat Tidur yang Tersedia pada Hotel Bintang di Sumatera Selatan tahun 2035?
- csv-18-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan pada tahun 2023?
- csv-18-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di wilayah Sumatera Selatan tahun 2023.
- csv-18-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan dan Angka Partisipasi Sekolah (Persen) di Sumatera Selatan tahun 2023?
- csv-18-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-18-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2023?
- csv-18-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan.
- csv-18-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan pada 2023?
- csv-18-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan yang tersedia di Sumatera Selatan pada tahun 2023?
- csv-18-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2023?
- csv-18-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Distribusi Persentase Rumah Tangga Menurut Kabupaten/Kota dan Bahan Bakar Utama untuk Memasak di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2035?
- csv-19-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Angka Partisipasi Sekolah (Persen) di Sumatera Selatan pada tahun 2025?
- csv-19-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Angka Partisipasi Sekolah (Persen) di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2025.
- csv-19-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Angka Partisipasi Sekolah (Persen) dan Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan tahun 2025?
- csv-19-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Angka Partisipasi Sekolah (Persen) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-19-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Angka Partisipasi Sekolah (Persen) di Sumatera Selatan tahun 2025?
- csv-19-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Angka Partisipasi Sekolah (Persen) di Sumatera Selatan.
- csv-19-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Angka Partisipasi Sekolah (Persen) di Sumatera Selatan pada 2025?
- csv-19-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Angka Partisipasi Sekolah (Persen) yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-19-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Angka Partisipasi Sekolah (Persen) di Sumatera Selatan tahun 2025?
- csv-19-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Angka Partisipasi Sekolah (Persen) di Sumatera Selatan tahun 2035?
- csv-20-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan pada tahun 2020?
- csv-20-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di wilayah Sumatera Selatan tahun 2020.
- csv-20-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) dan Neraca Perdagangan (000 US $) di Sumatera Selatan tahun 2020?
- csv-20-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-20-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan tahun 2020?
- csv-20-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan.
- csv-20-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan pada 2020?
- csv-20-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) yang tersedia di Sumatera Selatan pada tahun 2020?
- csv-20-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan tahun 2020?
- csv-20-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Posisi Simpanan Berjangka Rupiah dan Valuta Asing Bank Umum dan BPR (Juta Rupiah) di Sumatera Selatan tahun 2035?
- csv-22-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada tahun 2022?
- csv-22-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di wilayah Sumatera Selatan tahun 2022.
- csv-22-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan dan Indeks Kemalahan Konstruksi di Sumatera Selatan tahun 2022?
- csv-22-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-22-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-22-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan.
- csv-22-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan pada 2022?
- csv-22-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan yang tersedia di Sumatera Selatan pada tahun 2022?
- csv-22-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2022?
- csv-22-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Keluarga Penerima Manfaat (KPM) dan Anggaran Bantuan Sosial Pangan Menurut Kabupaten/Kota di Provinsi Sumatera Selatan di Sumatera Selatan tahun 2035?
- csv-23-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Indeks Kemalahan Konstruksi di Sumatera Selatan pada tahun 2018?
- csv-23-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Indeks Kemalahan Konstruksi di wilayah Sumatera Selatan tahun 2018.
- csv-23-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Indeks Kemalahan Konstruksi dan Produksi Daging Ternak (kg) di Sumatera Selatan tahun 2018?
- csv-23-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Indeks Kemalahan Konstruksi di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-23-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Indeks Kemalahan Konstruksi di Sumatera Selatan tahun 2018?
- csv-23-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Indeks Kemalahan Konstruksi di Sumatera Selatan.
- csv-23-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Indeks Kemalahan Konstruksi di Sumatera Selatan pada 2018?
- csv-23-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Indeks Kemalahan Konstruksi yang tersedia di Sumatera Selatan pada tahun 2018?
- csv-23-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Indeks Kemalahan Konstruksi di Sumatera Selatan tahun 2018?
- csv-23-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Indeks Kemalahan Konstruksi di Sumatera Selatan tahun 2035?
- csv-24-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Produksi Daging Ternak (kg) di Sumatera Selatan pada tahun 2025?
- csv-24-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Produksi Daging Ternak (kg) di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2025.
- csv-24-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Produksi Daging Ternak (kg) dan IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan tahun 2025?
- csv-24-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Produksi Daging Ternak (kg) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-24-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Produksi Daging Ternak (kg) di Sumatera Selatan tahun 2025?
- csv-24-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Produksi Daging Ternak (kg) di Sumatera Selatan.
- csv-24-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Produksi Daging Ternak (kg) di Sumatera Selatan pada 2025?
- csv-24-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Produksi Daging Ternak (kg) yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-24-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Produksi Daging Ternak (kg) di Sumatera Selatan tahun 2025?
- csv-24-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Produksi Daging Ternak (kg) di Sumatera Selatan tahun 2035?
- csv-25-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan pada tahun 2025?
- csv-25-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan IMB Aneka Investasi (Juta Rupiah) di wilayah Sumatera Selatan tahun 2025.
- csv-25-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa IMB Aneka Investasi (Juta Rupiah) dan Indeks Tendensi Konsumen di Sumatera Selatan tahun 2025?
- csv-25-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-25-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan tahun 2025?
- csv-25-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan.
- csv-25-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan pada 2025?
- csv-25-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data IMB Aneka Investasi (Juta Rupiah) yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-25-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan tahun 2025?
- csv-25-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa IMB Aneka Investasi (Juta Rupiah) di Sumatera Selatan tahun 2035?
- csv-27-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan pada tahun 2025?
- csv-27-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Jumlah Kecamatan dan Desa/Kelurahan di Ogan Komering Ilir dan Ogan Komering Ulu tahun 2025.
- csv-27-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kecamatan dan Desa/Kelurahan dan Tingkat Pengangguran (Persen) di Sumatera Selatan tahun 2025?
- csv-27-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan pada rentang tahun yang tersedia.
- csv-27-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan tahun 2025?
- csv-27-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan.
- csv-27-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan pada 2025?
- csv-27-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Jumlah Kecamatan dan Desa/Kelurahan yang tersedia di Sumatera Selatan pada tahun 2025?
- csv-27-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan tahun 2025?
- csv-27-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Jumlah Kecamatan dan Desa/Kelurahan di Sumatera Selatan tahun 2035?
- csv-28-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Tingkat Pengangguran (Persen) di Perempuan pada tahun 2025?
- csv-28-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan Tingkat Pengangguran (Persen) di Jumlah dan Laki-Laki tahun 2025.
- csv-28-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Tingkat Pengangguran (Persen) dan Barang Angkutan Udara Domestik dan International (Ton) di Perempuan tahun 2025?
- csv-28-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan Tingkat Pengangguran (Persen) di Perempuan pada rentang tahun yang tersedia.
- csv-28-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada berapa Tingkat Pengangguran (Persen) di Perempuan tahun 2025?
- csv-28-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong cek data terbaru untuk Tingkat Pengangguran (Persen) di Perempuan.
- csv-28-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total jumlah Tingkat Pengangguran (Persen) di Perempuan pada 2025?
- csv-28-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bagaimana data Tingkat Pengangguran (Persen) yang tersedia di Perempuan pada tahun 2025?
- csv-28-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Brp Tingkat Pengangguran (Persen) di Perempuan tahun 2025?
- csv-28-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa Tingkat Pengangguran (Persen) di Perempuan tahun 2035?
- type-tabel-statis-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Banyaknya Pelanggan, Daya Tersambung dan Energi Listrik Terjual Menurut Jenis Pelanggan di Provinsi Sumatera Selatan, 2022.
- type-tabel-statis-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Jumlah Ternak yang Dipotong di Provinsi Sumatera Selatan menurut Jenis Hewan Ternak dan Status Pencatatan, 2018-2022.
- type-tabel-statis-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Laju Implisit Kabupaten/Kota di Provinsi Sumatera Selatan Menurut Komponen Pengeluaran (Persen), 2022.
- type-tabel-statis-04: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Banyaknya Desa/Kelurahan Menurut Keberadaan dan Jenis Industri Kecil dan Mikro tahun 2021.
- type-tabel-statis-05: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Desa kelurahan menurut pengguna listrik dan sumber penerangan jalan desa.
- type-tabel-statis-06: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Pengamatan Unsur Iklim di Stasiun Pengamatan Badan Meteorologi Klimatologi dan Geofisika (BMKG), 2020–2022.
- type-tabel-statis-07: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Rata-Rata Harga Komoditas Strategis Kabupaten Kota Agust-Sept 2022 (Merk).
- type-tabel-statis-08: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan PDRB Atas Dasar Harga Berlaku Tanpa Minyak dan Gas Menurut Kabupaten Kota se-Sumatera Selatan (juta rupiah), 2018-2022.
- type-tabel-statis-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Ibukota Kabupaten/Kota di Provinsi Sumatera Selatan, 2022 untuk Sumatera Selatan tahun 2024.
- type-tabel-statis-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong tampilkan Banyaknya desa/kelurahan menurut keberadaan pos polisi dan kemudahan akses ke pos polisi terdekat, 2021 untuk Sumatera Selatan tahun 2025.
- special-01: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: BRS terbaru tentang inflasi Sumatera Selatan
- special-02: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan publikasi BPS Sumatera Selatan terbaru
- special-03: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berita statistik terbaru di Sumatera Selatan
- special-09: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Ada BRS terbaru BPS tentang Aktivitas Politik dan Komunitas Lainnya?
- special-10: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong carikan publikasi BPS mengenai Budaya.
- special-13: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong carikan publikasi BPS mengenai Harga-Harga.
- special-14: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan berita statistik terbaru terkait Hukum dan Kriminal.
- special-16: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong carikan publikasi BPS mengenai Indikator Millenium Development Goals (MDGs).
- special-17: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan berita statistik terbaru terkait Kependudukan dan Migrasi.
- special-19: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong carikan publikasi BPS mengenai Keuangan Pemerintah, Fiskal dan Statistik Sektor Publik.
- special-20: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan berita statistik terbaru terkait Kondisi Tempat Tinggal, Kemiskinan, dan Permasalahan Sosial Lintas Sektor.
- special-22: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tolong carikan publikasi BPS mengenai Lingkungan.
- special-23: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Tampilkan berita statistik terbaru terkait Masyarakat Informasi.
- special-31: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Tampilkan data penduduk DKI Jakarta tahun 2025.
- special-32: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Berapa harga tiket pesawat dari Palembang ke Jakarta?
- special-35: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Berapa harga bitcoin di Muara Enim?
- special-36: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Apa yang dimaksud statistik resmi?
- special-37: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Bagaimana cara membaca tabel statistik?
- special-40: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Terima kasih atas bantuannya.
- special-41: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Halo, selamat pagi.
- special-42: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Bisa jelaskan cara memilih indikator statistik?
- special-44: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Bagaimana cara mengutip sumber statistik dengan benar?
- special-45: Permintaan layanan gagal dengan HTTP 429. Pertanyaan: Tolong beri saran menyusun pertanyaan survei.
- reg-guru-sd-pali-lahat-2025: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa jumlah guru SD di Pali dan Lahat tahun 2025?
- reg-musi-rawas-vs-utara: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Bandingkan jumlah guru sekolah dasar di Kabupaten Musi Rawas dan Kabupaten Musi Rawas Utara tahun 2025. Jangan tertukar wilayahnya.
- reg-total-guru-sd-sumsel: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa total guru SD di Sumatera Selatan tahun 2025?
- reg-rata-rata-gaji-sumsel: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Berapa rata-rata gaji di Sumatera Selatan?
- reg-konsumsi-kalori-2025-terakhir-2022: Tabel penyimpanan BPS belum tersedia: log_gagal. Pertanyaan: Konsumsi kalori Sumatera Selatan tahun 2025 berapa?
- Lingkungan: Tabel penyimpanan BPS belum tersedia: log_gagal.
