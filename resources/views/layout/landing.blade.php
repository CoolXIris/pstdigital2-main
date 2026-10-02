<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description"
            content="An impressive and flawless site template that includes various UI elements and countless features, attractive ready-made blocks and rich pages, basically everything you need to create a unique and professional website.">
        <meta name="keywords"
            content="bootstrap 5, business, corporate, creative, gulp, marketing, minimal, modern, multipurpose, one page, responsive, saas, sass, seo, startup, html5 template, site template">
        <meta name="author" content="elemis">
        <meta name="google-signin-client_id" content="733905232176-epggeag5a7c798h5i833o83fut1uss52.apps.googleusercontent.com">
        <title>PSTDIGITAL</title>
        <link rel="shortcut icon" href="./sandbox/img/favicon.png">
        <link rel="stylesheet" href="./sandbox/css/plugins.css">
        <link rel="stylesheet" href="./sandbox/css/style.css">
        <meta name="google-site-verification" content="mFNHxUF7OSLc_hByYKDSud0Sj78fS9xYGdxlRAerPKw" />
        @yield('style')
    </head>

    <body>
        <div class="content-wrapper">
            <header class="wrapper bg-soft-primary">
                <nav class="navbar navbar-expand-lg center-nav transparent position-absolute navbar-dark caret-none">
                    <div class="container flex-lg-row flex-nowrap align-items-center">
                        <div class="navbar-brand w-100">
                            <a href="{{ url('/') }}">
                                <img class="logo-dark" src="./sandbox/img/logo.png" srcset="./sandbox/img/logo@2x.png 12x" style="max-width: 150px"
                                    alt="" />
                                <img class="logo-dark" src="./sandbox/img/logo_bps_long.png" srcset="./sandbox/img/logo_bps_long.png 12x"
                                    style="max-width: 150px" alt="" />
                                <img class="logo-dark" src="./CBQAISO9001.png" srcset="./CBQAISO9001.png 12x"
                                    style="max-width: 150px; max-height:80px" alt="" />

                                <img class="logo-light" src="./sandbox/img/logo-light.png" srcset="./sandbox/img/logo-light@2x.png 12x"
                                    style="max-width: 150px" alt="" />
                                <img class="logo-light" src="./sandbox/img/logo_bps_long.png" srcset="./sandbox/img/logo_bps_long.png 12x"
                                    style="max-width: 150px" alt="" />
                                <img class="logo-light" src="./CBQAISO9001.png" srcset="./CBQAISO9001.png 12x"
                                    style="max-width: 150px; max-height:80px" alt="" />
                            </a>
                        </div>
                        <div class="navbar-collapse offcanvas offcanvas-nav offcanvas-start">
                            <div class="offcanvas-header d-lg-none d-xl-none">
                                <a href="{{ url('/') }}"><img src="./sandbox/img/logo-light.png" srcset="./sandbox/img/logo-light@2x.png 2x"
                                        style="max-width: 150px" alt="" /></a>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                            </div>
                            <div class="offcanvas-body ms-lg-auto d-flex flex-column h-100">
                                <ul class="navbar-nav">
                                    {{-- <li class="nav-item ">
                                    <a class="nav-link" href="{{ url('/') }}">Home</a>
                                </li>
                                <li class="nav-item ">
                                    <a class="nav-link" href="{{ url('/konsultasi') }}">Konsultasi</a>
                                </li>
                                <li class="nav-item ">
                                    <a class="nav-link" href="#">Katalog Buku</a>
                                </li>
                                <li class="nav-item ">
                                    <a class="nav-link" href="#">Chat Bot</a>
                                </li> --}}

                                </ul>
                                <!-- /.navbar-nav -->
                                <div class="d-lg-none mt-auto pt-6 pb-6 order-4">
                                    <a href="mailto:first.last@email.com" class="link-inverse">info@email.com</a>
                                    <br /> 00 (123) 456 78 90 <br />
                                    <nav class="nav social social-white mt-4">
                                        <a href="#"><i class="uil uil-twitter"></i></a>
                                        <a href="#"><i class="uil uil-facebook-f"></i></a>
                                        <a href="#"><i class="uil uil-dribbble"></i></a>
                                        <a href="#"><i class="uil uil-instagram"></i></a>
                                        <a href="#"><i class="uil uil-youtube"></i></a>
                                    </nav>
                                    <!-- /.social -->
                                </div>
                                <!-- /offcanvas-nav-other -->
                            </div>
                            <!-- /.offcanvas-body -->
                        </div>
                        <!-- /.navbar-collapse -->
                        <div class="navbar-other w-100 d-flex ms-auto">
                            <ul class="navbar-nav flex-row align-items-center ms-auto">
                                <li class="nav-item ">
                                    <a class="nav-link" href="#">Tentang</a>
                                </li>
                                @if (Auth::user())
                                    <li class="nav-item dropdown language-select text-uppercase">
                                        <a class="nav-link dropdown-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                                            aria-haspopup="true" aria-expanded="false">
                                            Halo {{ Auth::user()->name }}
                                        </a>
                                        <ul class="dropdown-menu">
                                            <li class="nav-item">
                                                <a class="dropdown-item" href="{{ route('profile', ['id' => Auth::user()->id]) }}">Profile</a>
                                            </li>
                                            <li class="nav-item"><a class="dropdown-item" href="{{ route('logout') }}">Logout</a></li>
                                        </ul>
                                    </li>
                                @endif
                                @if (!Auth::user())
                                    <li class="nav-item ">
                                        <a class="nav-link" href="{{ url('/login') }}">Konsultasi Sekarang</a>
                                    </li>
                                @endif
                                {{-- <li class="nav-item"><a class="nav-link" data-bs-toggle="offcanvas"
                                    data-bs-target="#offcanvas-info"><i class="uil uil-info-circle"></i></a></li> --}}
                                <li class="nav-item d-lg-none">
                                    <button class="hamburger offcanvas-nav-btn"><span></span></button>
                                </li>
                            </ul>
                            <!-- /.navbar-nav -->
                        </div>
                        <!-- /.navbar-other -->
                    </div>

                </nav>
                <!-- /.navbar -->
                <div class="offcanvas offcanvas-end text-inverse" id="offcanvas-info" data-bs-scroll="true">
                    <div class="offcanvas-header">
                        <a href="{{ url('/') }}"><img src="./sandbox/img/logo-light.png" srcset="./sandbox/img/logo-light@2x.png 10x"
                                alt="" style="max-width: 150px" /></a>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                    </div>
                    <div class="offcanvas-body">
                        <div class="widget mb-8">
                            <p>PST DIGITAL adalah platform...</p>
                        </div>

                        <div class="widget mb-8">
                            <h4 class="widget-title text-white mb-3">Contact Info</h4>
                            <address> Jl. Kapten Anwar Sastro <br /> Palembang, Sumatera Selatan </address>
                            <a href="mailto:first.last@email.com">info@email.com</a><br /> 00 (123) 456 78 90
                        </div>

                        <div class="widget mb-8">
                            <h4 class="widget-title text-white mb-3">Learn More</h4>
                            <ul class="list-unstyled">
                                <li><a href="#">Our Story</a></li>
                                <li><a href="#">Terms of Use</a></li>
                                <li><a href="#">Privacy Policy</a></li>
                                <li><a href="#">Contact Us</a></li>
                            </ul>
                        </div>

                        <div class="widget">
                            <h4 class="widget-title text-white mb-3">Follow Us</h4>
                            <nav class="nav social social-white">
                                <a href="#"><i class="uil uil-twitter"></i></a>
                                <a href="#"><i class="uil uil-facebook-f"></i></a>
                                <a href="#"><i class="uil uil-dribbble"></i></a>
                                <a href="#"><i class="uil uil-instagram"></i></a>
                                <a href="#"><i class="uil uil-youtube"></i></a>
                            </nav>
                            <!-- /.social -->
                        </div>

                    </div>
                    <!-- /.offcanvas-body -->
                </div>
                <!-- /.offcanvas -->
            </header>

            @yield('content')
        </div>
        <!-- /.content-wrapper -->
        <footer class="bg-dark text-inverse">
            <div class="container py-13 py-md-15">
                <div class="row gy-6 gy-lg-0">
                    <div class="col-lg-3">
                        <div class="widget">
                            <img class="mb-4" src="./sandbox/img/logo-light.png" srcset="./sandbox/img/logo-light@2x.png 8x"
                                style="max-width: 150px" alt="" />
                            <p class="mb-4">© 2022 BPS Provinsi Sumatera Selatan. All rights reserved.</p>
                            <nav class="nav social social-white">
                                <a href="#"><i class="uil uil-twitter"></i></a>
                                <a href="#"><i class="uil uil-facebook-f"></i></a>
                                <a href="#"><i class="uil uil-dribbble"></i></a>
                                <a href="#"><i class="uil uil-instagram"></i></a>
                                <a href="#"><i class="uil uil-youtube"></i></a>
                            </nav>
                            <!-- /.social -->
                        </div>

                    </div>

                    <div class="col-md-4 col-lg-2 offset-lg-2">
                        <div class="widget">
                            <h4 class="widget-title mb-3 text-white">Need Help?</h4>
                            <ul class="list-unstyled mb-0">
                                {{-- <li><a href="#">Support</a></li>
                            <li><a href="#">Get Started</a></li> --}}
                                <li><a href="{{ url('term.html') }}">Terms of Use</a></li>
                                <li><a href="{{ url('privacy.html') }}">Privacy Policy</a></li>
                            </ul>
                        </div>

                    </div>

                    <div class="col-md-4 col-lg-2">
                        <div class="widget">
                            <h4 class="widget-title mb-3 text-white">Get in Touch</h4>
                            <address>Palembang, Jl. Kapten Anwar Sastro, 081333783485(Whatapps)</address>
                            <a href="mailto:lapakstatistik16@gmail.com">lapakstatistik16@gmail.com</a><br />
                        </div>

                    </div>
                    <div class="col-md-4 col-lg-3">
                        <div class="widget">
                            <p> Jika anda mengalami kendala saat melakukan penjadwalan Konsultasi dan Chatbot BPS Sumatera Selatan, Silahkan
                                menghubungi admin pelayanan kami di nomor Whatsapp 0813-3378-3458</p>
                        </div>

                    </div>
                </div>

            </div>

        </footer>
        <div class="progress-wrap">
            <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102">
                <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" />
            </svg>
        </div>
        <script src="./sandbox/js/plugins.js"></script>
        <script src="./sandbox/js/theme.js"></script>
        <script src="https://apis.google.com/js/platform.js" async defer></script>
    </body>

</html>
