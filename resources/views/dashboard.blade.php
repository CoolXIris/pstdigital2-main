@extends('layout.admin')
@section('content')
    <div class="side-app">
        <div class="main-container container-fluid">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Dashboard PST DIGITAL</h1>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="javascript:void(0);">Home</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Dashboard Utama</li>
                    </ol>
                </div>
                <div class="ms-auto pageheader-btn">
                    {{-- <a href="javascript:void(0);" class="btn btn-primary btn-icon text-white me-2">
                        <span>
                            <i class="fe fe-plus"></i>
                        </span> Add Account
                    </a>
                    <a href="javascript:void(0);" class="btn btn-success btn-icon text-white">
                        <span>
                            <i class="fe fe-log-in"></i>
                        </span> Export
                    </a> --}}
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12 col-md-12 col-sm-12 col-xl-12">
                    <div class="row">
                        <div class="col-lg-6 col-md-6 col-sm-12 col-xl-3">
                            <div class="card overflow-hidden">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col">
                                            <h6 class="">Jumlah User</h6>
                                            <h3 class="mb-2 number-font">{{ $data['jml_user'] }}</h3>
                                            <p class="text-muted mb-0">
                                                <span class="text-primary"><i class="fa fa-user-circle text-primary me-1"></i>
                                                    {{ $data['jml_admin'] }}</span> Admin
                                            </p>
                                        </div>
                                        <div class="col col-auto">
                                            <div class="counter-icon bg-primary-gradient box-shadow-primary brround ms-auto">
                                                <i class="fe fe-users text-white mb-5 "></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12 col-xl-3">
                            <div class="card overflow-hidden">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col">
                                            <h6 class="">Jumlah Konsultasi Bulan Ini</h6>
                                            <h3 class="mb-2 number-font">{{ $data['jml_meeting_bulan_ini'] }}</h3>
                                            <p class="text-muted mb-0">
                                                {{-- <span class="text-secondary"><i class="fa fa-chevron-circle-up text-secondary me-1"></i>
                                                    3%</span> last month --}}
                                            </p>
                                        </div>
                                        <div class="col col-auto">
                                            <div class="counter-icon bg-danger-gradient box-shadow-danger brround  ms-auto">
                                                <i class="fa fa-video-camera text-white mb-5 "></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12 col-xl-3">
                            <div class="card overflow-hidden">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col">
                                            <h6 class="">Jumlah Konsultasi Selesai Bulan ini</h6>
                                            <h3 class="mb-2 number-font">{{ $data['jml_meeting_selesai_bulan_ini'] }}</h3>
                                            <p class="text-muted mb-0">
                                                {{-- <span class="text-danger"><i class="fa fa-chevron-circle-down text-danger me-1"></i>
                                                    0.2%</span> last month --}}
                                            </p>
                                        </div>
                                        <div class="col col-auto">
                                            <div class="counter-icon bg-success-gradient box-shadow-success brround  ms-auto">
                                                <i class="fa fa-check-square-o text-white mb-5 "></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 col-md-6 col-sm-12 col-xl-3">
                            <div class="card overflow-hidden">
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col">
                                            <h6 class="">Jumlah Konsultasi Belum Selesai</h6>
                                            <h3 class="mb-2 number-font">{{ $data['jml_meeting_belum_selesai'] }}</h3>
                                            <p class="text-muted mb-0">
                                                {{-- <span class="text-success"><i class="fa fa-chevron-circle-down text-success me-1"></i>
                                                    0.5%</span> last month --}}
                                            </p>
                                        </div>
                                        <div class="col col-auto">
                                            <div class="counter-icon bg-secondary-gradient box-shadow-secondary brround ms-auto">
                                                <i class="fe fe-bell text-white mb-5 "></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12 col-md-12 col-lg-12 col-xl-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Grafik Konsultasi</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="chartBar1" class="h-275"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title fw-semibold ">Kritik dan Saran</h4>
                        </div>
                        <div class="card-body pb-0">
                            <ul class="task-list">
                                @foreach ($data['kritik_saran'] as $key => $kritik)
                                    <li>
                                        <i class="task-icon @if ($key % 2 == 0) bg-primary @else bg-secondary @endif "></i>
                                        <h6><i class="fa fa-star"></i> : {{ $kritik->rating }}<span
                                                class="text-muted fs-11 mx-2">{{ $kritik->tanggal }}</span>
                                        </h6>
                                        <p class="text-muted fs-12">{{ $kritik->kritik_saran }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-4 col-md-12">
                    <div class="card overflow-hidden">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">Jumlah Konsultasi Berdasarkan Jenis Konsultasi</h3>
                            </div>
                        </div>
                        <div class="card-body pb-4 pt-4">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Jenis Konsultasi</th>
                                        <th>Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td>Statistik Secara Umum</td>
                                        <td>10</td>

                                    </tr>
                                    <tr>
                                        <td>2</td>
                                        <td>Pojok Statistik</td>
                                        <td>5</td>
                                    </tr>
                                    <tr>
                                        <td>3</td>
                                        <td>Kemiskinan</td>
                                        <td>3</td>
                                    </tr>
                                    <tr>
                                        <td>4</td>
                                        <td>Sosial Kependudukan</td>
                                        <td>3</td>
                                    </tr>
                                    <tr>
                                        <td>5</td>
                                        <td>Metodologi Statisik</td>
                                        <td>2</td>
                                    </tr>
                                    <tr>
                                        <td>6</td>
                                        <td>Perkebunan</td>
                                        <td>1</td>
                                    </tr>
                                    <tr>
                                        <td>7</td>
                                        <td>Total</td>
                                        <td>24</td>
                                    </tr>
                                </tbody>
                            </table>

                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-12">
                    <div class="card overflow-hidden">
                        <div class="card-header">
                            <div>
                                <h3 class="card-title">Jumlah User berdasarkan Pekerjaan</h3>
                            </div>
                        </div>
                        <div class="card-body pb-4 pt-4">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Kategori</th>
                                        <th>Jumlah(user)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td>ASN/TNI/POLRI</td>
                                        <td>138</td>
                                    </tr>
                                    <tr>
                                        <td>2</td>
                                        <td>Mahasiswa/Pelahar</td>
                                        <td>79</td>
                                    </tr>
                                    <tr>
                                        <td>3</td>
                                        <td>Dosen/Peneliti</td>
                                        <td>11</td>
                                    </tr>
                                    <tr>
                                        <td>4</td>
                                        <td>Lainnya</td>
                                        <td>18</td>
                                    </tr>
                                    <tr>
                                        <td>5</td>
                                        <td>Total</td>
                                        <td>250</td>
                                    </tr>
                                </tbody>
                            </table>

                            {{-- <div class="activity1"> --}}
                            {{-- <div class="activity-blog">
                                    <div class="activity-img brround bg-primary-transparent text-primary">
                                        <i class="fa fa-user-plus fs-20"></i>
                                    </div>
                                    <div class="activity-details d-flex">
                                        <div><b><span class="text-dark"> Mr John </span> </b> Started
                                            following you <span class="d-flex text-muted fs-11">01 June
                                                2020</span></div>
                                        <div class="ms-auto fs-13 text-dark fw-semibold"><span class="badge bg-primary text-white">1m</span></div>
                                    </div>
                                </div> --}}

                            {{-- </div> --}}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        $(function(e) {
            var chart_data = {!! json_encode($chart_data) !!};
            var ctx = document.getElementById("chartBar1").getContext('2d');
            var myChart = new Chart(ctx, {
                type: 'bar',
                data: chart_data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        display: true
                    },
                    scales: {
                        yAxes: [{
                            ticks: {
                                beginAtZero: true,
                                // stepSize: 1,
                                fontColor: "#77778e",
                            },
                            gridLines: {
                                color: 'rgba(119, 119, 142, 0.2)'
                            }
                        }],
                        xAxes: [{
                            ticks: {
                                display: true,
                                fontColor: "#77778e",
                            },
                            gridLines: {
                                display: false,
                                color: 'rgba(119, 119, 142, 0.2)'
                            }
                        }]
                    },
                    legend: {
                        labels: {
                            fontColor: "#77778e"
                        },
                    },
                }
            });
        })
    </script>
@endsection
