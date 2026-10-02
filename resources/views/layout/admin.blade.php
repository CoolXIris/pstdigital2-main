<!doctype html>
<html lang="en" dir="ltr">

    <head>
        <meta charset="UTF-8">
        <meta name='viewport' content='width=device-width, initial-scale=1.0, user-scalable=0'>
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="description" content="Zanex – Bootstrap  Admin & Dashboard Template">
        <meta name="author" content="Spruko Technologies Private Limited">
        <meta name="keywords"
            content="admin, dashboard, dashboard ui, admin dashboard template, admin panel dashboard, admin panel html, admin panel html template, admin panel template, admin ui templates, administrative templates, best admin dashboard, best admin templates, bootstrap 4 admin template, bootstrap admin dashboard, bootstrap admin panel, html css admin templates, html5 admin template, premium bootstrap templates, responsive admin template, template admin bootstrap 4, themeforest html">
        <link rel="shortcut icon" type="image/x-icon" href="{{ url('zanex/images/brand/favicon.ico') }}" />
        {{-- <meta name="google-signin-client_id"
        content="733905232176-epggeag5a7c798h5i833o83fut1uss52.apps.googleusercontent.com"> --}}

        <!-- TITLE -->
        <title>PST DIGITAL</title>

        <!-- BOOTSTRAP CSS -->
        <link id="style" href="{{ url('zanex/plugins/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet" />

        <!-- STYLE CSS -->
        <link href="{{ url('zanex/css/style.css') }}" rel="stylesheet" />
        <link href="{{ url('zanex/css/dark-style.css') }}" rel="stylesheet" />
        <link href="{{ url('zanex/css/skin-modes.css') }}" rel="stylesheet" />
        <link href="{{ url('zanex/css/transparent-style.css') }}" rel="stylesheet" />

        <!--- FONT-ICONS CSS -->
        <link href="{{ url('zanex/css/icons.css') }}" rel="stylesheet" />

        <!-- COLOR SKIN CSS -->
        <link id="theme" rel="stylesheet" type="text/css" media="all" href="{{ url('zanex/colors/color1.css') }}" />
        <style>
            .btn_edit {
                cursor: pointer;
            }

            .btn_hapus {
                cursor: pointer;
            }

            .btn_rating {
                cursor: pointer;
            }
        </style>
        @yield('css')
    </head>

    <body class="app sidebar-mini ltr light-mode">

        <!-- GLOBAL-LOADER -->
        <div id="global-loader">
            <img src="{{ url('zanex/images/loader.svg') }}" class="loader-img" alt="Loader">
        </div>
        <!-- /GLOBAL-LOADER -->

        <!-- PAGE -->
        <div class="page">
            <div class="page-main">

                <!-- app-Header -->
                <div class="app-header header sticky">
                    <div class="container-fluid main-container">
                        <div class="d-flex align-items-center">
                            <a aria-label="Hide Sidebar" class="app-sidebar__toggle" data-bs-toggle="sidebar" href="javascript:void(0);"></a>
                            <div class="responsive-logo">
                                <a href="index.html" class="header-logo">
                                    <img src="{{ url('zanex/images/brand/logo-3.png') }}" class="mobile-logo logo-1" alt="logo"
                                        style="max-height:40px">
                                    <img src="{{ url('zanex/images/brand/logo-light.png') }}" class="mobile-logo dark-logo-1" alt="logo"
                                        style="max-height:40px">
                                </a>
                            </div>
                            <!-- sidebar-toggle-->
                            <a class="logo-horizontal " href="index.html">
                                <img src="{{ url('zanex/images/brand/logo.png') }}" class="header-brand-img desktop-logo" alt="logo">
                                <img src="{{ url('zanex/images/brand/logo-3.png') }}" class="header-brand-img light-logo1" alt="logo">
                            </a>
                            <!-- LOGO -->
                            <div class="d-flex order-lg-2 ms-auto header-right-icons">
                                <!-- SEARCH -->
                                <button class="navbar-toggler navresponsive-toggler d-lg-none ms-auto" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#navbarSupportedContent-4" aria-controls="navbarSupportedContent-4" aria-expanded="false"
                                    aria-label="Toggle navigation">
                                    <span class="navbar-toggler-icon fe fe-more-vertical text-dark"></span>
                                </button>
                                <div class="navbar navbar-collapse responsive-navbar p-0">
                                    <div class="collapse navbar-collapse" id="navbarSupportedContent-4">
                                        <div class="d-flex order-lg-2">

                                            <div class="dropdown d-md-flex">
                                                <a class="nav-link icon theme-layout nav-link-bg layout-setting">
                                                    <span class="dark-layout"><i class="fe fe-moon"></i></span>
                                                    <span class="light-layout"><i class="fe fe-sun"></i></span>
                                                </a>
                                            </div>
                                            <!-- Theme-Layout -->
                                            <div class="dropdown d-md-flex">
                                                <a class="nav-link icon full-screen-link nav-link-bg">
                                                    <i class="fe fe-minimize fullscreen-button" id="myvideo"></i>
                                                </a>
                                            </div>
                                            <!-- FULL-SCREEN -->
                                            <div class="dropdown d-md-flex profile-1">
                                                <a href="javascript:void(0);" class="nav-link leading-none d-flex px-1">
                                                    <span>
                                                        <img src="{{ url('zanex/images/users/8.jpg') }}" alt="profile-user"
                                                            class="avatar  profile-user brround cover-image">
                                                    </span>
                                                </a>
                                            </div>
                                            <div class="dropdown d-md-flex header-settings">
                                                <a href="javascript:void(0);" class="nav-link icon " data-bs-toggle="sidebar-right"
                                                    data-target=".sidebar-right">
                                                    <i class="fe fe-menu"></i>
                                                </a>
                                            </div>
                                            <!-- SIDE-MENU -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- /app-Header -->

                <!--APP-SIDEBAR-->
                <div class="sticky">
                    <div class="app-sidebar__overlay" data-bs-toggle="sidebar"></div>
                    <aside class="app-sidebar">
                        <div class="side-header">
                            <a class="header-brand1" href="index.html">
                                <img src="{{ url('zanex/images/brand/logo-light.png') }}" class="header-brand-img desktop-logo" alt="logo">
                                <img src="{{ url('zanex/images/brand/logo-light.png') }}" class="header-brand-img toggle-logo" alt="logo">
                                <img src="{{ url('zanex/images/brand/logo-2.png') }}" class="header-brand-img light-logo" alt="logo">
                                <img src="{{ url('zanex/images/brand/logo-3.png') }}" class="header-brand-img light-logo1" alt="logo">
                            </a>
                            <!-- LOGO -->
                        </div>
                        <div class="main-sidemenu">
                            <div class="slide-left disabled" id="slide-left"><svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191"
                                    width="24" height="24" viewBox="0 0 24 24">
                                    <path d="M13.293 6.293 7.586 12l5.707 5.707 1.414-1.414L10.414 12l4.293-4.293z" />
                                </svg></div>
                            <ul class="side-menu">
                                <li class="sub-category">
                                    <h3>Main</h3>
                                </li>
                                <li class="slide">
                                    <a class="side-menu__item @if (Request::is('/')) active @endif" data-bs-toggle="slide"
                                        href="{{ route('/') }}">
                                        <i class="side-menu__icon fe fe-home"></i>
                                        <span class="side-menu__label">Landing</span>
                                    </a>
                                </li>

                                <li class="slide">
                                    <a class="side-menu__item @if (Request::is('konsultasi*')) active @endif" data-bs-toggle="slide"
                                        href="{{ url('konsultasi') }}">
                                        <i class="side-menu__icon fe fe-video"></i>
                                        <span class="side-menu__label">Konsultasi</span>
                                    </a>
                                </li>
                                <li class="slide">
                                    <a class="side-menu__item @if (Request::is('chatbot*')) active @endif" data-bs-toggle="slide"
                                        href="{{ url('chatbot') }}">
                                        <i class="side-menu__icon fa fa-comments-o"></i>
                                        <span class="side-menu__label">chatbot</span>
                                    </a>
                                </li>
                                <li class="slide">
                                    <a class="side-menu__item @if (Request::is('katalog*')) active @endif" data-bs-toggle="slide"
                                        href="{{ url('https://perpustakaan.bps.go.id/opac') }}">
                                        <i class="side-menu__icon fa fa-book"></i>
                                        <span class="side-menu__label">Katalog Publikasi</span>
                                    </a>
                                </li>
                                @role('admin|super_admin')
                                    <li class="sub-category">
                                        <h3>Admin</h3>
                                    </li>

                                    <li class="slide">
                                        <a class="side-menu__item @if (Request::is('dashboard*')) active @endif" data-bs-toggle="slide"
                                            href="{{ route('dashboard') }}">
                                            <i class="side-menu__icon fe fe-pie-chart"></i>
                                            <span class="side-menu__label">Dashboard</span>
                                        </a>
                                    </li>

                                    <li>
                                        <a class="side-menu__item @if (Request::is('users*')) active @endif" href="{{ route('users.index') }}">
                                            <i class="side-menu__icon fe fe-user"></i>
                                            <span class="side-menu__label">Users</span>
                                        </a>
                                    </li>
                                @endrole
                                @role('super_admin')
                                    <li>
                                        <a class="side-menu__item @if (Request::is('roles*')) active @endif" href="{{ route('roles.index') }}">
                                            <i class="side-menu__icon fe fe-user-check"></i>
                                            <span class="side-menu__label">Roles</span>
                                        </a>
                                    </li>
                                @endrole

                            </ul>
                            <div class="slide-right" id="slide-right"><svg xmlns="http://www.w3.org/2000/svg" fill="#7b8191" width="24"
                                    height="24" viewBox="0 0 24 24">
                                    <path d="M10.707 17.707 16.414 12l-5.707-5.707-1.414 1.414L13.586 12l-4.293 4.293z" />
                                </svg></div>
                        </div>
                    </aside>
                </div>
                <!--/APP-SIDEBAR-->

                <!--app-content open-->
                <div class="main-content app-content mt-0">
                    @yield('content')
                </div>
                <!--app-content end-->
            </div>

            <div class="sidebar sidebar-right sidebar-animate">
                <div class="panel panel-primary card mb-0 shadow-none border-0">
                    <div class="tab-menu-heading border-0 d-flex p-3">
                        <div class="card-title mb-0">Notifications</div>
                        <div class="card-options ms-auto">
                            <a href="javascript:void(0);" class="sidebar-icon text-end float-end me-1" data-bs-toggle="sidebar-right"
                                data-target=".sidebar-right"><i class="fe fe-x text-white"></i></a>
                        </div>
                    </div>
                    <div class="panel-body tabs-menu-body latest-tasks p-0 border-0">
                        <div class="tabs-menu border-bottom">
                            <!-- Tabs -->
                            <ul class="nav panel-tabs">
                                <li class=""><a href="#side1" class="active" data-bs-toggle="tab"><i class="fe fe-user me-1"></i>
                                        Profile</a></li>
                            </ul>
                        </div>
                        <div class="tab-content">
                            <div class="tab-pane active" id="side1">
                                <div class="card-body text-center">
                                    <div class="dropdown user-pro-body">
                                        <div class="">
                                            <img alt="user-img" class="avatar avatar-xl brround mx-auto text-center"
                                                src="{{ url('zanex/images/faces/6.jpg') }}"><span
                                                class="avatar-status profile-status bg-green"></span>
                                        </div>
                                        <div class="user-info mg-t-20">
                                            <h6 class="fw-semibold  mt-2 mb-0">{{ Auth::user()->name }}</h6>
                                            <span class="mb-0 text-muted fs-12">{{ Auth::user()->getRoleNames()->first() ?? 'user' }}</span>
                                        </div>
                                    </div>
                                </div>
                                <a class="dropdown-item d-flex border-bottom border-top" href="{{ url('profile') }}">
                                    <div class="d-flex"><i class="fe fe-user me-3 tx-20 text-muted"></i>
                                        <div class="pt-1">
                                            <h6 class="mb-0">My Profile</h6>
                                            <p class="tx-12 mb-0 text-muted">Profile Personal information</p>
                                        </div>
                                    </div>
                                </a>

                                <a class="dropdown-item d-flex border-bottom" href="{{ route('logout') }}">
                                    <div class="d-flex"><i class="fe fe-power me-3 tx-20 text-muted"></i>
                                        <div class="pt-1">
                                            <h6 class="mb-0">Sign Out</h6>
                                            <p class="tx-12 mb-0 text-muted">Account Signout</p>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!--/Sidebar-right-->

            <!-- FOOTER -->
            <footer class="footer">
                <div class="container">
                    <div class="row align-items-center flex-row-reverse">
                        <div class="col-md-12 col-sm-12 text-center">
                            Copyright © 2022 <a href="javascript:void(0);">PST DIGITAL</a>. By <a href="javascript:void(0);"> Boms </a>
                            All rights reserved
                        </div>
                        <div class="col-md-12 col-sm-12 text-center">
                            <p> Jika anda mengalami kendala saat melakukan penjadwalan Konsultasi dan Chatbot BPS Sumatera Selatan, Silahkan
                                menghubungi admin pelayanan kami di nomor Whatsapp 0813-3378-3458</p>
                        </div>
                    </div>
                </div>
            </footer>
            <!-- FOOTER END -->
        </div>

        <!-- BACK-TO-TOP -->
        <a href="#top" id="back-to-top"><i class="fa fa-angle-up"></i></a>

        <!-- JQUERY JS -->
        <script src="{{ url('zanex/js/jquery.min.js') }}"></script>

        <!-- BOOTSTRAP JS -->
        <script src="{{ url('zanex/plugins/bootstrap/js/popper.min.js') }}"></script>
        <script src="{{ url('zanex/plugins/bootstrap/js/bootstrap.min.js') }}"></script>

        <!-- SPARKLINE JS-->
        <script src="{{ url('zanex/js/jquery.sparkline.min.js') }}"></script>

        <!-- CHART-CIRCLE JS-->
        <script src="{{ url('zanex/js/circle-progress.min.js') }}"></script>

        <!-- CHARTJS CHART JS-->
        <script src="{{ url('zanex/plugins/chart/Chart.bundle.js') }}"></script>
        <script src="{{ url('zanex/plugins/chart/utils.js') }}"></script>

        <!-- PIETY CHART JS-->
        <script src="{{ url('zanex/plugins/peitychart/jquery.peity.min.js') }}"></script>
        <script src="{{ url('zanex/plugins/peitychart/peitychart.init.js') }}"></script>

        <!-- INTERNAL SELECT2 JS -->
        <script src="{{ url('zanex/plugins/select2/select2.full.min.js') }}"></script>

        <!-- INTERNAL Data tables js-->
        <script src="{{ url('zanex/plugins/datatable/js/jquery.dataTables.min.js') }}"></script>
        <script src="{{ url('zanex/plugins/datatable/js/dataTables.bootstrap5.js') }}"></script>
        <script src="{{ url('zanex/plugins/datatable/dataTables.responsive.min.js') }}"></script>

        <!-- ECHART JS-->
        <script src="{{ url('zanex/plugins/echarts/echarts.js') }}"></script>

        <!-- SIDE-MENU JS-->
        <script src="{{ url('zanex/plugins/sidemenu/sidemenu.js') }}"></script>

        <!-- Sticky js -->
        <script src="{{ url('zanex/js/sticky.js') }}"></script>

        <script src="{{ url('zanex/plugins/sweet-alert/sweetalert.min.js') }}"></script>
        <!-- SIDEBAR JS -->
        <script src="{{ url('zanex/plugins/sidebar/sidebar.js') }}"></script>

        <!-- Perfect SCROLLBAR JS-->
        <script src="{{ url('zanex/plugins/p-scroll/perfect-scrollbar.js') }}"></script>
        <script src="{{ url('zanex/plugins/p-scroll/pscroll.js') }}"></script>
        <script src="{{ url('zanex/plugins/p-scroll/pscroll-1.js') }}"></script>

        <!-- APEXCHART JS -->
        <script src="{{ url('zanex/js/apexcharts.js') }}"></script>

        <!-- INDEX JS -->
        <script src="{{ url('zanex/js/index1.js') }}"></script>

        <!-- Color Theme js -->
        <script src="{{ url('zanex/js/themeColors.js') }}"></script>

        <!-- CUSTOM JS -->
        <script src="{{ url('zanex/js/custom.js') }}"></script>
        @yield('script')
    </body>

</html>
