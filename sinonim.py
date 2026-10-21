"""Kamus kata pencarian untuk istilah umum pada katalog BPS."""

STOP = set("""
yang di ke dari untuk dan atau pada dengan apa saja ada tolong tampilkan
sebutkan ini itu berapa bagaimana tentang sekarang terbaru terakhir saat sih dong
apakah ada apakahkah mohon minta carikan cari tunjukkan tunjuk
tersebut dengan agar sebagai berdasarkan mengenai menyebutkan
""".split())

GENERIC = set("""
rata rata rata-rata jumlah persentase persen data angka total menurut tahun nilai
kabupaten kota provinsi sumatera selatan sumsel wilayah terbaru terkini terakhir
sekarang saat ini berapa
""".split())

SYN = {
    "gaji": ("gaji", "upah"),
    "upah": ("upah", "gaji"),
    "penghasilan": ("upah", "gaji", "pendapatan"),
    "pendapatan": ("pendapatan", "upah", "gaji"),
    "pekerja": ("pekerja", "buruh", "karyawan", "pegawai"),
    "karyawan": ("buruh", "karyawan", "pegawai", "pekerja"),
    "buruh": ("buruh", "pekerja", "karyawan", "pegawai"),
    "pegawai": ("pegawai", "pekerja", "buruh", "karyawan"),
    "miskin": ("miskin", "kemiskinan"),
    "kemiskinan": ("kemiskinan", "miskin"),
    "pengangguran": ("pengangguran", "penganggur", "tpt"),
    "penganggur": ("penganggur", "pengangguran", "tpt"),
    "pengangur": ("pengangguran", "penganggur", "tpt"),
    "penganguran": ("pengangguran", "penganggur", "tpt"),
    "penduduk": ("penduduk", "populasi"),
    "populasi": ("populasi", "penduduk"),
    "pria": ("pria", "laki-laki", "lelaki", "jenis kelamin"),
    "laki": ("laki", "laki-laki", "pria", "jenis kelamin"),
    "wanita": ("wanita", "perempuan", "jenis kelamin"),
    "perempuan": ("perempuan", "wanita", "jenis kelamin"),
    "perempu": ("perempuan", "wanita", "jenis kelamin"),
    "jenis kelamin": ("jenis kelamin", "gender", "sex"),
    "pdrb": ("pdrb", "produk domestik regional bruto"),
    "inflasi": ("inflasi", "ihk", "indeks harga konsumen"),
    "ntp": ("ntp", "nilai tukar petani persen", "nilai tukar petani"),
    "sekolah": ("sekolah", "pendidikan"),
    "pendidikan": ("pendidikan", "sekolah"),
    "rumah sakit": ("rumah sakit", "rs", "kesehatan"),
    "puskesmas": ("puskesmas", "fasilitas kesehatan"),
    "posyandu": ("posyandu", "fasilitas kesehatan"),
    "klinik": ("klinik", "fasilitas kesehatan"),
}

RELATED = {
    "puskesmas": ("fasilitas kesehatan",),
    "posyandu": ("fasilitas kesehatan",),
    "klinik": ("fasilitas kesehatan",),
    "penjara": ("jumlah tindak pidana", "tindak pidana", "narapidana", "tahanan"),
    "narapidana": ("penjara", "tindak pidana", "tahanan"),
    "tahanan": ("penjara", "tindak pidana", "narapidana"),
    "kejahatan": ("tindak pidana", "kriminalitas"),
    "kriminalitas": ("tindak pidana", "kejahatan"),
}
