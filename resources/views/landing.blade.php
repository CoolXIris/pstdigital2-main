@extends('layout.layout')

@section('css')
<style>
    :root {
        --pst-blue: #0878c9;
        --pst-green: #4e9d38;
        --pst-orange: #e58b22;
        --pst-ink: #153451;
        --pst-muted: #64748b;
        --pst-reveal-duration: 650ms;
    }

    .hero-section {
        isolation: isolate;
        overflow: hidden;
        margin-top: -76px;
        position: relative;
        background: #062c70;
    }

    .hero-section .carousel-inner {
        position: relative;
        z-index: 1;
    }

    .carousel-item {
        height: clamp(620px, 86vh, 810px);
        max-height: none;
        min-height: 620px;
        position: relative;
        overflow: hidden;
        background: linear-gradient(120deg, #073678 0%, #071c65 100%);
    }

    .carousel-item::before {
        content: "";
        position: absolute;
        inset: -5%;
        z-index: 2;
        pointer-events: none;
        background: radial-gradient(ellipse at 78% 26%, rgba(67, 181, 255, .2), transparent 40%),
            radial-gradient(ellipse at 30% 100%, rgba(82, 107, 255, .13), transparent 42%);
        mix-blend-mode: screen;
    }

    .carousel-item.active::before {
        animation: ambient-drift 15s ease-in-out infinite alternate;
    }

    .carousel-item::after {
        content: "";
        position: absolute;
        inset: 0;
        z-index: 2;
        background: linear-gradient(90deg, rgba(3, 26, 72, .86) 0%, rgba(4, 34, 91, .64) 49%, rgba(5, 31, 81, .16) 100%),
            linear-gradient(0deg, rgba(4, 24, 72, .38), transparent 46%);
        pointer-events: none;
    }

    .carousel-item img.bg-overlay {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: .72;
        z-index: 1;
    }

    .carousel-fade .carousel-item {
        transition-property: opacity;
        transition-duration: 850ms;
    }

    .carousel-caption {
        text-align: left;
        left: clamp(28px, 8vw, 120px);
        right: 24px;
        bottom: auto;
        top: 46%;
        transform: translateY(-50%);
        z-index: 3;
        max-width: 760px;
        padding: 0 0 34px;
    }

    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 9px 15px;
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .23);
        border-radius: 25px;
        color: white;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: .02em;
        margin-bottom: 23px;
        backdrop-filter: blur(12px);
    }

    .hero-badge::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #81e2b5;
        box-shadow: 0 0 0 4px rgba(129, 226, 181, .15);
    }

    .hero-title {
        font-size: clamp(42px, 5vw, 68px);
        font-weight: 700;
        letter-spacing: -.04em;
        line-height: 1.08;
        margin-bottom: 22px;
        color: white;
        text-wrap: balance;
    }

    .hero-description {
        max-width: 640px;
        font-size: clamp(16px, 1.5vw, 19px);
        font-weight: 400;
        color: rgba(242, 247, 255, .84);
        line-height: 1.7;
        margin-bottom: 27px;
    }

    .hero-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .hero-cta {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        min-height: 48px;
        padding: 0 21px;
        border: 1px solid rgba(255, 255, 255, .4);
        border-radius: 999px;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        transition: transform .25s ease, background-color .25s ease, border-color .25s ease;
    }

    .hero-cta.primary {
        border-color: transparent;
        background: linear-gradient(105deg, #169bdf, #0878c9, #1769ba);
        box-shadow: 0 8px 24px rgba(0, 107, 190, .28);
    }

    .hero-cta.secondary {
        background: rgba(255, 255, 255, .1);
        backdrop-filter: blur(10px);
    }

    .hero-cta:hover {
        color: #fff;
        transform: translateY(-2px);
        background-color: rgba(255, 255, 255, .2);
        border-color: rgba(255, 255, 255, .75);
    }

    .carousel-item.active .hero-badge,
    .carousel-item.active .hero-title,
    .carousel-item.active .hero-description,
    .carousel-item.active .hero-actions {
        animation: hero-enter .72s cubic-bezier(.2, .75, .25, 1) both;
    }

    .carousel-item.active .hero-title { animation-delay: 100ms; }
    .carousel-item.active .hero-description { animation-delay: 210ms; }
    .carousel-item.active .hero-actions { animation-delay: 320ms; }

    .carousel-control-prev,
    .carousel-control-next {
        width: 46px;
        height: 46px;
        top: 50%;
        transform: translateY(-50%);
        margin: 0 26px;
        border: 1px solid rgba(255, 255, 255, .25);
        background-color: rgba(255, 255, 255, .16);
        backdrop-filter: blur(10px);
        border-radius: 50%;
        z-index: 4;
        transition: background-color .2s ease, transform .2s ease;
    }

    .carousel-control-prev:hover,
    .carousel-control-next:hover {
        background-color: rgba(255, 255, 255, .28);
    }

    .carousel-indicators {
        z-index: 4;
        bottom: 66px;
        gap: 6px;
        margin-bottom: 0;
    }

    .carousel-indicators [data-bs-target] {
        position: relative;
        width: 30px;
        height: 4px;
        margin: 0;
        overflow: hidden;
        border: 0;
        border-radius: 8px;
        opacity: 1;
        background: rgba(255, 255, 255, .4);
    }

    .carousel-indicators [data-bs-target]::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: #fff;
        transform: scaleX(0);
        transform-origin: left;
    }

    .carousel-indicators [data-bs-target].active {
        width: 46px;
        background: rgba(255, 255, 255, .4);
    }

    .carousel-indicators [data-bs-target].active::after {
        animation: indicator-progress 5.6s linear both;
    }

    /* Layanan unggulan */
    .main-content {
        position: relative;
        z-index: 5;
        width: 100%;
        margin-top: -58px;
        padding: 72px 0 88px;
        border-radius: 54px 54px 0 0;
        background: #fff;
        box-shadow: 0 -14px 42px rgba(2, 21, 59, .08);
    }

    .container-inner {
        max-width: 1240px;
        margin: 0 auto;
        padding: 0 32px;
    }

    .section-kicker {
        display: inline-block;
        margin-bottom: 10px;
        color: var(--pst-blue);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .12em;
        text-transform: uppercase;
    }

    .services-heading h2 {
        color: var(--dark-blue);
        font-size: clamp(28px, 3vw, 36px);
        letter-spacing: -.03em;
    }

    .services-heading p {
        max-width: 570px;
        color: var(--pst-muted);
        line-height: 1.7;
    }

    .section-subtitle {
        max-width: 620px;
        color: var(--pst-muted);
        font-size: 14px;
        line-height: 1.7;
    }

    .service-card {
        --service-color: var(--pst-blue);
        --service-glow: rgba(8, 120, 201, .17);
        position: relative;
        isolation: isolate;
        overflow: hidden;
        height: 100%;
        min-height: 370px;
        padding: 34px 30px 30px;
        border: 1px solid #e9eef5;
        border-radius: 24px;
        background: #fff;
        box-shadow: 0 12px 32px rgba(18, 48, 83, .07);
        transition: transform .35s cubic-bezier(.2, .75, .25, 1), box-shadow .35s ease, border-color .35s ease;
    }

    .service-card::before {
        content: "";
        position: absolute;
        inset: 0 0 auto;
        z-index: 0;
        height: 4px;
        background: linear-gradient(90deg, transparent, var(--service-color), transparent);
        transform: scaleX(.18);
        opacity: .55;
        transition: transform .35s ease, opacity .35s ease;
    }

    .service-card.blue { --service-color: var(--pst-blue); --service-glow: rgba(8, 120, 201, .17); }
    .service-card.green { --service-color: var(--pst-green); --service-glow: rgba(78, 157, 56, .17); }
    .service-card.orange { --service-color: var(--pst-orange); --service-glow: rgba(229, 139, 34, .18); }

    .service-card:hover {
        transform: translateY(-8px);
        border-color: color-mix(in srgb, var(--service-color) 28%, white);
        box-shadow: 0 20px 42px var(--service-glow);
    }

    .service-card:hover::before { transform: scaleX(1); opacity: 1; }

    .service-card .card-body {
        position: relative;
        z-index: 1;
        min-height: 305px;
        width: 100%;
    }

    .service-icon {
        width: 76px;
        height: 76px;
        margin: 0 auto 22px;
        object-fit: contain;
        transition: transform .35s ease;
    }

    .service-card.blue:hover .service-icon { animation: chatbot-pulse 1.1s ease-in-out infinite; }
    .service-card.green:hover .service-icon { transform: scale(1.1); }
    .service-card.orange:hover .service-icon { transform: translateY(-4px) rotate(-3deg); }

    .service-card h3 {
        color: var(--pst-ink);
        font-size: 20px;
        font-weight: 700;
    }

    .service-card p {
        color: var(--pst-muted);
        font-size: 14px;
        line-height: 1.75;
    }

    .btn-card {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 48px;
        padding: 11px 16px;
        border: 0 !important;
        border-radius: 50px;
        color: #fff !important;
        font-weight: 600;
        text-decoration: none;
        background-size: 200% 100%;
        transition: background-position .35s ease, transform .25s ease, box-shadow .25s ease;
    }

    .btn-card::after {
        content: "";
        position: absolute;
        inset: 0;
        z-index: 0;
        opacity: 0;
        background: radial-gradient(circle at center, rgba(255,255,255,.32), transparent 68%);
        transform: scale(.5);
        transition: opacity .25s ease, transform .4s ease;
    }

    .btn-card > span { position: relative; z-index: 1; }

    .btn-card:hover {
        color: #fff !important;
        transform: translateY(-2px);
        background-position: 100% 0;
        box-shadow: 0 9px 20px var(--service-glow);
    }

    .btn-card:hover::after { opacity: 1; transform: scale(1.4); }
    .btn-blue { background-image: linear-gradient(105deg, #0878c9, #159fdf, #0878c9); }
    .btn-green { background-image: linear-gradient(105deg, #4e9d38, #70bd4f, #4e9d38); }
    .btn-orange { background-image: linear-gradient(105deg, #e58b22, #f3ab46, #e58b22); }

    /* Layanan tambahan */
    .additional-services { margin-top: 76px; }

    .section-title-small {
        color: var(--dark-blue);
        font-size: clamp(25px, 2.5vw, 32px);
        font-weight: 700;
        letter-spacing: -.03em;
    }

    .additional-card {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        height: 100%;
        min-height: 146px;
        padding: 22px;
        border: 1px solid #e9eef5;
        border-radius: 19px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(18, 48, 83, .055);
        transition: transform .35s ease, box-shadow .35s ease, border-color .35s ease;
    }

    .additional-card::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--pst-blue), #56b7e8, var(--pst-green));
        transform: scaleX(0);
        transform-origin: left;
        transition: transform .35s ease;
        z-index: 0;
    }

    .additional-card > * { position: relative; z-index: 1; }

    .additional-card:hover {
        transform: translateY(-5px);
        border-color: #cddff0;
        box-shadow: 0 16px 32px rgba(18, 65, 113, .12);
    }

    .additional-card:hover::after { transform: scaleX(1); }
    .additional-card img { flex-shrink: 0; object-fit: contain; }
    .additional-card h6 { color: var(--dark-blue); font-size: 15px; }
    .additional-card p { color: var(--pst-muted); font-size: 12px; line-height: 1.55; }

    .link-blue, .link-green, .link-orange {
        font-size: 12px;
        transition: opacity .2s ease, letter-spacing .2s ease;
    }
    .link-blue { color: var(--pst-blue); }
    .link-green { color: var(--pst-green); }
    .link-orange { color: var(--pst-orange); }
    .link-blue:hover, .link-green:hover, .link-orange:hover {
        opacity: .8;
        letter-spacing: .02em;
        text-decoration: underline;
    }

    html.motion-ready [data-reveal] {
        opacity: 0;
        transform: translateY(22px) scale(.985);
        transition: opacity var(--pst-reveal-duration) ease, transform var(--pst-reveal-duration) cubic-bezier(.2,.75,.25,1);
        transition-delay: var(--reveal-delay, 0ms);
    }

    html.motion-ready [data-reveal].is-visible {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    @keyframes hero-enter {
        from { opacity: 0; transform: translateY(18px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes ambient-drift {
        from { transform: translate3d(-12px, 4px, 0) scale(.96); }
        to { transform: translate3d(18px, -14px, 0) scale(1.04); }
    }

    @keyframes indicator-progress { to { transform: scaleX(1); } }

    @keyframes chatbot-pulse {
        0%, 100% { transform: scale(1) rotate(0); }
        35% { transform: scale(1.07) rotate(-2deg); }
        70% { transform: scale(1.04) rotate(2deg); }
    }

    @media (max-width: 991px) {
        .carousel-item { height: 720px; min-height: 620px; }
        .carousel-caption { left: 7vw; max-width: 700px; }
        .main-content { padding-top: 62px; }
        .container-inner { padding-right: 24px; padding-left: 24px; }
        .service-card { min-height: 355px; padding: 30px 24px 25px; }
        .service-card .card-body { min-height: 300px; }
        .carousel-control-prev { margin-left: 14px; }
        .carousel-control-next { margin-right: 14px; }
    }

    @media (max-width: 576px) {
        .carousel-item { height: 690px; min-height: 610px; }
        .carousel-item img.bg-overlay { object-position: 62% center; }
        .carousel-item::after {
            background: linear-gradient(90deg, rgba(3, 26, 72, .78), rgba(4, 34, 91, .46)),
                linear-gradient(0deg, rgba(4, 24, 72, .4), transparent 62%);
        }
        .carousel-caption {
            left: 22px;
            right: 22px;
            top: 47%;
            text-align: left;
            padding-bottom: 36px;
        }
        .hero-badge { margin-bottom: 18px; padding: 8px 12px; font-size: 11px; }
        .hero-title { font-size: clamp(37px, 10vw, 48px); line-height: 1.09; margin-bottom: 16px; }
        .hero-description { font-size: 14px; line-height: 1.65; margin-bottom: 20px; }
        .hero-actions { gap: 8px; }
        .hero-cta { min-height: 44px; padding: 0 15px; font-size: 12px; }
        .carousel-control-prev, .carousel-control-next { display: none; }
        .carousel-indicators { bottom: 44px; }
        .main-content { margin-top: -30px; padding: 52px 0 64px; border-radius: 30px 30px 0 0; }
        .container-inner { padding: 0 18px; }
        .services-heading { margin-bottom: 30px !important; }
        .service-card { min-height: 330px; padding: 28px 24px 22px; }
        .service-card .card-body { min-height: 278px; }
        .additional-services { margin-top: 56px; padding-top: 0 !important; }
        .additional-services .section-title-small { margin-bottom: 28px !important; }
        .additional-card { min-height: 132px; padding: 18px; }
    }

    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior: auto; }
        *, *::before, *::after {
            scroll-behavior: auto !important;
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: .01ms !important;
        }
        html.motion-ready [data-reveal] { opacity: 1; transform: none; }
    }
</style>
@endsection

@section('script')
<script>
    (() => {
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const header = document.querySelector('.header');
        const updateHeader = () => header?.classList.toggle('is-scrolled', window.scrollY > 12);

        updateHeader();
        window.addEventListener('scroll', updateHeader, { passive: true });

        const revealItems = [...document.querySelectorAll('[data-reveal]')];
        revealItems.forEach((item) => {
            const siblings = item.parentElement?.children ?? [];
            const index = [...siblings].indexOf(item);
            if (siblings.length > 1) item.style.setProperty('--reveal-delay', `${Math.min(index * 85, 510)}ms`);
        });

        document.documentElement.classList.add('motion-ready');

        if (!reduceMotion && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, currentObserver) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    currentObserver.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -36px 0px' });

            revealItems.forEach((item) => observer.observe(item));
        } else {
            revealItems.forEach((item) => item.classList.add('is-visible'));
        }

        const hero = document.getElementById('pstHeroCarousel');
        if (hero && window.bootstrap?.Carousel) {
            const carousel = window.bootstrap.Carousel.getOrCreateInstance(hero, {
                interval: reduceMotion ? false : 5600,
                pause: 'hover',
                ride: false,
                wrap: true,
            });

            if (!reduceMotion) carousel.cycle();
        }
    })();
</script>
@endsection

@section('content')
    <div id="pstHeroCarousel" class="carousel slide carousel-fade hero-section" data-bs-interval="5600">

        <div class="carousel-indicators">
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="0" class="active"
                aria-current="true" aria-label="Tampilkan slide 1"></button>
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="1" aria-label="Tampilkan slide 2"></button>
            <button type="button" data-bs-target="#pstHeroCarousel" data-bs-slide-to="2" aria-label="Tampilkan slide 3"></button>
        </div>

        <div class="carousel-inner">
            <div class="carousel-item active">
                <img src="{{ url('./img/slide=1.png') }}" class="bg-overlay" alt="Akses layanan data statistik resmi BPS" fetchpriority="high" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Selamat Datang di PST Digital</div>
                    <h1 class="hero-title text-balance">
                        Akses Data Statistik<br />Lebih Mudah dan Cepat
                    </h1>
                    <p class="hero-description text-pretty">
                        Platform terintegrasi Badan Pusat Statistik untuk memenuhi
                        kebutuhan data masyarakat umum, konsultasi statistik, dan akses
                        publikasi terbaru. Temukan layanan statistik resmi dalam satu tempat.
                    </p>
                    <div class="hero-actions">
                        <a href="#layanan" class="hero-cta primary">Jelajahi Layanan <i class="bi bi-arrow-down-right" aria-hidden="true"></i></a>
                        <a href="https://perpustakaan.bps.go.id/opac/" class="hero-cta secondary" target="_blank" rel="noopener">Katalog Publikasi <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
            <div class="carousel-item">
                <img src="{{ url('./img/slide=2.png') }}" class="bg-overlay" alt="Layanan konsultasi statistik dan chatbot" loading="lazy" decoding="async" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Layanan Terintegrasi</div>
                    <h1 class="hero-title text-balance">
                        Konsultasi Data<br />Kapan Saja, Dimana Saja
                    </h1>
                    <p class="hero-description text-pretty">
                        Akses mudah ke data statistik resmi BPS dengan dukungan konsultasi
                        virtual bersama operator dan chatbot yang siap membantu kapan saja.
                    </p>
                    <div class="hero-actions">
                        <a href="#layanan" class="hero-cta primary">Pilih Layanan <i class="bi bi-arrow-down-right" aria-hidden="true"></i></a>
                        <a href="{{ Auth::user()?->hasRole(['admin', 'super_admin']) ? route('admin.chatbot') : route('chatbot.index') }}" class="hero-cta secondary">Coba Chatbot <i class="bi bi-chat-dots" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
            <div class="carousel-item">
                <img src="{{ url('./img/slide=3.png') }}" class="bg-overlay" alt="Publikasi digital BPS yang dapat diakses gratis" loading="lazy" decoding="async" />
                <div class="carousel-caption d-sm-block d-md-block">
                    <div class="hero-badge">Publikasi Digital</div>
                    <h1 class="hero-title text-balance">Ribuan Publikasi<br />Tersedia Gratis</h1>
                    <p class="hero-description text-pretty">
                        Jelajahi dan unduh publikasi statistik resmi BPS dalam format
                        digital secara gratis untuk mendukung riset, pekerjaan, dan keputusan Anda.
                    </p>
                    <div class="hero-actions">
                        <a href="https://perpustakaan.bps.go.id/opac/" class="hero-cta primary" target="_blank" rel="noopener">Cari Publikasi <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                        <a href="#layanan-tambahan" class="hero-cta secondary">Layanan Lainnya <i class="bi bi-arrow-down-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </div>

        <button class="carousel-control-prev" type="button" data-bs-target="#pstHeroCarousel" data-bs-slide="prev" aria-label="Slide sebelumnya">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#pstHeroCarousel" data-bs-slide="next" aria-label="Slide berikutnya">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    </div>

    <!-- ======================MAIN CONTENT======================== -->

    <main class="main-content">
        <div class="container-inner">
            <div id="layanan" class="text-center mb-5 services-heading" data-reveal>
                <span class="section-kicker">PST Digital BPS</span>
                <h2 class="fw-bold">
                    Layanan Unggulan
                </h2>
                <p class="section-subtitle mx-auto">
                    Pilih cara yang paling sesuai untuk menemukan data, membaca publikasi,
                    atau berkonsultasi dengan layanan statistik BPS.
                </p>
            </div>

            <div class="row g-4 justify-content-center">
                <div class="col-lg-4 col-md-6" data-reveal>
                    <div class="card service-card blue">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/chatbot.png') }}" class="service-icon" alt="Chatbot" loading="lazy" decoding="async" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Chatbot</h3>
                                <p class="text-muted mt-3">
                                    Konsultasi data statistik cepat dan mudah selama 24 jam di
                                    mana dikelola secara otomatis oleh robot
                                </p>
                            </div>
                            <a href="{{ Auth::user()?->hasRole(['admin', 'super_admin']) ? route('admin.chatbot') : route('chatbot.index') }}" class="btn btn-card btn-blue mt-4"><span>Mulai Chat Sekarang</span></a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6" data-reveal>
                    <div class="card service-card green">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/konsultasi.png') }}" class="service-icon" alt="Konsultasi" loading="lazy" decoding="async" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Konsultasi Virtual</h3>
                                <p class="text-muted mt-3">
                                    Konsultasi secara virtual untuk mendapatkan penjelasan
                                    terkait metodologi dan indikator statistik oleh operator
                                </p>
                            </div>
                            <a href="{{ Auth::user()?->hasRole(['admin', 'super_admin']) ? route('admin.konsultasi') : route('konsultasi.index') }}" class="btn btn-card btn-green mt-4"><span>Buat Janji Temu</span></a>
                        </div>
                    </div>
                </div>

                <!-- ===============LAYANAN TAMBAHAN==================== -->

                <div class="col-lg-4 col-md-6" data-reveal>
                    <div class="card service-card orange">
                        <div class="card-body d-flex flex-column align-items-center p-0">
                            <img src="{{ url('./img/katalog.png') }}" class="service-icon" alt="Katalog" loading="lazy" decoding="async" />
                            <div class="flex-grow-1">
                                <h3 class="fw-bold">Katalog Publikasi</h3>
                                <p class="text-muted mt-3">
                                    Akses dan unduh ribuan publikasi statistik resmi BPS dalam
                                    format digital secara gratis.
                                </p>
                            </div>
                            <a href="https://perpustakaan.bps.go.id/opac/" class="btn btn-card btn-orange mt-4"><span>Jelajahi Katalog</span></a>
                        </div>
                    </div>
                </div>
            </div>

            <div id="layanan-tambahan" class="additional-services">
                <div class="text-center mb-5" data-reveal>
                    <span class="section-kicker">Lebih banyak akses</span>
                    <h2 class="fw-bold section-title-small">
                    Layanan Utama Tambahan
                    </h2>
                    <p class="section-subtitle mx-auto">Jelajahi berbagai portal statistik dan layanan publik resmi BPS.</p>
                </div>
                <div class="row g-4">
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/perpustakaan.png') }}" width="57" height="57"
                                    alt="Perpustakaan" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/silastik.png') }}" width="57" height="57"
                                    alt="Silastik" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/romantik.png') }}" width="57" height="57"
                                    alt="Romantik" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/pojok statistik.png') }}" width="57" height="57"
                                    alt="Pojok Statistik" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/webapi.png') }}" width="57" height="17" alt="WebAPI"
                                    style="object-fit: contain" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/ppid.png') }}" width="57" height="37" alt="PPID"
                                    style="object-fit: contain" loading="lazy" decoding="async" />
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
                    <div class="col-lg-4 col-md-6" data-reveal>
                        <div class="card additional-card">
                            <div class="d-flex align-items-center gap-3">
                                <img src="{{ url('./img/lapor.png') }}" width="57" height="41" alt="Lapor"
                                    style="object-fit: contain" loading="lazy" decoding="async" />
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
