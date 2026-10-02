<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Landing PST Digital - Badan Pusat Statistik</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="{{ url('./bootstrap.min.css') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet" />

    <!-- STYLE CSS -->
    <link href="https://cdn.jsdelivr.net/npm/timepicker-ui@4.3.0/dist/css/index.min.css" rel="stylesheet" />

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/jquery-timepicker/1.11.13/jquery.timepicker.min.css"
        integrity="sha512-o/EXVNKft3N3KwEPXZ+D1eizHBCXrDF91uZBZYoomlpqw9YIFYfPNt1YUoRQxt4OY1wCoMGNbWkkVc9dU3/72g=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/css/select2.min.css"
        integrity="sha512-2L0dEjIN/DAmTV5puHriEIuQU/IL3CoPm/4eyZp3bM8dnaXri6lK8u6DC5L96b+YSs9f3Le81dDRZUSYeX4QcQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css"
        integrity="sha512-aOG0c6nPNzGk+5zjwyJaoRUgCdOrfSDhmMID2u4+OIslr0GjpLKo7Xm0Ao3xmpM4T8AmIouRkqwj1nrdVsLKEQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    {{-- <link href="{{ url('zanex/css/dark-style.css') }}" rel="stylesheet" />
        <link href="{{ url('zanex/css/skin-modes.css') }}" rel="stylesheet" />
        <link href="{{ url('zanex/css/transparent-style.css') }}" rel="stylesheet" /> --}}

    <!--- FONT-ICONS CSS -->
    {{-- <link href="{{ url('zanex/css/icons.css') }}" rel="stylesheet" /> --}}
    <style>
        /* Container Utama: Fixed agar melayang */
        /* ================= GLOBAL VARIABLES ================= */
        :root {
            --primary-blue: #000080;
            --dark-blue: #043277;
            --accent-blue: #0093dd;
            --accent-green: #68b92e;
            --accent-orange: #eb891b;
            --dark-blue-nav: #043277;
            --accent-orange-nav: #eb891b;
        }

        body {
            font-family: "Poppins", sans-serif;
        }

        /* ================= NAVBAR STYLING ================= */
        .header {
            background-color: var(--dark-blue-nav) !important;
            padding: 10px 50px !important;
            position: sticky;
            height: 80px;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .nav-link {
            color: #ffffff !important;
            font-size: 15px;
            font-weight: 500;
            padding: 10px 0 !important;
        }

        .dropdown-menu {
            background-color: #043277;
            border-radius: 12px;
            border: none;
        }

        .dropdown-item {
            color: #ffffff;
            font-size: 14px;
            padding: 10px 20px;
        }

        .dropdown-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .btn-login-header {
            background-color: var(--accent-orange-nav);
            color: #ffffff !important;
            padding: 8px 30px;
            border-radius: 30px;
            font-weight: 700;
            text-decoration: none;
            transition: 0.3s;
        }

        .btn-login-header:hover {
            background-color: #d17a17;
            transform: scale(1.05);
        }

        .nav-logo {
            height: 45px;
            width: auto;
            object-fit: contain;
            display: block;
        }

        /* ================= CAROUSEL ================= */
        .hero-section {
            margin-top: -76px;
            position: relative;
        }

        .carousel-item {
            background: linear-gradient(180deg, #0072c7 0%, #000986 98%);
            height: 769px;
            position: relative;
            max-height: 90vh;
        }

        .carousel-item img.bg-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.7;
            z-index: 1;
        }

        .carousel-caption {
            text-align: left;
            left: 94px;
            right: auto;
            bottom: auto;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            max-width: 800px;
        }

        .hero-badge {
            display: inline-block;
            padding: 12px 20px;
            background-color: #1a309b;
            border: 1px solid #394da9;
            border-radius: 25px;
            color: white;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .hero-title {
            font-size: 48px;
            font-weight: 600;
            line-height: 1.1;
            margin-bottom: 30px;
            color: white;
        }

        .hero-description {
            font-size: 20px;
            font-weight: 700;
            color: #cdcdcd;
            line-height: 1.4;
        }

        .carousel-control-prev,
        .carousel-control-next {
            width: 45px;
            height: 45px;
            background-color: rgba(255, 255, 255, 0.29);
            backdrop-filter: blur(7px);
            border-radius: 50%;
            top: 50%;
            transform: translateY(-50%);
            margin: 0 30px;
        }

        .carousel-indicators {
            bottom: 120px;
        }

        .carousel-indicators [data-bs-target] {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: none;
        }

        /* ================= FOOTER ================= */
        .footer {
            background-color: #043277;
            color: #fff7e0;
            padding: 60px 0;
            position: relative;
            z-index: 40;
        }

        .footer-logo {
            height: 81px;
            width: auto;
        }

        .footer-social-icons {
            display: flex;
            gap: 28px;
            margin-top: 50px;
        }

        .social-icon {
            width: 23px;
            height: 23px;
            fill: none;
            cursor: pointer;
        }

        .social-icon:hover {
            transform: translateY(-3px);
        }

        .footer-title {
            font-weight: 700;
            margin-bottom: 20px;
            font-size: 16px;
            color: #ffffff;
        }

        .footer-link {
            color: #fff7e0;
            text-decoration: none;
            display: block;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .footer-link:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        /* ================= RESPONSIVE ================= */
        @media (max-width: 1440px) {
            .carousel-caption {
                zoom: 0.85;
            }

            .main-content {
                zoom: 0.85;
            }

            .footer {
                zoom: 0.85;
            }
        }

        @media (max-width: 991px) {

            /* header tetap */
            .header {
                padding: 10px 16px;
            }

            /* tombol burger */
            .navbar-toggler {
                border: none;
                box-shadow: none;
            }

            .navbar-toggler-icon {
                filter: brightness(0) invert(1);
            }

            /* container dropdown */
            .navbar-collapse {
                position: absolute;
                top: 100%;
                right: 16px;
                left: 16px;
                background-color: #043277;
                border-radius: 24px;
                padding: 20px;
                margin-top: 8px;
                z-index: 999;
            }

            .profile-icon-svg {
                display: none;
            }

            .profile-text {
                display: inline;
                font-size: 16px;
                font-weight: 600;
                color: #ffffff;
            }

            .navbar-nav,
            .navbar-nav .nav-item,
            .navbar-nav .nav-link {
                text-align: left !important;
                justify-content: flex-start;
            }

            .nav-profile .nav-link {
                text-align: left !important;
                padding-left: 8px;
            }
        }

        @media (max-width: 576px) {
            .carousel-item {
                height: 460px;
            }

            .carousel-caption {
                position: absolute;
                left: 16px;
                right: 16px;
                top: 55%;
                transform: translateY(-50%);
                text-align: center;
            }

            .hero-title {
                font-size: 24px;
                line-height: 1.25;
            }

            .hero-description {
                font-size: 14px;
                font-weight: 500;
            }

            .hero-badge {
                font-size: 11px;
                padding: 6px 12px;
            }
        }

        .sidebar-wrapper {
            position: fixed;
            top: 0;
            right: -300px;
            width: 300px;
            height: 100vh;
            background-color: #ffffff;
            z-index: 9999;
            transition: 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
            box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
        }

        /* Munculkan sidebar saat class .active ditambahkan */
        .sidebar-wrapper.active {
            right: 0;
        }

        .sidebar-header-blue {
            background-color: #043277;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 20px;
        }

        #closeSidebarBtn {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
        }

        .sidebar-user-profile {
            padding: 40px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .avatar-icon i {
            font-size: 70px;
            color: #6B7280;
        }

        .name {
            font-weight: 600;
            margin-top: 15px;
            margin-bottom: 0;
        }

        .role {
            color: #9CA3AF;
            font-size: 14px;
        }

        .sidebar-links {
            padding: 15px;
        }

        .link-item {
            display: block;
            padding: 12px 20px;
            color: #4B5563;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .link-item.active-link {
            background-color: #E5E7EB;
            color: #111827;
            font-weight: 500;
        }

        .link-item:hover:not(.active-link) {
            background-color: #f9fafb;
        }

        .sidebar-dark-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 9998;
        }

        .sidebar-dark-overlay.active {
            display: block;
        }

        .info-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .info-box h5 {
            font-size: 22px;
            font-weight: 600;
        }

        .info-box p {
            font-size: 14px;
            color: #525252;
        }

        .info-icon {
            background-color: #eff6ff;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #155dfc;
        }

        /* ================= MAIN SECTION ================= */
        .main-section {
            display: flex;
            flex-direction: column;
            gap: 40px;
        }

        /* White container behind button */
        .button-container-card {
            background-color: #ffffff;
            padding-left: 30px;
            padding-top: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            width: 100%;
            display: flex;
            align-items: center;
        }

        /* ================= BUTTON ================= */
        .add-card {
            margin-bottom: 32px;
        }

        .add-card .btn {
            background-color: #6159c9;
            color: #ffffff;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            border: none;
            transition: all 0.3s ease;
        }

        .add-card .btn:hover {
            background-color: #4e46b4;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(97, 89, 201, 0.3);
        }

        /* ================= SECTION ================= */
        .section {
            width: 100%;
            margin-bottom: 20px;
        }

        .section h6 {
            font-size: 20px;
            font-weight: 500;
            line-height: 120%;
        }

        .section small {
            color: #525252;
        }
    </style>
    @yield('css')
</head>

<body>
    <nav class="navbar navbar-expand-lg header navbar-dark">
        <div class="container-fluid">
            <div class="logo-section d-flex align-items-center">
                <a href="{{ url('/') }}" class="d-flex align-items-center text-decoration-none">
                    <img src="{{ url('./img/pst digital.png') }}" alt="PST Logo" class="nav-logo" />
                    <img src="{{ url('./img/logo bps.png') }}" alt="BPS Logo"
                        class="nav-logo ms-3 d-none d-sm-inline" />
                    <img src="{{ url('./img/cbq.png') }}" alt="CBQ Logo" class="nav-logo ms-3 d-none d-md-inline" />
                </a>
            </div>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-4">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ url('/') }}">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button"
                            data-bs-toggle="dropdown">Layanan</a>
                        <ul class="dropdown-menu shadow">
                            <li>
                                <a class="dropdown-item" href="{{ url('konsultasi') }}">Konsultasi Virtual</a>
                            </li>
                            <li><a class="dropdown-item" href="{{ url('chatbot') }}">Chatbot</a></li>
                            <li>
                                <a class="dropdown-item" target="_blank"
                                    href="https://perpustakaan.bps.go.id/opac/">Katalog
                                    Publikasi</a>
                            </li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="https://ppid.bps.go.id/app/konten/1600/Profil-BPS.html">Tentang
                            Kami</a>
                    </li>
                    @auth
                        <a href="../html/sidebar.html" class="nav-link profile-icon">
                            <span class="profile-icon-svg">
                                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" fill="currentColor"
                                    class="bi bi-person-circle" viewBox="0 0 16 16">
                                    <path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
                                    <path fill-rule="evenodd"
                                        d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1z" />
                                </svg>
                            </span>
                            <span class="profile-text"></span>
                        </a>
                    @else
                        <li class="nav-item mt-3 mt-lg-0">
                            <a href="{{ url('login') }}"
                                class="btn-login-header d-inline-block text-center w-100 w-lg-auto">Masuk</a>
                        </li>
                    @endauth


                </ul>
            </div>
        </div>
    </nav>


    @yield('content')

    <div class="sidebar-wrapper" id="sidebarMenu">
        <div class="sidebar-header-blue">
            <button id="closeSidebarBtn"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="sidebar-user-profile text-center">
            <div class="avatar-icon"><i class="bi bi-person-circle"></i></div>
            <h6 class="name">
                @auth
                    {{ Auth::user()->name }}
                @endauth
            </h6>
            <p class="role">
                @auth
                    {{ Auth::user()->getRoleNames()->first() ?? 'user' }}
                @endauth
            </p>
        </div>

        <div class="sidebar-links">
            <a href="{{ url('/') }}" class="link-item">Landing page</a>
            <a href="{{ url('konsultasi') }}" class="link-item">Konsultasi</a>
            <a href="{{ url('chatbot') }}" class="link-item">Chatbot</a>
            <a href="https://perpustakaan.bps.go.id/opac/" target="_blank" rel="noopener" class="link-item">Katalog Publikasi <i class="bi bi-box-arrow-up-right ms-1"></i></a>
            <a href="{{ url('profile') }}" class="link-item active-link">Profil Saya</a>
            <a href="{{ url('logout') }}" class="link-item">Keluar</a>
        </div>
    </div>

    <div class="sidebar-dark-overlay" id="sidebarOverlay"></div>



    <!-- =====================FOOTER======================= -->
    <footer class="footer">
        <div class="container">
            <div class="row gy-4">
                <div class="col-lg-7 d-flex flex-column justify-content-between">
                    <div>
                        <img src="{{ url('./img/pst digital.png') }}" alt="pst Logo" class="footer-logo" />
                        <div class="footer-social-icons">
                            <svg class="social-icon" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" stroke="#EFEFEF" stroke-width="2" />
                                <path
                                    d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"
                                    stroke="#EFEFEF" stroke-width="2" />
                            </svg>
                            <svg class="social-icon" viewBox="0 0 21 17">
                                <path
                                    d="M20.92 2.01C20.15 2.35 19.32 2.58 18.46 2.69C19.34 2.16 20.02 1.32 20.34 0.31C19.51 0.81 18.59 1.16 17.62 1.36C16.83 0.5 15.72 0 14.46 0C12.11 0 10.19 1.92 10.19 4.29C10.19 4.63 10.23 4.96 10.3 5.27C6.74 5.09 3.57 3.38 1.46 0.79C1.09 1.42 0.88 2.16 0.88 2.94C0.88 4.43 1.63 5.75 2.79 6.5C2.08 6.5 1.42 6.3 0.84 6V6.03C0.84 8.11 2.32 9.85 4.28 10.24C3.65 10.41 2.98 10.44 2.34 10.31C2.88 12.01 4.48 13.26 6.35 13.29C4.89 14.45 3.06 15.13 1.08 15.13C0.72 15.13 0.36 15.11 0 15.07C1.9 16.29 4.16 17 6.58 17C14.46 17 18.79 10.46 18.79 4.79C18.79 4.6 18.79 4.42 18.78 4.23C19.62 3.63 20.34 2.88 20.92 2.01Z"
                                    fill="#EFEFEF" />
                            </svg>
                            <svg class="social-icon" viewBox="0 0 20 14">
                                <path
                                    d="M19.59 2.19C19.37 1.36 18.7 0.69 17.87 0.47C16.32 0 10 0 10 0C10 0 3.68 0 2.13 0.47C1.3 0.69 0.63 1.36 0.41 2.19C0 3.74 0 7 0 7C0 7 0 10.26 0.41 11.81C0.63 12.64 1.3 13.31 2.13 13.53C3.68 14 10 14 10 14C10 14 16.32 14 17.87 13.53C18.7 13.31 19.59 11.81 19.59 11.81C20 10.26 20 7 20 7C20 7 20 3.74 19.59 2.19ZM8 10V4L13 7L8 10Z"
                                    fill="#EFEFEF" />
                            </svg>
                            <svg class="social-icon" viewBox="0 0 20 20">
                                <circle cx="10" cy="10" r="3.5" stroke="#EFEFEF" stroke-width="2" />
                                <rect x="1" y="1" width="18" height="18" rx="5" stroke="#EFEFEF"
                                    stroke-width="2" />
                                <circle cx="15.5" cy="4.5" r="1" fill="#EFEFEF" />
                            </svg>
                            <svg class="social-icon" viewBox="0 0 10.5 20">
                                <path
                                    d="M9.5 11.5H7V20H3V11.5H0V7.5H3V4.74C3 1.55 4.93 0 7.82 0C9.24 0 10.74 0.29 10.74 0.29V3.5H9.08C7.45 3.5 7 4.49 7 5.5V7.5H10.5L9.5 11.5Z"
                                    fill="#EFEFEF" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="mb-0 opacity-75" style="font-size: 13px; line-height: 1.4">
                            Jika anda mengalami kendala saat melakukan penjadwalan
                            Konsultasi dan Chatbot BPS Sumatera Selatan, Silahkan
                            menghubungi admin pelayanan kami di nomor
                            <a href="https://wa.me/6281333783458"
                                style="color: #efefef; text-decoration: underline">Whatsapp 0813-3378-3458</a>
                        </p>
                    </div>
                    <p class="mb-0 mt-4 opacity-75">
                        © 2022 BPS Provinsi Sumatera Selatan. All rights reserved.
                    </p>
                </div>

                <div class="col-lg-5">
                    <div class="row">
                        <div class="col-6">
                            <div class="footer-title">LAYANAN</div>
                            <a href="{{ url('konsultasi') }}" class="footer-link">Konsultasi Virtual</a>
                            <a href="{{ url('chatbot') }}" class="footer-link">Chatbot</a>
                            <a href="https://perpustakaan.bps.go.id/opac/" class="footer-link">Katalog Publikasi</a>
                        </div>
                        <div class="col-6">
                            <div class="footer-title">BANTUAN</div>
                            <a href="#" class="footer-link">Kebijakan Privasi</a>
                            <a href="#" class="footer-link">Syarat Penggunaan</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <script src="{{ url('./bootstrap.bundle.min.js') }}"></script>
    <script src="{{ url('zanex/js/jquery.min.js') }}"></script>
    <script src="{{ url('zanex/plugins/select2/select2.full.min.js') }}"></script>
    <script>
        // document.getElementById("sidebar-container").innerHTML = data;
        // Inisialisasi logika tombol setelah HTML berhasil dimuat
        const sidebar = document.getElementById("sidebarMenu");
        const overlay = document.getElementById("sidebarOverlay");
        const openBtn = document.querySelector(".profile-icon");
        const closeBtn = document.getElementById("closeSidebarBtn");

        const closeMenu = () => {
            sidebar?.classList.remove("active");
            overlay?.classList.remove("active");
        };

        if (openBtn && sidebar && overlay) {
            openBtn.onclick = (e) => {
                e.preventDefault();
                sidebar.classList.add("active");
                overlay.classList.add("active");
            };
        }

        closeBtn?.addEventListener("click", closeMenu);
        overlay?.addEventListener("click", closeMenu);
        // Memanggil file sidebar.html
        // fetch("sidebar.html")
        //     .then((response) => response.text())
        //     .then((data) => {

        //     });
    </script>
    @yield('script')
</body>

</html>
