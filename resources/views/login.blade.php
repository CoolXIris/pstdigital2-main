@extends('layout.layout')

@section('content')
    <div class="hero-background">
        <div class="hero-bg-image">
            <img src="{{ url('./img/login1.png') }}" alt="Background" />
        </div>
        <div class="hero-overlay"></div>

        <div class="login-container">
            <div class="login-card">
                <div class="login-image">
                    <img src="{{ url('./img/login 2.png') }}" alt="BPS Office" />
                </div>

                <div class="login-form-section">
                    <div class="text-center w-100" style="max-width: 400px">
                        <div class="lock-icon mx-auto">
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                                <path
                                    d="M25.3334 14.6667H6.66669C5.19393 14.6667 4.00002 15.8606 4.00002 17.3334V26.6667C4.00002 28.1395 5.19393 29.3334 6.66669 29.3334H25.3334C26.8061 29.3334 28 28.1395 28 26.6667V17.3334C28 15.8606 26.8061 14.6667 25.3334 14.6667Z"
                                    stroke="white" stroke-width="2.6" />
                                <path
                                    d="M10.6666 14.6667V9.33335C10.6666 7.56524 11.3691 5.86955 12.6193 4.61931C13.8696 3.36907 15.5652 2.66669 17.3333 2.66669C19.1014 2.66669 20.7971 3.36907 22.0474 4.61931C23.2976 5.86955 24 7.56524 24 9.33335V14.6667"
                                    stroke="white" stroke-width="2.6" />
                            </svg>
                        </div>
                        <h2 class="fw-bold h4 mb-2">Selamat Datang</h2>
                        <p class="text-muted mb-4">Masuk dengan akun Google Anda</p>

                        @if (session('error'))
                            <div class="alert alert-danger w-100" role="alert">
                                {{ session('error') }}
                            </div>
                        @endif

                        <a class="google-button" href="{{ route('google_login') }}">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" width="20" />
                            <span class="fw-bold" style="color: #364153">Masuk dengan Google</span>
                        </a>

                        <div class="mt-4 pt-3 border-top">
                            <p style="font-size: 13px; color: #6a7282">
                                Dengan masuk, Anda menyetujui
                                <a href="#" class="text-decoration-none" style="color: #043277">Syarat & Ketentuan</a>
                                dan
                                <a href="#" class="text-decoration-none" style="color: #043277">Kebijakan Privasi</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <style>

        /* ================= LOGIN SECTION ================= */
        .hero-background {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .hero-bg-image {
            position: absolute;
            inset: 0;
            z-index: 0;
        }

        .hero-bg-image img {
            width: 100%;
            object-fit: cover;
            align-items: center;
        }

        .hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to left,
                    rgba(30, 58, 138, 0),
                    rgba(30, 58, 138, 0.35));
            z-index: 2;
        }

        .login-container {
            position: relative;
            z-index: 10;
            width: 1120px;
            max-width: 95%;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px 0;
        }

        .login-card {
            background: white;
            border-radius: 20px;
            display: flex;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            min-height: 374px;
        }

        .login-image {
            width: 640px;
            flex-shrink: 0;
        }

        .login-image img {
            width: 100%;
            height: 128%;
            object-fit: cover;
        }

        .login-form-section {
            flex: 1;
            padding: 45px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .lock-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(to bottom, #001f3f, #004080);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .google-button {
            background-color: #efefef;
            border: 1.778px solid #d1d5dc;
            border-radius: 10px;
            padding: 14px 16px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
        }


        @media screen and (max-width: 1440px) {
            .hero-background {
                zoom: 0.85;
            }

            .footer {
                zoom: 0.85;
            }
        }

        /* ================= RESPONSIVE ADJUSTMENTS ================= */
        /* Tablet (iPad/Large Mobile) */
        @media (max-width: 991.98px) {
            .login-container {
                width: 90%;
                margin: 40px auto;
            }

            .login-card {
                flex-direction: column;
                /* Gambar pindah ke atas, form ke bawah */
                min-height: auto;
            }

            .login-image {
                width: 100%;
                height: 250px;
                /* Batasi tinggi gambar di tablet */
            }

            .login-image img {
                height: 100%;
            }

            .login-form-section {
                padding: 30px 20px;
            }
        }

        /* HP (Mobile) */
        @media (max-width: 576px) {
            .header {
                padding: 10px 15px !important;
                height: 70px;
            }

            .login-card {
                border-radius: 15px;
            }

            .login-image {
                height: 180px;
                /* Lebih kecil untuk layar HP */
            }

            .login-form-section {
                padding: 25px 15px;
            }

            .lock-icon {
                width: 50px;
                height: 50px;
            }

            .footer {
                padding: 40px 20px;
                text-align: center;
                /* Meratakan tengah teks footer di HP */
            }

            .footer-social-icons {
                justify-content: center;
                margin-top: 20px;
            }

            .footer-logo {
                height: 60px;
            }
        }
    </style>
@endsection
