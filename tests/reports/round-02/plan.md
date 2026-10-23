# Putaran 2 — R9 (rencana sebelum perubahan)

## Hipotesis akar masalah

Kelima kegagalan `train` yang tercatat berada pada R9. Empat pertanyaan di luar cakupan tetap memperoleh saran tabel karena jalur fallback katalog tidak menerapkan pemeriksaan cakupan kata inti yang sudah dipakai jalur variabel. Pemeriksaan lokal menunjukkan kandidat untuk pertanyaan harga kendaraan/tiket/bitcoin hanya cocok pada kata luas seperti `harga` atau `listrik`, sedangkan kata topik lain tidak cocok. Jawaban prakiraan cuaca sudah menolak permintaan, tetapi frasanya tidak cocok dengan indikator penolakan tegas yang dinilai oleh judge.

## Perbaikan yang direncanakan

1. Terapkan batas cakupan kata inti minimal 75% pada kandidat katalog sebelum kandidat ditampilkan atau diambil datanya. Ini memakai batas yang sudah ada di jalur variabel dan memperketat, bukan melonggarkan, pemilihan kandidat.
2. Jika tidak ada kandidat yang memenuhi cakupan itu, jangan tampilkan judul yang hanya cocok pada kata luas; nyatakan bahwa data tidak ditemukan dalam cakupan yang tersedia.
3. Tegaskan di instruksi chatbot bahwa penolakan untuk data di luar cakupan harus jelas dan tidak menyertakan angka.

Tidak akan mengubah pertanyaan, kunci jawaban, judge, maupun ambang pengujian.
