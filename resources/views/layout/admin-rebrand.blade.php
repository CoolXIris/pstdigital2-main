<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Administrasi PST Digital')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ url('zanex/plugins/bootstrap/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ url('zanex/css/icons.css') }}">
    <link rel="stylesheet" href="{{ url('admin-rebrand.css') }}">
    <script>
        try {
            if (localStorage.getItem('pst-color-mode') === 'dark') document.documentElement.dataset.theme = 'dark';
        } catch (error) {}
    </script>
    @yield('styles')
</head>
<body class="pstd-admin-page">
    <div class="admin-shell">
        <div class="admin-scrim" data-admin-scrim></div>
        <aside class="admin-sidebar" data-admin-sidebar aria-label="Navigasi administrasi">
            <a class="admin-brand" href="{{ route('dashboard') }}">
                <img src="{{ url('img/pst1.png') }}" alt="PST Digital BPS">
                <span><strong>PST DIGITAL</strong><small>BADAN PUSAT STATISTIK</small></span>
            </a>

            <div class="admin-identity">
                @if (Auth::user()->picture)
                    <img class="admin-avatar" src="{{ Auth::user()->picture }}" alt="">
                @else
                    <span class="admin-avatar admin-avatar-initial">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                @endif
                <span class="admin-identity-copy"><strong>{{ Auth::user()->name }}</strong><small>{{ Auth::user()->getRoleNames()->first() ?? 'Admin' }}</small></span>
                <span class="admin-online-dot" aria-label="Aktif"></span>
            </div>

            <nav class="admin-nav" aria-label="Menu utama">
                <p class="admin-nav-label">RUANG KERJA</p>
                <a class="admin-nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">
                    <i class="fe fe-grid" aria-hidden="true"></i><span>Dashboard</span>
                </a>
                <a class="admin-nav-link {{ request()->routeIs('admin.konsultasi*') ? 'is-active' : '' }}" href="{{ route('admin.konsultasi') }}">
                    <i class="fe fe-calendar" aria-hidden="true"></i><span>Manajemen Konsultasi</span>
                </a>
                <a class="admin-nav-link {{ request()->routeIs('admin.chatbot') ? 'is-active' : '' }}" href="{{ route('admin.chatbot') }}">
                    <i class="fe fe-message-square" aria-hidden="true"></i><span>Manajemen Chatbot</span>
                </a>
                <a class="admin-nav-link {{ request()->routeIs('users.*') ? 'is-active' : '' }}" href="{{ route('users.index') }}">
                    <i class="fe fe-users" aria-hidden="true"></i><span>Manajemen Pengguna</span>
                </a>
            </nav>

            <div class="admin-sidebar-footer">
                <div class="admin-sidebar-note"><span class="admin-bps-mark">BPS</span><span>Layanan Statistik Terpadu<small>Sumatera Selatan</small></span></div>
                <a class="admin-signout" href="{{ route('logout') }}"><i class="fe fe-log-out" aria-hidden="true"></i><span>Keluar</span></a>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <button class="admin-menu-toggle" type="button" data-admin-toggle aria-label="Buka menu" aria-expanded="false"><i class="fe fe-menu" aria-hidden="true"></i></button>
                <div class="admin-topbar-context"><span class="admin-topbar-kicker">PST DIGITAL / ADMINISTRASI</span><strong>@yield('topbar_title', 'Pusat kendali layanan')</strong></div>
                <div class="admin-topbar-actions">
                    <button class="admin-theme-toggle" type="button" data-theme-toggle aria-pressed="false" aria-label="Aktifkan mode gelap" title="Aktifkan mode gelap"><i class="fe fe-moon" aria-hidden="true"></i></button>
                    <span class="admin-date"><i class="fe fe-calendar" aria-hidden="true"></i>{{ now()->translatedFormat('l, d F Y') }}</span>
                    <a class="admin-profile" href="{{ route('profile') }}" aria-label="Buka profil {{ Auth::user()->name }}">
                        @if (Auth::user()->picture)<img src="{{ Auth::user()->picture }}" alt="">@else<span>{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>@endif
                    </a>
                </div>
            </header>

            <main class="admin-content">
                @if (session('message'))<div class="alert alert-success admin-alert" role="status">{{ session('message') }}</div>@endif
                @if (session('error'))<div class="alert alert-danger admin-alert" role="alert">{{ session('error') }}</div>@endif
                @if (isset($errors) && $errors->any())<div class="alert alert-danger admin-alert" role="alert"><strong>Periksa kembali data yang dikirim.</strong><ul class="mb-0 mt-2">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @yield('content')
            </main>
            <footer class="admin-footer"><span>PST Digital · Badan Pusat Statistik Provinsi Sumatera Selatan</span><span>Portal Administrasi</span></footer>
        </div>
    </div>

    <script src="{{ url('zanex/js/jquery.min.js') }}"></script>
    <script src="{{ url('zanex/plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ url('zanex/plugins/chart/Chart.bundle.js') }}"></script>
    <script>
        (() => {
            const themeToggle = document.querySelector('[data-theme-toggle]');
            const themeIcon = themeToggle?.querySelector('i');
            const syncThemeToggle = () => {
                const isDark = document.documentElement.dataset.theme === 'dark';
                themeToggle?.setAttribute('aria-pressed', String(isDark));
                themeToggle?.setAttribute('aria-label', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
                themeToggle?.setAttribute('title', isDark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
                if (themeIcon) themeIcon.className = isDark ? 'fe fe-sun' : 'fe fe-moon';
            };
            themeToggle?.addEventListener('click', () => {
                const isDark = document.documentElement.dataset.theme !== 'dark';
                document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
                try { localStorage.setItem('pst-color-mode', isDark ? 'dark' : 'light'); } catch (error) {}
                syncThemeToggle();
            });
            syncThemeToggle();
        })();

        (() => {
            const sidebar = document.querySelector('[data-admin-sidebar]');
            const scrim = document.querySelector('[data-admin-scrim]');
            const toggle = document.querySelector('[data-admin-toggle]');
            const closeMenu = () => {
                sidebar?.classList.remove('is-open');
                scrim?.classList.remove('is-visible');
                toggle?.setAttribute('aria-expanded', 'false');
            };
            toggle?.addEventListener('click', () => {
                const isOpen = sidebar?.classList.toggle('is-open') ?? false;
                scrim?.classList.toggle('is-visible', isOpen);
                toggle.setAttribute('aria-expanded', String(isOpen));
            });
            scrim?.addEventListener('click', closeMenu);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeMenu();
            });
        })();
    </script>
    @yield('scripts')
</body>
</html>
