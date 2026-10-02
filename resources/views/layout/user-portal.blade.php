<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PST Digital | Layanan Pengguna')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ url('bootstrap.min.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ url('user-portal.css') }}">
    <script>
        try {
            if (localStorage.getItem('pst-color-mode') === 'dark') document.documentElement.dataset.theme = 'dark';
        } catch (error) {}
    </script>
    @yield('styles')
</head>

<body class="user-portal">
    <div class="portal-shell">
        <div class="portal-scrim" data-portal-scrim></div>
        <aside class="portal-sidebar" data-portal-sidebar aria-label="Navigasi layanan">
            <a class="portal-brand" href="{{ url('/') }}">
                <img src="{{ url('img/perpustakaan.png') }}" alt="PST Digital">
                <span><strong>PST DIGITAL</strong><small>BPS PROVINSI SUMATERA SELATAN</small></span>
            </a>
            <div class="portal-member">
                @if (Auth::user()->picture)
                <img class="portal-avatar" src="{{ Auth::user()->picture }}" alt="">
                @else
                <span class="portal-avatar portal-avatar-initial">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                @endif
                <span class="portal-member-copy"><strong>{{ Auth::user()->name }}</strong><small>{{ Auth::user()->email }}</small></span>
            </div>
            <nav class="portal-nav" aria-label="Menu utama">
                <span class="portal-nav-label">LAYANAN</span>
                <a class="portal-nav-link {{ request()->is('/') ? 'is-active' : '' }}" href="{{ url('/') }}"><i class="bi bi-house-door"></i><span>Landing page</span></a>
                <a class="portal-nav-link {{ request()->is('konsultasi*') ? 'is-active' : '' }}" href="{{ url('konsultasi') }}"><i class="bi bi-calendar2-week"></i><span>Konsultasi</span></a>
                <a class="portal-nav-link {{ request()->is('chatbot*') ? 'is-active' : '' }}" href="{{ url('chatbot') }}"><i class="bi bi-chat-square-text"></i><span>Chatbot</span></a>
                <a class="portal-nav-link" href="https://perpustakaan.bps.go.id/opac/" target="_blank" rel="noopener"><i class="bi bi-journal-bookmark"></i><span>Katalog Publikasi</span><i class="bi bi-box-arrow-up-right portal-external"></i></a>
            </nav>
            <div class="portal-sidebar-bottom">
                <a class="portal-nav-link {{ request()->is('profile*') ? 'is-active' : '' }}" href="{{ route('profile') }}"><i class="bi bi-person-vcard"></i><span>Profil Saya</span></a>
                <a class="portal-logout" href="{{ route('logout') }}"><i class="bi bi-box-arrow-left"></i><span>Keluar</span></a>
                <div class="portal-bps-note"><span class="portal-bps-seal">BPS</span><span>Layanan Statistik Terpadu<small>Sumatera Selatan</small></span></div>
            </div>
        </aside>

        <div class="portal-main">
            <header class="portal-topbar">
                <button class="portal-menu-toggle" type="button" data-portal-toggle aria-label="Buka navigasi" aria-expanded="false"><i class="bi bi-list"></i></button>
                <div class="portal-topbar-title"><span>PORTAL PENGGUNA</span><strong>@yield('topbar_title', 'Layanan PST Digital')</strong></div>
                <div class="portal-topbar-actions"><button class="portal-theme-toggle" type="button" data-theme-toggle aria-pressed="false" aria-label="Aktifkan mode gelap" title="Aktifkan mode gelap"><i class="bi bi-moon-stars" aria-hidden="true"></i></button><span class="portal-topbar-date"><i class="bi bi-calendar3"></i>{{ now()->translatedFormat('d F Y') }}</span><a href="{{ route('profile') }}" class="portal-topbar-profile" aria-label="Profil saya">@if (Auth::user()->picture)<img src="{{ Auth::user()->picture }}" alt="">@else{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}@endif</a></div>
            </header>
            <main class="portal-content">
                @if (session('message'))<div class="alert alert-success portal-alert" role="status"><i class="bi bi-check-circle"></i>{{ session('message') }}</div>@endif
                @if (session('error'))<div class="alert alert-danger portal-alert" role="alert"><i class="bi bi-exclamation-circle"></i>{{ session('error') }}</div>@endif
                @if ($errors->any())<div class="alert alert-danger portal-alert" role="alert"><strong>Mohon periksa kembali isian Anda.</strong>
                    <ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>@endif
                @yield('content')
            </main>
            <footer class="portal-footer"><span>PST Digital · Badan Pusat Statistik Provinsi Sumatera Selatan</span><span>Pelayanan Statistik Terpadu</span></footer>
        </div>
    </div>
    <script src="{{ url('bootstrap.bundle.min.js') }}"></script>
    <script>
        (() => {
            const themeToggle = document.querySelector('[data-theme-toggle]');
            const themeIcon = themeToggle?.querySelector('i');
            const syncThemeToggle = () => {
                const isDark = document.documentElement.dataset.theme === 'dark';
                themeToggle?.setAttribute('aria-pressed', String(isDark));
                themeToggle?.setAttribute('aria-label', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
                themeToggle?.setAttribute('title', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
                if (themeIcon) themeIcon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
            };
            themeToggle?.addEventListener('click', () => {
                const isDark = document.documentElement.dataset.theme !== 'dark';
                document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
                try {
                    localStorage.setItem('pst-color-mode', isDark ? 'dark' : 'light');
                } catch (error) {}
                syncThemeToggle();
            });
            syncThemeToggle();
        })();

        (() => {
            const sidebar = document.querySelector('[data-portal-sidebar]');
            const scrim = document.querySelector('[data-portal-scrim]');
            const toggle = document.querySelector('[data-portal-toggle]');
            const close = () => {
                sidebar?.classList.remove('is-open');
                scrim?.classList.remove('is-visible');
                toggle?.setAttribute('aria-expanded', 'false');
            };
            toggle?.addEventListener('click', () => {
                const open = sidebar?.classList.toggle('is-open') ?? false;
                scrim?.classList.toggle('is-visible', open);
                toggle.setAttribute('aria-expanded', String(open));
            });
            scrim?.addEventListener('click', close);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') close();
            });
        })();
    </script>
    @yield('scripts')
</body>

</html>