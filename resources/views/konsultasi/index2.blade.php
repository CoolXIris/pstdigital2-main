@extends('layout.admin')

@section('content')
    <div class="side-app">
        <div class="main-container container-fluid">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Konsultasi</h1>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="javascript:void(0);">Home</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Konsultasi</li>
                    </ol>
                </div>
                <div class="ms-auto pageheader-btn">
                    <a href="javascript:void(0);" class="btn btn-primary btn-icon text-white me-2" data-bs-target="#tambah_modal" data-bs-toggle="modal">
                        <span>
                            <i class="fe fe-plus"></i>
                        </span> Mulai Menjadwal Konsul
                    </a>

                </div>
            </div>
            @include('layout.alert')

            <div class="row">
                <div class="col-12 col-sm-12">
                    <div class="card ">
                        <div class="card-header">
                            <h3 class="card-title mb-0">Users</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered text-nowrap mb-0">
                                    <thead class="border-top">
                                        <tr class="text-center">
                                            <th class="bg-transparent border-bottom-0 w-3">No</th>
                                            <th class="bg-transparent border-bottom-0 w-20">User</th>
                                            <th class="bg-transparent border-bottom-0 w-50">Nama Konsultasi</th>
                                            <th class="bg-transparent border-bottom-0 w-17">Waktu</th>
                                            <th class="bg-transparent border-bottom-0 w-17">Rating</th>
                                            <th class="bg-transparent border-bottom-0 w-10">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($data as $i => $dt)
                                            <tr class="border-bottom align-middle">
                                                <td class="text-muted fs-15 fw-semibold text-center align-middle">
                                                    {{ $i + 1 }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold align-middle">
                                                    {{ $dt->user->name }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold align-middle"
                                                    style="width: 10%; word-wrap: break-word; white-space:normal">
                                                    {{ $dt->name }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold text-center align-middle">
                                                    <span
                                                        class="badge @if ($dt->status == 0) bg-info @elseif($dt->status == 1) bg-success @else bg-danger @endif me-1 mb-1 mt-1">
                                                        @if ($dt->status == 0)
                                                            Belum
                                                        @elseif($dt->status == 1)
                                                            Selesai
                                                        @else
                                                            Batal
                                                        @endif
                                                    </span> <br />
                                                    {{ $dt->tanggal }} <br>
                                                    {{ $dt->start_time }} - {{ $dt->end_time }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold text-center align-middle">
                                                    @if ($dt->rating == null && $dt->status == 0)
                                                        Belum Selesai
                                                    @elseif ($user->hasrole('user') && $dt->rating == null && $dt->status == 1)
                                                        <a class="text-warning btn_rating" data-id="{{ $dt->id }}" data-name="{{ $dt->name }}"
                                                            data-rating="{{ $dt->rating }}" data-kritik_saran="{{ $dt->kritik_saran }}">
                                                            <i>Isi Penilaian</i>
                                                        </a>
                                                    @else
                                                        @php
                                                            // Konversi rating ke emoji
                                                            $emojis = [
                                                                1 => '😢', // Sedih
                                                                2 => '😕', // Kurang puas
                                                                3 => '😐', // Netral
                                                                4 => '😊', // Puas
                                                                5 => '😁', // Sangat puas
                                                            ];

                                                            $rating = $dt->rating;
                                                            $emoji = isset($emojis[$rating]) ? $emojis[$rating] : '';
                                                        @endphp

                                                        <div class="emoji-rating-display" title="Rating: {{ $rating }}/5">
                                                            @for ($i = 1; $i <= 5; $i++)
                                                                <span style="font-size: 1.2em; opacity: {{ $i <= $rating ? '1' : '0.3' }};">
                                                                    {{ $emojis[$i] }}
                                                                </span>
                                                            @endfor
                                                        </div>

                                                        @if ($dt->kritik_saran)
                                                            <div class="mt-1 small">{{ $dt->kritik_saran }}</div>
                                                        @endif
                                                    @endif

                                                </td>
                                                <td class="text-muted fs-15 fw-semibold text-center align-middle">
                                                    @if ($user->hasRole('user') && $dt->status != 1)
                                                        <a href="{{ $dt->google_meet_link }}" target="_blank" id="btn_meet_user"
                                                            data-link="{{ $dt->google_meet_link }}"> <img src="{{ url('zanex/images/logo-meet.png') }}"
                                                                style="width: 20px" />
                                                            <br>
                                                            Mulai Meet
                                                        </a>
                                                        &nbsp;
                                                    @elseif($user->hasRole('user') && $dt->status == 1)
                                                        Selesai
                                                    @endif

                                                    @role('super_admin|admin')
                                                        <a href="{{ $dt->google_meet_link }}" target="_blank"> <img
                                                                src="{{ url('zanex/images/logo-meet.png') }}" style="width: 20px" /></a>
                                                        &nbsp;
                                                        <a class="text-warning btn_edit" data-id="{{ $dt->id }}" data-name="{{ $dt->name }}"
                                                            data-deskripsi="{{ $dt->description }}" data-tanggal="{{ $dt->tanggal }}"
                                                            data-start_time = "{{ $dt->start_time }}" data-end_time = "{{ $dt->end_time }}"
                                                            data-status = "{{ $dt->status }}" data-ringkasan = "{{ $dt->ringkasan }}"
                                                            data-link_dokumentasi = "{{ $dt->link_dokumentasi }}">
                                                            <i class="fa fa-pencil"></i>
                                                        </a>
                                                        &nbsp;
                                                        <a class="text-danger btn_hapus" data-id="{{ $dt->id }}" data-name="{{ $dt->name }}"
                                                            data-deskripsi="{{ $dt->description }}" data-tanggal="{{ $dt->tanggal }}"
                                                            data-start_time = "{{ $dt->start_time }}" data-end_time = "{{ $dt->end_time }}"
                                                            data-status = "{{ $dt->status }}">
                                                            <i class="fa fa-trash"></i>
                                                        </a>
                                                    @endrole
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <br />
                                {{ $data->links() }}
                            </div>
                        </div>
                    </div>
                </div>
                <!-- COL END -->
            </div>
        </div>
    </div>

    <div class="modal fade" id="tambah_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Buat Jadwal Konsultasi Baru</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form action="konsultasi" method="POST" id="form_tambah">
                        @csrf
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="name_modal_tambah" class="col-form-label">Jenis Konsultasi:</label>
                                {{-- <input type="text" class="form-control" id="name_modal_tambah" name="name" required> --}}
                                <br>
                                <select class="form-control select2 form-select" name="name" id="name_modal_tambah" style="width: 100%">
                                    <option value="">Pilih Jenis Konsultasi</option>
                                    <option value="Statistik secara umum">Statistik secara umum</option>
                                    <option value="Rekomendasi Statistik">Rekomendasi Statistik</option>
                                    <option value="Metodologi Statistik">Metodologi Statistik</option>
                                    <option value="Statistik Sektoral">Statistik Sektoral</option>
                                    <option value="Sains data">Sains data</option>
                                    <option value="Data Spasial">Data Spasial</option>
                                    <option value="Data Ekonomi">Data Ekonomi</option>
                                    <option value="Data Sosial dan Kependudukan">Data Sosial dan Kependudukan</option>
                                    <option value="Data Pertanian">Data Pertanian</option>
                                    <option value="Potensi Desa">Potensi Desa</option>
                                    <option value="Ekspor-Impor">Ekspor-Impor</option>
                                    <option value="Harga & Inflasi">Harga & Inflasi</option>
                                    <option value="Tenaga Kerja">Tenaga Kerja</option>
                                    <option value="Kemiskinan">Kemiskinan</option>
                                    <option value="Pertumbuhan Ekonomi">Pertumbuhan Ekonomi</option>
                                    <option value="Statistik Industri">Statistik Industri</option>
                                    <option value="Statistik Produksi">Statistik Produksi</option>
                                    <option value="Indeks Pembangunan Manusia">Indeks Pembangunan Manusia</option>
                                    <option value="Data Transportasi dan Distribusi">Data Transportasi dan Distribusi</option>
                                </select>
                            </div>

                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="deskripsi_modal_tambah" class="col-form-label">Deskripsi:</label>
                                <input type="text" class="form-control" id="deskripsi_modal_tambah" name="deskripsi">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="tanggal_modal_tambah" class="col-form-label">Tanggal:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-calendar tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control fc-datepicker" id="tanggal_modal_tambah" data-date-format="yyyy-mm-dd"
                                        placeholder="YYYY-MM-DD" type="text" name="tanggal" autocomplete="off" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_mulai_modal_tambah" class="col-form-label">Waktu Mulai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_mulai_modal_tambah" placeholder="Set time" type="text"
                                        name="waktu_mulai" required>
                                </div>
                            </div>
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_selesai_modal_tambah" class="col-form-label">Waktu Selesai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_selesai_modal_tambah" placeholder="Set time" type="text"
                                        name="waktu_selesai" readonly required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12 form-group mb-2">
                                    <div class="form-group">
                                        <label class="form-label" for="status_modal_tambah">Status</label>
                                        <select name="status" class="form-control select2 form-select " data-bs-placeholder="Select Status"
                                            id="status_modal_tambah" disabled>
                                            <option label="Select Status"></option>
                                            <option value="0" selected>Belum Dilaksanakan</option>
                                            <option value="1">Selesai</option>
                                            <option value="9">Batal</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" form="form_tambah">Save changes</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="edit_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Edit Jadwal Konsultasi</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="form_edit">
                        @csrf
                        @method('PUT')
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="name_modal_edit" class="col-form-label">Jenis Konsultasi:</label>
                                {{-- <input type="text" class="form-control" id="name_modal_edit" name="name" required> --}}
                                <select class="form-control select2 form-select" name="name" id="name_modal_edit" style="width: 100%">
                                    <option value="">Pilih Jenis Konsultasi</option>
                                    <option value="Statistik secara umum">Statistik secara umum</option>
                                    <option value="Rekomendasi Statistik">Rekomendasi Statistik</option>
                                    <option value="Metodologi Statistik">Metodologi Statistik</option>
                                    <option value="Statistik Sektoral">Statistik Sektoral</option>
                                    <option value="Sains data">Sains data</option>
                                    <option value="Data Spasial">Data Spasial</option>
                                    <option value="Data Ekonomi">Data Ekonomi</option>
                                    <option value="Data Sosial dan Kependudukan">Data Sosial dan Kependudukan</option>
                                    <option value="Data Pertanian">Data Pertanian</option>
                                    <option value="Potensi Desa">Potensi Desa</option>
                                    <option value="Ekspor-Impor">Ekspor-Impor</option>
                                    <option value="Harga & Inflasi">Harga & Inflasi</option>
                                    <option value="Tenaga Kerja">Tenaga Kerja</option>
                                    <option value="Kemiskinan">Kemiskinan</option>
                                    <option value="Pertumbuhan Ekonomi">Pertumbuhan Ekonomi</option>
                                    <option value="Statistik Industri">Statistik Industri</option>
                                    <option value="Statistik Produksi">Statistik Produksi</option>
                                    <option value="Indeks Pembangunan Manusia">Indeks Pembangunan Manusia</option>
                                    <option value="Data Transportasi dan Distribusi">Data Transportasi dan Distribusi</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="deskripsi_modal_edit" class="col-form-label">Deskripsi:</label>
                                <input type="text" class="form-control" id="deskripsi_modal_edit" name="deskripsi">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="tanggal_modal_edit" class="col-form-label">Tanggal:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-calendar tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control fc-datepicker" id="tanggal_modal_edit" data-date-format="yyyy-mm-dd"
                                        placeholder="YYYY-MM-DD" type="text" name="tanggal" autocomplete="off" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_mulai_modal_edit" class="col-form-label">Waktu Mulai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_mulai_modal_edit" placeholder="Set time" type="text" name="waktu_mulai"
                                        required>
                                </div>
                            </div>
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_selesai_modal_edit" class="col-form-label">Waktu Selesai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_selesai_modal_edit" placeholder="Set time" type="text"
                                        name="waktu_selesai" readonly required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <div class="form-group">
                                    <label class="form-label" for="status_modal_edit">Status</label>
                                    <select name="status" class="form-control form-select select2" data-bs-placeholder="Select Status"
                                        id="status_modal_edit" style="width: 100%">
                                        <option label="Select Status"></option>
                                        <option value="0">Belum Dilaksanakan</option>
                                        <option value="1">Selesai</option>
                                        <option value="9">Batal</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="ringkasan_modal_edit" class="col-form-label">Ringkasan Konsultasi:</label>
                                <input type="text" class="form-control" id="ringkasan_modal_edit" name="ringkasan" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="link_modal_edit" class="col-form-label">Link Dokumentasi Konsultasi:</label>
                                <input type="text" class="form-control" id="link_modal_edit" name="link_dokumentasi" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" form="form_edit">Save changes</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hapus_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Delete Jadwal Konsultasi</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="form_hapus">
                        @csrf
                        @method('DELETE')
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="name_modal_hapus" class="col-form-label">Judul Konsultasi:</label>
                                <input type="text" class="form-control" id="name_modal_hapus" name="name" required readonly>
                            </div>

                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="deskripsi_modal_hapus" class="col-form-label">Deskripsi:</label>
                                <input type="text" class="form-control" id="deskripsi_modal_hapus" name="deskripsi" readonly>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="tanggal_modal_hapus" class="col-form-label">Tanggal:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-calendar tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control fc-datepicker" id="tanggal_modal_hapus" data-date-format="yyyy-mm-dd"
                                        placeholder="YYYY-MM-DD" type="text" name="tanggal" autocomplete="off" required readonly>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_mulai_modal_hapus" class="col-form-label">Waktu Mulai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_mulai_modal_hapus" placeholder="Set time" type="text" name="waktu_mulai"
                                        required readonly>
                                </div>
                            </div>
                            <div class="col-6 form-group mb-2">
                                <label for="waktu_selesai_modal_hapus" class="col-form-label">Waktu Selesai:</label>
                                <div class="input-group">
                                    <div class="input-group-text">
                                        <i class="fa fa-clock-o tx-16 lh-0 op-6"></i>
                                    </div>
                                    <input class="form-control" id="waktu_selesai_modal_hapus" placeholder="Set time" type="text"
                                        name="waktu_selesai" required readonly>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <div class="form-group">
                                    <label class="form-label" for="status_modal_status">Status</label>
                                    <select name="status" class="form-control form-select select2" data-bs-placeholder="Select Status"
                                        id="status_modal_hapus" disabled>
                                        <option label="Select Status"></option>
                                        <option value="0">Belum Dilaksanakan</option>
                                        <option value="1">Selesai</option>
                                        <option value="9">Batal</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger" type="submit" form="form_hapus">Hapus Data</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rating_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Nilai Konsultasi</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="form_rating">
                        @csrf
                        @method('POST')
                        <div class="row">
                            <div class="col-12 form-group mb-2">
                                <label for="name_modal_rating" class="col-form-label">Judul Konsultasi:</label>
                                <input type="text" class="form-control" id="name_modal_rating" name="name" required readonly>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                {{-- <h2>Bagaimana pengalaman Anda?</h2> --}}
                                <label class="col-form-label">Bagaimana pengalaman Anda?</label>
                                <div class="rating-container">
                                    <input type="radio" id="rate5" name="rating" value="5">
                                    <label for="rate5">😁</label>

                                    <input type="radio" id="rate4" name="rating" value="4">
                                    <label for="rate4">😊</label>

                                    <input type="radio" id="rate3" name="rating" value="3">
                                    <label for="rate3">😐</label>

                                    <input type="radio" id="rate2" name="rating" value="2">
                                    <label for="rate2">😕</label>

                                    <input type="radio" id="rate1" name="rating" value="1">
                                    <label for="rate1">😢</label>
                                </div>

                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                {{-- <h2>Bagaimana pengalaman Anda?</h2> --}}
                                <label class="col-form-label" for="kritik_modal_rating">Kritik dan Saran</label>
                                <input type="text" class="form-control" id="kritik_modal_rating" name="kritik" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-danger" type="submit" form="form_rating">Submit</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('css')
    <link rel="stylesheet" type="text/css" media="all" href="{{ url('zanex/star-rating.min.css') }}">
    <style>
        .select2-container {
            z-index: 1050;
            width: 100%;
        }

        .ui-datepicker {
            z-index: 9999 !important;
        }

        .ui-datepicker .ui-datepicker-calendar td {
            background-color: #eaeaea;
        }

        .ui-datepicker .ui-datepicker-calendar td span {
            background-color: transparent;
        }

        .ui-state-active {
            background-color: var(--primary-bg-color);
        }

        .rating-container {
            display: flex;
            flex-direction: row-reverse;
            justify-content: center;
            gap: 5px;
        }

        .rating-container input {
            display: none;
        }

        .rating-container label {
            font-size: 2.5rem;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .rating-container label:hover,
        .rating-container label:hover~label,
        .rating-container input:checked~label {
            transform: scale(1.2);
        }

        /* Warna untuk emoji yang dipilih dan sebelumnya */
        .rating-container input:checked~label,
        .rating-container input:checked+label~label {
            opacity: 1;
        }

        /* Warna untuk emoji yang belum dipilih */
        .rating-container label {
            opacity: 0.5;
        }
    </style>
@endsection
@section('script')
    <script src="./zanex/plugins/time-picker/jquery.timepicker.js"></script>
    <script src="./zanex/plugins/bootstrap-datepicker/bootstrap-datepicker.js"></script>
    <script src="./zanex/plugins/date-picker/date-picker.js"></script>
    <script src="./zanex/plugins/date-picker/jquery-ui.js"></script>
    <script src="./zanex/plugins/input-mask/jquery.maskedinput.js"></script>
    <script src="./zanex/star-rating.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.btn_edit').click(function() {
                let id = $(this).data('id');
                $('#name_modal_edit').val($(this).data('name')).trigger('change');;
                $('#deskripsi_modal_edit').val($(this).data('deskripsi'));
                $('#tanggal_modal_edit').val($(this).data('tanggal'));
                $('#waktu_mulai_modal_edit').val($(this).data('start_time'));
                $('#waktu_selesai_modal_edit').val($(this).data('end_time'));
                $('#status_modal_edit').val($(this).data('status')).trigger('change');
                $('#link_modal_edit').val($(this).data('link_dokumentasi'));
                $('#ringkasan_modal_edit').val($(this).data('ringkasan'));
                $('#form_edit').attr('action', 'konsultasi/' + id);
                $('#edit_modal').modal('show');
            });

            $('.btn_hapus').click(function() {
                let id = $(this).data('id');
                $('#name_modal_hapus').val($(this).data('name')).trigger('change');;
                $('#deskripsi_modal_hapus').val($(this).data('deskripsi'));
                $('#tanggal_modal_hapus').val($(this).data('tanggal'));
                $('#waktu_mulai_modal_hapus').val($(this).data('start_time'));
                $('#waktu_selesai_modal_hapus').val($(this).data('end_time'));
                $('#status_modal_hapus').val($(this).data('status')).trigger('change');
                $('#form_hapus').attr('action', 'konsultasi/' + id);
                $('#hapus_modal').modal('show');
            });

            $('.btn_rating').click(function() {
                let id = $(this).data('id');
                $('#name_modal_rating').val($(this).data('name')).trigger('change');;
                // console.log($(this).data())
                $('#rating_modal_rating').val($(this).data('rating'));
                $('#kritik_modal_rating').val($(this).data('kritik_saran'));
                // $('#tanggal_modal_edit').val($(this).data('tanggal'));
                // $('#waktu_mulai_modal_edit').val($(this).data('start_time'));
                // $('#waktu_selesai_modal_edit').val($(this).data('end_time'));
                // $('#status_modal_edit').val($(this).data('status')).trigger('change');
                $('#form_rating').attr('action', 'konsultasi_rating/' + id);
                $('#rating_modal').modal('show');
            });
            var stars = new StarRating('.star-rating');

            $('#btn_meet_user').click(function() {
                var link = $(this).data('link');
                var user = {!! json_encode($user['name']) !!}
                var message =
                    `Minn min min, ada user yang lagi join g-meet nih sekarang, namanya: ${user}.
                     Tolong dilayani ya. Linknya: ${link} `;
                sendNotifTelegram(message);
            })

            function sendNotifTelegram(message) {
                var token = {!! json_encode($telegram['token']) !!}
                var g_id = {!! json_encode($telegram['group_id']) !!}
                $.ajax({
                    method: "POST",
                    url: `https://api.telegram.org/bot${token}/sendMessage?chat_id=${g_id}&text=${message}`,
                    success: function(response) {
                        console.log(response);
                    }
                })
            }

            $('#tanggal_modal_tambah').datepicker({
                dateFormat: "yy-mm-dd",
                showOtherMonths: true,
                selectOtherMonths: true,
                minDate: isAfterSixPM() ? 1 : 0,
                appendTo: $("#tambah_modal"),
                beforeShowDay: function(date) {
                    // Nonaktifkan hari ini jika sudah lewat jam 18:00
                    const today = new Date();
                    const isToday = date.getDate() === today.getDate() &&
                        date.getMonth() === today.getMonth() &&
                        date.getFullYear() === today.getFullYear();

                    if (isToday && isAfterSixPM()) {
                        return [false, '', 'Hari ini sudah lewat jam 18:00'];
                    }
                    return [true, ''];
                }
            });

            $('#tanggal_modal_tambah').on('change', function() {
                const selectedDate = $(this).val();
                const today = new Date().toISOString().split('T')[0];
                const timepicker = $('#waktu_mulai_modal_tambah');

                if (selectedDate === today) {
                    const roundedMinTime = getRoundedMinTime(); // Waktu dibulatkan ke 30 menit

                    if (roundedMinTime === null) {
                        // Jika sudah lewat jam 18:00
                        timepicker.val('');
                        timepicker.timepicker('option', 'minTime', null);
                        timepicker.timepicker('option', 'disableTimeRanges', [
                            ['00:00', '23:59']
                        ]);
                    } else {
                        // Tambahkan 1 jam ke waktu yang sudah dibulatkan
                        const [hours, minutes] = roundedMinTime.split(':');
                        const minTimeDate = new Date();
                        minTimeDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                        minTimeDate.setHours(minTimeDate.getHours() + 1); // Tambah 1 jam

                        const minTimePlus1Hour =
                            minTimeDate.getHours().toString().padStart(2, '0') + ':' +
                            minTimeDate.getMinutes().toString().padStart(2, '0');

                        // Set minTime dengan waktu yang sudah dibulatkan + 1 jam
                        timepicker.timepicker('option', 'minTime', minTimePlus1Hour);
                        timepicker.timepicker('option', 'disableTimeRanges', disableTimesAfterSixPM());
                    }

                } else {
                    // Untuk hari selain hari ini
                    timepicker.timepicker('option', 'minTime', '08:00');
                    timepicker.timepicker('option', 'disableTimeRanges', []);
                }
            });

            $('#tanggal_modal_edit').datepicker({
                dateFormat: "yy-mm-dd",
                showOtherMonths: true,
                selectOtherMonths: true,
                appendTo: $("#edit_modal"),
            });

            $('#waktu_mulai_modal_tambah').timepicker({
                appendTo: '#tambah_modal',
                'scrollDefault': 'now',
                'timeFormat': 'H:i',
                'step': 30,
                'minTime': getRoundedMinTimePlus1Hour(), // Gunakan waktu rounded + 1 jam
                'maxTime': '18:00',
                'forceRoundTime': true,
                'disableTimeRanges': disableTimesAfterSixPM(),
                // 'showDuration': true
            });
            if (getRoundedMinTime() === null) {
                $('#waktu_mulai_modal_tambah').val('');
            }

            $('#waktu_mulai_modal_tambah').change(function() {
                const waktuMulai = $(this).val();
                if (waktuMulai) {
                    const [hours, minutes] = waktuMulai.split(':').map(Number);
                    const dateMulai = new Date();
                    dateMulai.setHours(hours, minutes + 45); // Tambah 45 menit

                    const waktuSelesai = [
                        String(dateMulai.getHours()).padStart(2, '0'),
                        String(dateMulai.getMinutes()).padStart(2, '0')
                    ].join(':');

                    // Set nilai waktu selesai
                    $('#waktu_selesai_modal_tambah').val(waktuSelesai);
                }
            });

            // $('#waktu_selesai_modal_tambah').timepicker({
            //     'scrollDefault': 'now',
            //     appendTo: '#tambah_modal',
            //     'timeFormat': 'H:i:s'
            // });

            $('#waktu_mulai_modal_edit').timepicker({
                'scrollDefault': 'now',
                appendTo: '#edit_modal',
                'timeFormat': 'H:i'
            });

            $('#waktu_mulai_modal_edit').change(function() {
                const waktuMulai = $(this).val(); // Ambil nilai waktu mulai (format: HH:MM:SS)
                // console.log(waktuMulai)
                if (waktuMulai) {
                    // Konversi waktu mulai ke Date object
                    const [hours, minutes, seconds] = waktuMulai.split(':').map(Number);
                    const dateMulai = new Date();
                    dateMulai.setHours(hours, minutes + 45); // Tambah 45 menit
                    // Format waktu selesai ke HH:MM
                    const waktuSelesai = [
                        String(dateMulai.getHours()).padStart(2, '0'),
                        String(dateMulai.getMinutes()).padStart(2, '0'),
                    ].join(':');
                    // Set nilai waktu selesai
                    $('#waktu_selesai_modal_edit').val(waktuSelesai);
                }
            });

            // $('#waktu_selesai_modal_edit').timepicker({
            //     'scrollDefault': 'now',
            //     appendTo: '#edit_modal',
            //     'timeFormat': 'H:i:s'
            // });

            $('#status_modal_tambah').select2({
                width: '100%',
                dropdownParent: $('#tambah_modal')
            });

            $('#status_modal_edit').select2({
                width: '100%',
                dropdownParent: $('#edit_modal')
            });


            $('#form_tambah').on('submit', function(e) {
                const tanggal = $('#tanggal_modal_tambah').val();
                const waktuMulai = $('#waktu_mulai_modal_tambah').val();

                if (!tanggal || !waktuMulai) return true;

                const [jam, menit] = waktuMulai.split(':');
                const jadwalDateTime = new Date(tanggal);
                jadwalDateTime.setHours(parseInt(jam), parseInt(menit));

                const sekarang = new Date();
                const minimalDateTime = new Date(sekarang.getTime() + 60 * 60 * 1000);

                if (jadwalDateTime < minimalDateTime && tanggal === sekarang.toISOString().split('T')[0]) {
                    e.preventDefault();
                    alert(`Waktu booking harus minimal 1 jam dari sekarang. Silakan pilih setelah ${formatTime(minimalDateTime)}`);
                    return false;
                }

                return true;
            });

        });

        function isAfterSixPM() {
            const now = new Date();
            return now.getHours() >= 18;
        }

        function formatTime(date) {
            return date.getHours() + ':' + (date.getMinutes() < 10 ? '0' : '') + date.getMinutes();
        }

        function getMinTime() {
            const now = new Date();
            now.setHours(now.getHours() + 1);
            return formatTime(now);
        }

        function getRoundedMinTime() {
            const now = new Date();
            // Jika sudah lewat jam 18:00
            if (now.getHours() >= 18) {
                return null;
            }
            // Bulatkan ke 30 menit berikutnya
            let minutes = now.getMinutes();
            let hours = now.getHours();
            if (minutes > 30) {
                hours += 1;
                minutes = 0;
            } else if (minutes > 0) {
                minutes = 30;
            }
            // Jika setelah pembulatan melewati jam 18:00
            if (hours >= 18) {
                return null;
            }
            return hours.toString().padStart(2, '0') + ':' +
                minutes.toString().padStart(2, '0');
        }

        function getRoundedMinTimePlus1Hour() {
            const roundedTime = getRoundedMinTime();
            if (roundedTime === null) return null;
            const [hours, minutes] = roundedTime.split(':');
            const timeDate = new Date();
            timeDate.setHours(parseInt(hours), parseInt(minutes), 0, 0);
            timeDate.setHours(timeDate.getHours() + 1); // Tambah 1 jam
            return timeDate.getHours().toString().padStart(2, '0') + ':' +
                timeDate.getMinutes().toString().padStart(2, '0');
        }

        function disableTimesAfterSixPM() {
            const now = new Date();
            if (now.getHours() >= 18) {
                return [
                    ['00:00', '23:59']
                ]; // Nonaktifkan semua waktu
            }
            return [];
        }
    </script>
@endsection
