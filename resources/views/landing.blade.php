@extends('layout.layout')

@section('css')
<style>
    /* ================= MAIN CONTENT (Layanan Utama) ================= */
        .main-content {
            background: #ffffff;
            border-radius: 80px 80px 0 0;
            margin-top: -100px;
            position: relative;
            z-index: 50;
            padding: 60px 0 80px 0;
            width: 100%;
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.05);
        }

        .container-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 40px;
        }

        .service-card {
            border: none;
            border-radius: 25px;
            padding: 40px 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            background: white;
            min-height: 439px;
            height: auto;
            max-width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
        }

        .service-card:hover {
            transform: translateY(-10px);
        }

        .service-card.blue:hover {
            box-shadow: 0 15px 45px rgba(0, 147, 221, 0.25);
        }

        .service-card.green:hover {
            box-shadow: 0 15px 45px rgba(104, 185, 46, 0.25);
        }

        .service-card.orange:hover {
            box-shadow: 0 15px 45px rgba(235, 137, 27, 0.25);
        }

        .service-icon {
            width: 70px;
            height: 70px;
            object-fit: contain;
            margin-bottom: 10px;
        }

        .service-card h3 {
            font-size: 20px;
            font-weight: 700;
            color: #000;
        }

        .service-card p {
            font-size: 16px;
            color: #777;
            line-height: 1.6;
        }

        .btn-card {
            width: 100%;
            border-radius: 50px;
            padding: 12px 0;
            font-weight: 600;
            color: white !important;
            border: none !important;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: none !important;
            transform: none !important;
        }

        .btn-card:active {
            filter: none !important;
            transform: none !important;
            box-shadow: none !important;
            color: white !important;
        }

        .btn-blue {
            background-color: var(--accent-blue) !important;
        }

        .btn-green {
            background-color: var(--accent-green) !important;
        }

        .btn-orange {
            background-color: var(--accent-orange) !important;
        }

        /* ================= LAYANAN UTAMA TAMBAHAN ================= */
        .section-title-small {
            color: var(--dark-blue);
            font-size: 24px;
            font-weight: 700;
        }

        .additional-card {
            background: #ffffff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border-radius: 20px;
            padding: 25px;
            border: none;
            transition: transform 0.3s ease, background-color 0.3s ease;
            height: 100%;
            display: flex;
            align-items: center;
        }

        .additional-card:hover {
            transform: translateY(-5px);
            background-color: #f8f8f8;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .additional-card img {
            flex-shrink: 0;
            object-fit: contain;
        }

        .additional-card h6 {
            font-size: 16px;
            color: var(--dark-blue);
            margin-bottom: 4px;
        }

        .additional-card p {
            font-size: 13px;
            color: #666;
            line-height: 1.4;
        }

        .link-blue {
            color: var(--accent-blue);
            transition: opacity 0.2s;
        }

        .link-green {
            color: var(--accent-green);
            transition: opacity 0.2s;
        }

        .link-orange {
            color: var(--accent-orange);
            transition: opacity 0.2s;
        }

        .link-blue:hover,
        .link-green:hover,
        .link-orange:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
</style>
@endsection

@section('content')
    <div id="pstHeroCarousel" class="carousel slide hero-section" data-bs-ride="carousel" data-bs-interval="5000">

        <div class="carousel-indicators">
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="0" class="active"
                aria-current="true"></button>
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="1"></button>
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="2"></button>
        </div>

        <div class="carousel-inner">
            <div class="carousel-item active">
                <img src="{{ url('./img/slide=1.png') }}" class="bg-overlay" alt="Slide 1" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Selamat Datang di PST Digital</div>
                    <h1 class="hero-title">
                        Akses Data Statistik<br />Lebih Mudah dan Cepat
                    </h1>
                    <p class="hero-description">
                        Platform terintegrasi Badan Pusat Statistik untuk memenuhi
                        kebutuhan data masyarakat umum, konsultasi statistik, dan akses
                        publikasi terbaru
                    </p>
                </div>
            </div>
            <div class="carousel-item">
                <img src="{{ url('./img/slide=2.png') }}" class="bg-overlay" alt="Slide 2" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Layanan Terintegrasi</div>
                    <h1 class="hero-title">
                        Konsultasi Data<br />Kapan Saja, Dimana Saja
                    </h1>
                    <p class="hero-description">
                        Akses mudah ke data statistik resmi BPS dengan dukungan konsultasi
                        virtual dan chatbot 24/7
                    </p>
                </div>
            </div>
            <div class="carousel-item">
                <img src="{{ url('./img/slide=3.png') }}" class="bg-overlay" alt="Slide 3" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Publikasi Digital</div>
                    <h1 class="hero-title">Ribuan Publikasi<br />Tersedia Gratis</h1>
                    <p class="hero-description">
                        Jelajahi dan unduh publikasi statistik resmi BPS dalam format
                        digital secara gratis
                    </p>
                </div>
            </div>
        </div>

        <button class="carousel-control-prev" type="button" data-bs-target="#pstHeroCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#pstHeroCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    </div>

    <!-- ======================MAIN CONTENT======================== -->

    <main class="main-content">
        <div class="container-inner">
            <div class="text-center mb-5">
                <h2 class="fw-bold" style="color: var(--dark-blue); font-size: 31px">
                    Layanan Unggulan
                </h2>
                <p class="section-subtitle mx-auto">
                    Akses cepat ke tiga layanan kami untuk mempermudah kebutuhan data
                    dan konsultasi anda
                </p>
            </div>

            <div class="row g-4 justify-content-center">
                <div class="col-lg-4 col-md-6">
                    <div class="card service-card blue">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/chatbot.png') }}" class="service-icon" alt="Chatbot" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Chatbot</h3>
                                <p class="text-muted mt-3">
                                    Konsultasi data statistik cepat dan mudah selama 24 jam di
                                    mana dikelola secara otomatis oleh robot
                                </p>
                            </div>
                            <a href="{{ Auth::user()?->hasRole(['admin', 'super_admin']) ? route('admin.chatbot') : route('chatbot.index') }}" class="btn btn-card btn-blue mt-4">Mulai Chat Sekarang</a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6">
                    <div class="card service-card green">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/konsultasi.png') }}" class="service-icon" alt="Konsultasi" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Konsultasi Virtual</h3>
                                <p class="text-muted mt-3">
                                    Konsultasi secara virtual untuk mendapatkan penjelasan
                                    terkait metodologi dan indikator statistik oleh operator
                                </p>
                            </div>
                            <a href="{{ Auth::user()?->hasRole(['admin', 'super_admin']) ? route('admin.konsultasi') : route('konsultasi.index') }}" class="btn btn-card btn-green mt-4">Buat Janji Temu</a>
                        </div>
                    </div>
                </div>

                <!-- ===============LAYANAN TAMBAHAN==================== -->

                <div class="col-lg-4 col-md-6">
                    <div class="card service-card orange">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/katalog.png') }}" class="service-icon" alt="Katalog" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Katalog Publikasi</h3>
                                <p class="text-muted mt-3">
                                    Akses dan unduh ribuan publikasi statistik resmi BPS dalam
                                    format digital secara gratis.
                                </p>
                            </div>
                            <a href="https://perpustakaan.bps.go.id/opac/" class="btn btn-card btn-orange mt-4">Jelajahi
                                Katalog</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-5">
                <h2 class="text-center mb-5 fw-bold section-title-small">
                    Layanan Utama Tambahan
                </h2>
                <div class="row g-4">
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/perpustakaan.png') }}" width="57" height="57"
                                    alt="Perpustakaan" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">
                                        Perpustakaan Digital
                                    </h6>
                                    <p class="small text-muted mb-2">
                                        Akses koleksi publikasi digital melalui
                                        perpustakaan.bps.go.id
                                    </p>
                                    <a href="https://perpustakaan.bps.go.id"
                                        class="small fw-bold text-decoration-none link-blue">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/silastik.png') }}" width="57" height="57"
                                    alt="Silastik" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">Silastik</h6>
                                    <p class="small text-muted mb-2">
                                        Sistem layanan statistik terintegrasi melalui
                                        silastik.web.bps.go.id
                                    </p>
                                    <a href="https://silastik.web.bps.go.id"
                                        class="small fw-bold text-decoration-none link-green">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/romantik.png') }}" width="57" height="57"
                                    alt="Romantik" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">Romantik</h6>
                                    <p class="small text-muted mb-2">
                                        Rujukan metadata statistik melalui romantik.web.bps.go.id
                                    </p>
                                    <a href="https://romantik.web.bps.go.id"
                                        class="small fw-bold text-decoration-none link-orange">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/pojok statistik.png') }}" width="57" height="57"
                                    alt="Pojok Statistik" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">
                                        Pojok Statistik Sumsel
                                    </h6>
                                    <p class="small text-muted mb-2">
                                        Layanan pojok statistik virtual melalui
                                        pojokstatistik.bps.go.id
                                    </p>
                                    <a href="https://pojokstatistik.bps.go.id/"
                                        class="small fw-bold text-decoration-none link-blue">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/webapi.png') }}" width="57" height="17" alt="WebAPI"
                                    style="object-fit: contain" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">WebAPI Sumsel</h6>
                                    <p class="small text-muted mb-2">
                                        Sistem layanan API terintegrasi BPS Provinsi Sumsel
                                    </p>
                                    <a href="https://webapi.bps.go.id/developer/"
                                        class="small fw-bold text-decoration-none link-green">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/ppid.png') }}" width="57" height="37" alt="PPID"
                                    style="object-fit: contain" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">PPID Sumsel</h6>
                                    <p class="small text-muted mb-2">
                                        Layanan Informasi Publik BPS Provinsi Sumsel
                                    </p>
                                    <a href="https://ppid.bps.go.id/?mfd=0000"
                                        class="small fw-bold text-decoration-none link-orange">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6">
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/lapor.png') }}" width="57" height="41" alt="Lapor"
                                    style="object-fit: contain" />
                                <div>
                                    <h6 class="fw-bold mb-1 card-title-blue">Lapor!</h6>
                                    <p class="small text-muted mb-2">
                                        Layanan Aspirasi dan Pengaduan Online Rakyat
                                    </p>
                                    <a href="https://www.lapor.go.id/"
                                        class="small fw-bold text-decoration-none link-blue">Kunjungi</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
@endsection
