@extends('layout.admin-rebrand')

@section('title', 'Manajemen Pengguna | PST Digital')
@section('topbar_title', 'Manajemen pengguna')

@section('content')
    <section class="admin-page-heading">
        <div>
            <span class="admin-eyebrow">Administrasi akun</span>
            <h1>Manajemen Pengguna</h1>
            <p>Kelola akun, informasi instansi, dan tingkat akses pengguna layanan PST Digital.</p>
        </div>
        <div class="admin-heading-actions">
            <a class="admin-btn admin-btn-outline" href="{{ route('users_export') }}"><i class="fe fe-download"></i> Ekspor XLSX</a>
            <button class="admin-btn admin-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="fe fe-user-plus"></i> Tambah pengguna</button>
        </div>
    </section>

    <div class="admin-stat-grid">
        <article class="admin-stat admin-stat-blue"><span class="admin-stat-label">Semua akun</span><strong class="admin-stat-value">{{ number_format($stats['total']) }}</strong><span class="admin-stat-icon"><i class="fe fe-users"></i></span></article>
        <article class="admin-stat admin-stat-green"><span class="admin-stat-label">Pengguna layanan</span><strong class="admin-stat-value">{{ number_format($stats['users']) }}</strong><span class="admin-stat-icon"><i class="fe fe-user"></i></span></article>
        <article class="admin-stat admin-stat-amber"><span class="admin-stat-label">Administrator</span><strong class="admin-stat-value">{{ number_format($stats['admins']) }}</strong><span class="admin-stat-icon"><i class="fe fe-shield"></i></span></article>
        <article class="admin-stat admin-stat-red"><span class="admin-stat-label">Hasil pencarian</span><strong class="admin-stat-value">{{ number_format($data->total()) }}</strong><span class="admin-stat-foot">Akun cocok dengan filter aktif</span><span class="admin-stat-icon"><i class="fe fe-filter"></i></span></article>
    </div>

    <section class="admin-panel">
        <form class="admin-toolbar" method="GET" action="{{ route('users.index') }}">
            <div class="admin-search">
                <i class="fe fe-search" aria-hidden="true"></i>
                <input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Cari nama, email, atau instansi" aria-label="Cari pengguna">
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select" name="role" aria-label="Filter berdasarkan role" style="min-height:36px;width:auto;font-size:10px;border-color:#dce4ed">
                    <option value="">Semua role</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->name }}" @selected($roleFilter === $role->name)>{{ ucfirst(str_replace('_', ' ', $role->name)) }}</option>
                    @endforeach
                </select>
                <button class="admin-btn admin-btn-outline" type="submit"><i class="fe fe-filter"></i> Filter</button>
                @if ($search || $roleFilter)<a class="admin-btn admin-btn-outline" href="{{ route('users.index') }}" aria-label="Hapus filter"><i class="fe fe-x"></i></a>@endif
            </div>
        </form>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Pengguna</th><th>Instansi</th><th>Jabatan</th><th>Role</th><th>Bergabung</th><th class="text-end">Aksi</th></tr></thead>
                <tbody>
                    @forelse ($data as $user)
                        @php($userRole = $user->getRoleNames()->first() ?? 'user')
                        <tr>
                            <td>
                                <div class="admin-person">
                                    <span class="admin-person-avatar">@if ($user->picture)<img src="{{ $user->picture }}" alt="">@else{{ strtoupper(substr($user->name, 0, 1)) }}@endif</span>
                                    <span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span>
                                </div>
                            </td>
                            <td>{{ $user->instansi ?: '—' }}</td>
                            <td>{{ $user->jabatan ?: '—' }}</td>
                            <td><span class="admin-badge {{ in_array($userRole, ['admin', 'super_admin'], true) ? 'admin-badge-blue' : 'admin-badge-neutral' }}">{{ ucfirst(str_replace('_', ' ', $userRole)) }}</span></td>
                            <td>{{ $user->created_at?->translatedFormat('d M Y') ?? '—' }}</td>
                            <td>
                                <div class="admin-action-group">
                                    <button class="admin-action-icon" type="button" data-edit-user data-id="{{ $user->id }}" data-name="{{ $user->name }}" data-email="{{ $user->email }}" data-instansi="{{ $user->instansi }}" data-jabatan="{{ $user->jabatan }}" data-role="{{ $userRole }}" title="Edit pengguna" aria-label="Edit {{ $user->name }}"><i class="fe fe-edit-2"></i></button>
                                    @if ((int) $user->id !== (int) Auth::id())
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Hapus akun {{ addslashes($user->name) }}?')">
                                            @csrf @method('DELETE')<button class="admin-action-icon is-danger" type="submit" title="Hapus pengguna" aria-label="Hapus {{ $user->name }}"><i class="fe fe-trash-2"></i></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="admin-empty"><i class="fe fe-users"></i><strong>Tidak ada pengguna ditemukan</strong><span>Coba ubah kata kunci atau filter role.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($data->hasPages())<div class="admin-table-footer"><span>Menampilkan {{ $data->firstItem() }}–{{ $data->lastItem() }} dari {{ $data->total() }} pengguna</span><div class="admin-pagination">{{ $data->links() }}</div></div>@endif
    </section>

    <div class="modal fade admin-modal" id="createUserModal" tabindex="-1" aria-labelledby="createUserTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <form method="POST" action="{{ route('users.store') }}">
                @csrf
                <div class="modal-header"><div><h2 class="modal-title" id="createUserTitle">Tambah pengguna</h2><p class="admin-panel-subtitle mb-0">Buat akun baru untuk layanan PST Digital.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label" for="create-name">Nama lengkap</label><input class="form-control" id="create-name" name="name" value="{{ old('name') }}" required maxlength="255"></div>
                    <div class="mb-3"><label class="form-label" for="create-email">Email</label><input class="form-control" id="create-email" name="email" type="email" value="{{ old('email') }}" required maxlength="255"></div>
                    <div class="row g-3 mb-3"><div class="col-sm-6"><label class="form-label" for="create-instansi">Instansi</label><input class="form-control" id="create-instansi" name="instansi" maxlength="255"></div><div class="col-sm-6"><label class="form-label" for="create-jabatan">Jabatan</label><input class="form-control" id="create-jabatan" name="jabatan" maxlength="255"></div></div>
                    <div><label class="form-label" for="create-role">Role</label><select class="form-select" id="create-role" name="role" required>
                        @foreach ($roles as $role)
                            @if (Auth::user()->hasRole('super_admin') || $role->name === 'user')<option value="{{ $role->name }}">{{ ucfirst(str_replace('_', ' ', $role->name)) }}</option>@endif
                        @endforeach
                    </select></div>
                </div>
                <div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Batal</button><button class="admin-btn admin-btn-primary" type="submit"><i class="fe fe-check"></i> Simpan pengguna</button></div>
            </form>
        </div></div>
    </div>

    <div class="modal fade admin-modal" id="editUserModal" tabindex="-1" aria-labelledby="editUserTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <form method="POST" id="edit-user-form" data-users-url="{{ url('users') }}">
                @csrf @method('PUT')
                <div class="modal-header"><div><h2 class="modal-title" id="editUserTitle">Edit pengguna</h2><p class="admin-panel-subtitle mb-0">Perbarui informasi akun dan akses.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label" for="edit-name">Nama lengkap</label><input class="form-control" id="edit-name" name="name" required maxlength="255"></div>
                    <div class="mb-3"><label class="form-label" for="edit-email">Email</label><input class="form-control" id="edit-email" name="email" type="email" required maxlength="255"></div>
                    <div class="row g-3 mb-3"><div class="col-sm-6"><label class="form-label" for="edit-instansi">Instansi</label><input class="form-control" id="edit-instansi" name="instansi" maxlength="255"></div><div class="col-sm-6"><label class="form-label" for="edit-jabatan">Jabatan</label><input class="form-control" id="edit-jabatan" name="jabatan" maxlength="255"></div></div>
                    <div><label class="form-label" for="edit-role">Role</label><select class="form-select" id="edit-role" name="role" required>
                        @foreach ($roles as $role)
                            @if (Auth::user()->hasRole('super_admin') || $role->name === 'user')<option value="{{ $role->name }}">{{ ucfirst(str_replace('_', ' ', $role->name)) }}</option>@endif
                        @endforeach
                    </select></div>
                </div>
                <div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Batal</button><button class="admin-btn admin-btn-primary" type="submit"><i class="fe fe-check"></i> Simpan perubahan</button></div>
            </form>
        </div></div>
    </div>
@endsection

@section('scripts')
<script>
    (() => {
        const editModalElement = document.getElementById('editUserModal');
        const editModal = window.bootstrap ? new bootstrap.Modal(editModalElement) : null;
        document.querySelectorAll('[data-edit-user]').forEach((button) => {
            button.addEventListener('click', () => {
                const form = document.getElementById('edit-user-form');
                form.action = `${form.dataset.usersUrl}/${button.dataset.id}`;
                document.getElementById('edit-name').value = button.dataset.name;
                document.getElementById('edit-email').value = button.dataset.email;
                document.getElementById('edit-instansi').value = button.dataset.instansi;
                document.getElementById('edit-jabatan').value = button.dataset.jabatan;
                document.getElementById('edit-role').value = button.dataset.role;
                editModal?.show();
            });
        });
    })();
</script>
@endsection
