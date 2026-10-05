@extends('layout.user-portal')

@section('title', 'Profil Saya | PST Digital')
@section('topbar_title', 'Profil saya')

@section('content')
@php
$profileFields = ['name', 'pekerjaan', 'jenis_kelamin', 'tanggal_lahir', 'asal_prov', 'asal_kab', 'no_hp', 'pendidikan'];
$filledFields = collect($profileFields)->filter(fn ($field) => filled($data->{$field}))->count();
$completion = (int) round($filledFields / count($profileFields) * 100);
$jobs = ['Pelajar/Mahasiswa', 'Peneliti/Dosen', 'Pegawai Swasta', 'Pegawai BUMN/BUMD', 'Wiraswasta', 'ASN/TNI/Polri'];
@endphp

<section class="portal-heading">
    <div><span class="portal-eyebrow">Akun layanan</span>
        <h1>Profil Saya</h1>
        <p>Lengkapi informasi ini agar petugas dapat memberikan layanan yang sesuai kebutuhan Anda.</p>
    </div>
</section>

<div class="portal-profile-hero mb-3">
    <span class="portal-profile-photo">@if ($data->picture)<img src="{{ $data->picture }}" alt="Foto profil">@else{{ strtoupper(substr($data->name, 0, 1)) }}@endif</span>
    <div>
        <h2>{{ $data->name }}</h2>
        <p>{{ $data->email }}</p>
    </div>
    <div class="portal-completion" data-completion="{{ $completion }}">
        <div class="portal-completion-label"><span>Kelengkapan profil</span><strong>{{ $completion }}%</strong></div>
        <div class="portal-completion-track"><span></span></div>
    </div>
</div>

<form method="POST" action="{{ route('profile.update', $data) }}">
    @csrf @method('PUT')
    <section class="portal-form-section">
        <h2><i class="bi bi-person-lines-fill me-2"></i>Informasi dasar</h2>
        <p>Identitas yang digunakan untuk layanan konsultasi.</p>
        <div class="row g-3">
            <div class="col-lg-6"><label class="form-label" for="profile-name">Nama lengkap</label>
                <div class="portal-input-icon"><i class="bi bi-person"></i><input class="form-control" id="profile-name" name="name" value="{{ old('name', $data->name) }}" autocomplete="name" required maxlength="255"></div>
            </div>
            <div class="col-lg-6"><label class="form-label" for="profile-email">Email akun Google</label>
                <div class="portal-input-icon"><i class="bi bi-envelope"></i><input class="form-control" id="profile-email" value="{{ $data->email }}" disabled readonly></div>
            </div>
            <div class="col-md-6"><label class="form-label" for="profile-job">Pekerjaan</label><select class="form-select" id="profile-job" name="pekerjaan" required>
                    <option value="">Pilih kategori pekerjaan</option>@foreach ($jobs as $job)<option value="{{ $job }}" @selected(old('pekerjaan', $data->pekerjaan) === $job)>{{ $job }}</option>@endforeach
                </select></div>
            <div class="col-md-6"><label class="form-label" for="profile-education">Pendidikan terakhir</label><input class="form-control" id="profile-education" name="pendidikan" value="{{ old('pendidikan', $data->pendidikan) }}" placeholder="Contoh: S1 Statistik" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label" for="profile-gender">Jenis kelamin</label><select class="form-select" id="profile-gender" name="jenis_kelamin" required>
                    <option value="">Pilih jenis kelamin</option>
                    <option value="1" @selected((string) old('jenis_kelamin', $data->jenis_kelamin) === '1')>Laki-laki</option>
                    <option value="2" @selected((string) old('jenis_kelamin', $data->jenis_kelamin) === '2')>Perempuan</option>
                </select></div>
            <div class="col-md-6"><label class="form-label" for="profile-birth">Tanggal lahir</label><input class="form-control" id="profile-birth" type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir', $data->tanggal_lahir) }}" max="{{ now()->subDay()->toDateString() }}" required></div>
        </div>
    </section>

    <section class="portal-form-section">
        <h2><i class="bi bi-geo-alt me-2"></i>Domisili dan kontak</h2>
        <p>Informasi ini membantu petugas memahami konteks permintaan Anda.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="profile-province">Provinsi</label><input class="form-control" id="profile-province" name="asal_prov" value="{{ old('asal_prov', $data->asal_prov) }}" placeholder="Nama provinsi" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label" for="profile-city">Kabupaten / kota</label><input class="form-control" id="profile-city" name="asal_kab" value="{{ old('asal_kab', $data->asal_kab) }}" placeholder="Nama kabupaten atau kota" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label" for="profile-phone">Nomor telepon</label>
                <div class="portal-input-icon"><i class="bi bi-telephone"></i><input class="form-control" id="profile-phone" name="no_hp" value="{{ old('no_hp', $data->no_hp) }}" type="tel" autocomplete="tel" placeholder="08xxxxxxxxxx" maxlength="30" required></div>
            </div>
        </div>
    </section>

    <div class="portal-sticky-actions"><a class="portal-btn portal-btn-outline" href="{{ url('/') }}">Batal</a><button class="portal-btn portal-btn-primary" type="submit"><i class="bi bi-check2"></i> Simpan profil</button></div>
</form>
@endsection

@section('scripts')
<script>
    document.querySelectorAll('[data-completion]').forEach((indicator) => {
        indicator.querySelector('.portal-completion-track span').style.width = `${indicator.dataset.completion}%`;
    });
</script>
@endsection