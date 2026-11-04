"""Kamus kata pencarian untuk istilah umum pada katalog BPS."""

STOP = set("""
yang di ke dari untuk dan atau pada dengan apa saja ada tolong tampilkan
sebutkan ini itu berapa bagaimana tentang sekarang terbaru terakhir saat sih dong
apakah ada apakahkah mohon minta carikan cari tunjukkan tunjuk
tersebut dengan agar sebagai berdasarkan mengenai menyebutkan
menurut mereka
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
    "pns": ("pns", "pegawai negeri sipil", "asn", "aparatur sipil negara"),
    "pegawai negeri sipil": ("pegawai negeri sipil", "pns", "asn", "aparatur sipil negara"),
    "asn": ("asn", "aparatur sipil negara", "pns", "pegawai negeri sipil"),
    "aparatur sipil negara": ("aparatur sipil negara", "asn", "pns", "pegawai negeri sipil"),
    "tpt": ("tpt", "tingkat pengangguran terbuka"),
    "tingkat pengangguran terbuka": ("tingkat pengangguran terbuka", "tpt", "pengangguran terbuka"),
    "umk": ("umk", "upah minimum kabupaten", "upah minimum kota"),
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
    "orang": ("orang", "penduduk", "warga", "jiwa", "populasi"),
    "jiwa": ("jiwa", "orang", "penduduk", "warga", "populasi"),
    "warga": ("warga", "orang", "penduduk", "jiwa", "populasi"),
    "pria": ("pria", "laki-laki", "lelaki"),
    "laki": ("laki", "laki-laki", "pria"),
    "wanita": ("wanita", "perempuan"),
    "perempuan": ("perempuan", "wanita"),
    "perempu": ("perempuan", "wanita"),
    "jenis kelamin": ("jenis kelamin",),
    "pdrb": ("pdrb", "produk domestik regional bruto"),
    "produk domestik regional bruto": ("produk domestik regional bruto", "pdrb"),
    "ipm": ("ipm", "indeks pembangunan manusia"),
    "indeks pembangunan manusia": ("indeks pembangunan manusia", "ipm"),
    "inflasi": ("inflasi", "ihk", "indeks harga konsumen"),
    "ntp": ("ntp", "nilai tukar petani persen", "nilai tukar petani"),
    "sekolah": ("sekolah", "pendidikan"),
    "pendidikan": ("pendidikan", "sekolah"),
    "rumah sakit": ("rumah sakit", "rs", "kesehatan"),
    "puskesmas": ("puskesmas", "fasilitas kesehatan"),
    "posyandu": ("posyandu", "fasilitas kesehatan"),
    "klinik": ("klinik", "fasilitas kesehatan"),
    "perpustakaan": ("perpustakaan", "pustaka"),
    "pustaka": ("pustaka", "perpustakaan"),
    "bencana alam": ("bencana alam", "bencana"),
    "korban": ("korban", "korban bencana"),
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
    "korban": ("meninggal", "hilang", "terluka", "terdampak"),
    "bencana": ("bencana alam",),
    "jenis kelamin": ("gender", "sex"),
}
