@extends('layout.admin-rebrand')

@section('title', 'Manajemen Konsultasi | PST Digital')
@section('topbar_title', 'Manajemen konsultasi')

@section('content')
    <section class="admin-page-heading">
        <div><span class="admin-eyebrow">Layanan konsultasi</span><h1>Manajemen Konsultasi</h1><p>Verifikasi permintaan, tugaskan petugas PST, dan pantau pelaksanaan konsultasi.</p></div>
        <span class="admin-badge admin-badge-blue"><i class="fe fe-calendar"></i> {{ number_format($counts['all']) }} total</span>
    </section>

    <div class="admin-stat-grid admin-consultation-stats">
        <article class="admin-stat admin-stat-amber"><span class="admin-stat-label">Menunggu persetujuan</span><strong class="admin-stat-value">{{ number_format($counts['pending']) }}</strong><span class="admin-stat-icon"><i class="fe fe-inbox"></i></span></article>
        <article class="admin-stat admin-stat-blue"><span class="admin-stat-label">Terkonfirmasi</span><strong class="admin-stat-value">{{ number_format($counts['confirmed']) }}</strong><span class="admin-stat-icon"><i class="fe fe-calendar"></i></span></article>
        <article class="admin-stat admin-stat-green"><span class="admin-stat-label">Selesai</span><strong class="admin-stat-value">{{ number_format($counts['completed']) }}</strong><span class="admin-stat-icon"><i class="fe fe-check-circle"></i></span></article>
        <article class="admin-stat admin-stat-red"><span class="admin-stat-label">Dibatalkan</span><strong class="admin-stat-value">{{ number_format($counts['cancelled']) }}</strong><span class="admin-stat-icon"><i class="fe fe-x-circle"></i></span></article>
    </div>

    <section class="admin-consultation-section mb-4">
        <div class="admin-section-heading">
            <div><span class="admin-eyebrow">Perlu tindakan</span><h2>Permintaan konsultasi</h2><p>Periksa detail, pilih petugas yang tersedia, lalu konfirmasikan jadwal.</p></div>
            <span class="admin-badge admin-badge-amber">{{ $counts['pending'] }} menunggu</span>
        </div>
        @if ($pending->isNotEmpty())
            <div class="admin-pending-list">
                @foreach ($pending as $meeting)
                    <article class="admin-pending-card">
                        <div class="admin-pending-main">
                            <div class="admin-person">
                                <span class="admin-person-avatar">{{ strtoupper(substr($meeting->user?->name ?? 'P', 0, 1)) }}</span>
                                <span><strong>{{ $meeting->user?->name ?? 'Pengguna dihapus' }}</strong><small>{{ $meeting->user?->email ?? 'Tidak tersedia' }}</small></span>
                            </div>
                            <span class="admin-badge admin-badge-amber"><i class="fe fe-clock"></i> Menunggu persetujuan</span>
                        </div>
                        <h3>{{ $meeting->name }}</h3>
                        <p class="admin-pending-description">{{ \Illuminate\Support\Str::limit($meeting->description, 210) }}</p>
                        <div class="admin-consultation-meta">
                            <span><i class="fe fe-calendar"></i>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}</span>
                            <span><i class="fe fe-clock"></i>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</span>
                            <span><i class="fe fe-user-check"></i>{{ count($availableStaff[$meeting->id] ?? []) }} dari {{ count($staff) }} petugas tersedia</span>
                        </div>
                        <div class="admin-pending-actions">
                            <button class="admin-btn admin-btn-success" type="button" data-bs-toggle="modal" data-bs-target="#approveMeetingModal"
                                data-meeting-id="{{ $meeting->id }}" data-user="{{ $meeting->user?->name ?? 'Pengguna dihapus' }}" data-email="{{ $meeting->user?->email ?? '' }}"
                                data-topic="{{ $meeting->name }}" data-description="{{ $meeting->description }}" data-date="{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}"
                                data-start="{{ substr($meeting->start_time, 0, 5) }}" data-end="{{ substr($meeting->end_time, 0, 5) }}"
                                data-available="{{ implode('|', $availableStaff[$meeting->id] ?? []) }}">
                                <i class="fe fe-check"></i> Konfirmasi konsultasi
                            </button>
                            <button class="admin-btn admin-btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelMeetingModal" data-meeting-id="{{ $meeting->id }}" data-topic="{{ $meeting->name }}"><i class="fe fe-x"></i> Batalkan</button>
                        </div>
                    </article>
                @endforeach
            </div>
            @if ($pending->hasPages())<div class="admin-section-pagination">{{ $pending->links() }}</div>@endif
        @else
            <div class="admin-panel"><div class="admin-empty"><i class="fe fe-inbox"></i><strong>Tidak ada permintaan yang menunggu</strong><span>Permintaan baru dari pengguna akan muncul di bagian ini.</span></div></div>
        @endif
    </section>

    <section class="admin-consultation-section mb-4">
        <div class="admin-section-heading">
            <div><span class="admin-eyebrow">Sudah disetujui</span><h2>Konsultasi Mendatang</h2><p>Jadwal terkonfirmasi dan petugas yang bertanggung jawab.</p></div>
            <span class="admin-badge admin-badge-blue">{{ $counts['confirmed'] }} terkonfirmasi</span>
        </div>
        @if ($upcoming->isNotEmpty())
            <div class="admin-confirmed-grid">
                @foreach ($upcoming as $meeting)
                    <article class="admin-confirmed-card">
                        <span class="admin-badge admin-badge-green"><i class="fe fe-check-circle"></i> Terkonfirmasi</span>
                        <h3>{{ $meeting->user?->name ?? 'Pengguna dihapus' }}</h3>
                        <p class="admin-confirmed-topic">{{ $meeting->name }}</p>
                        <div class="admin-confirmed-detail"><span><i class="fe fe-calendar"></i>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('D, d M Y') }}</span><span><i class="fe fe-clock"></i>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</span><span><i class="fe fe-user"></i>{{ $meeting->assigned_staff ?: 'Petugas belum ditentukan' }}</span></div>
                        @if ($meeting->room_open)
                            <div class="admin-room-actions">
                                <span class="admin-room-presence" data-presence-url="{{ route('konsultasi.presence', $meeting) }}" aria-live="polite"><i class="fe fe-refresh-cw"></i> Memeriksa kehadiran...</span>
                                <form method="GET" action="{{ route('konsultasi.room', $meeting) }}"><button class="admin-btn admin-btn-primary admin-confirmed-button" type="submit"><i class="fe fe-video"></i> Masuk ruang konsultasi</button></form>
                            </div>
                        @endif
                        @unless ($meeting->room_open)<p class="admin-room-window-note">Ruang aktif hanya pada tanggal dan jam sesi.</p>@endunless
                        @if (!$meeting->approved_by_user_id || (int) $meeting->approved_by_user_id === (int) Auth::id())
                            <button class="admin-btn admin-btn-outline admin-confirmed-button consultation-chat-open" type="button" data-consultation-chat data-chat-url="{{ route('konsultasi.messages', $meeting) }}" data-chat-status-url="{{ route('konsultasi.messages.status', $meeting) }}" data-chat-topic="{{ $meeting->name }}" data-chat-person="{{ $meeting->user?->name ?? 'Pengguna' }}"><i class="fe fe-message-circle"></i> Chat<span class="consultation-chat-unread-dot" hidden aria-hidden="true"></span></button>
                        @endif
                        <button class="admin-btn admin-btn-success admin-confirmed-button" type="button" data-bs-toggle="modal" data-bs-target="#completeMeetingModal" data-meeting-id="{{ $meeting->id }}" data-topic="{{ $meeting->name }}"><i class="fe fe-check"></i> Selesaikan konsultasi</button>
                    </article>
                @endforeach
            </div>
            @if ($upcoming->hasPages())<div class="admin-section-pagination">{{ $upcoming->links() }}</div>@endif
        @else
            <div class="admin-panel"><div class="admin-empty"><i class="fe fe-calendar"></i><strong>Belum ada konsultasi terkonfirmasi</strong><span>Permintaan yang disetujui akan tampil di sini.</span></div></div>
        @endif
    </section>

    <section class="admin-consultation-section">
        <div class="admin-section-heading">
            <div><span class="admin-eyebrow">Arsip layanan</span><h2>Riwayat Konsultasi</h2><p>Sesi yang telah selesai atau dibatalkan.</p></div>
            <form class="admin-search" method="GET" action="{{ route('admin.konsultasi') }}"><i class="fe fe-search" aria-hidden="true"></i><input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Cari pengguna atau topik" aria-label="Cari riwayat konsultasi"></form>
        </div>
        <section class="admin-panel">
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Pengguna</th><th>Topik konsultasi</th><th>Tanggal</th><th>Waktu</th><th>Petugas</th><th>Status akhir</th><th>Rating</th><th>Detail</th></tr></thead><tbody>
                @forelse ($history as $meeting)
                    <tr>
                        <td><div class="admin-person"><span class="admin-person-avatar">{{ strtoupper(substr($meeting->user?->name ?? 'P', 0, 1)) }}</span><span><strong>{{ $meeting->user?->name ?? 'Pengguna dihapus' }}</strong><small>{{ $meeting->user?->email ?? '' }}</small></span></div></td>
                        <td>{{ $meeting->name }}</td><td>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}</td><td>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }}</td><td>{{ $meeting->assigned_staff ?: '—' }}</td>
                        <td><span class="admin-badge {{ (int) $meeting->status === 1 ? 'admin-badge-green' : 'admin-badge-red' }}">{{ (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan' }}</span></td>
                        <td>@if ($meeting->rating)<span class="admin-stars">{{ str_repeat('★', (int) $meeting->rating) }}</span>@else<span class="text-muted">—</span>@endif</td>
                        <td><button class="admin-action-icon" type="button" data-bs-toggle="modal" data-bs-target="#meetingDetailModal" data-user="{{ $meeting->user?->name ?? 'Pengguna dihapus' }}" data-email="{{ $meeting->user?->email ?? '' }}" data-topic="{{ $meeting->name }}" data-description="{{ $meeting->description }}" data-date="{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}" data-start="{{ substr($meeting->start_time, 0, 5) }}" data-end="{{ substr($meeting->end_time, 0, 5) }}" data-staff="{{ $meeting->assigned_staff }}" data-status="{{ (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan' }}" data-reason="{{ $meeting->cancellation_reason }}" data-summary="{{ $meeting->ringkasan }}" data-feedback="{{ $meeting->kritik_saran }}" data-rating="{{ $meeting->rating }}" data-doc-url="{{ $meeting->link_dokumentasi ? $meeting->link_dokumentasi : ($meeting->documentation_path ? route('konsultasi.documentation', $meeting) : '') }}" aria-label="Detail konsultasi" title="Lihat detail"><i class="fe fe-eye"></i></button></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="admin-empty"><i class="fe fe-archive"></i><strong>Riwayat masih kosong</strong><span>Sesi selesai atau dibatalkan akan tersimpan di sini.</span></div></td></tr>
                @endforelse
            </tbody></table></div>
            @if ($history->hasPages())<div class="admin-table-footer"><span>{{ $history->total() }} sesi dalam riwayat</span><div class="admin-pagination">{{ $history->links() }}</div></div>@endif
        </section>
    </section>

    <div class="modal fade admin-modal" id="approveMeetingModal" tabindex="-1" aria-labelledby="approveMeetingTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
            <form method="POST" id="approve-meeting-form" data-endpoint-base="{{ url('admin/konsultasi') }}">
                @csrf @method('PATCH')<input type="hidden" name="action" value="confirm">
                <div class="modal-header"><div><span class="admin-eyebrow">Verifikasi jadwal</span><h2 class="modal-title" id="approveMeetingTitle">Konfirmasi konsultasi</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
                <div class="modal-body">
                    <div class="admin-approval-person"><span class="admin-person-avatar" id="approval-avatar">P</span><div><strong id="approval-user"></strong><small id="approval-email"></small></div></div>
                    <div class="admin-approval-details">
                        <div><small>Jenis konsultasi</small><strong id="approval-topic"></strong></div>
                        <div><small>Jadwal yang diminta</small><strong id="approval-schedule"></strong></div>
                        <div class="admin-approval-description"><small>Deskripsi kebutuhan</small><p id="approval-description"></p></div>
                    </div>
                    <div class="mt-3"><label class="form-label" for="assigned-staff">Petugas PST yang melayani</label><select class="form-select" id="assigned-staff" name="assigned_staff" required><option value="">Pilih petugas tersedia</option>@foreach ($staff as $staffName)<option value="{{ $staffName }}">{{ $staffName }}</option>@endforeach</select><small class="form-text" id="staff-availability-note">Petugas dengan jadwal bentrok tidak dapat dipilih.</small></div>
                    <div class="admin-approval-warning" id="no-staff-warning" hidden><i class="fe fe-alert-circle"></i><span>Semua petugas sudah memiliki jadwal yang bertabrakan pada waktu ini.</span></div>
                </div>
                <div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Kembali</button><button class="admin-btn admin-btn-success" id="approve-submit" type="submit"><i class="fe fe-check"></i> Konfirmasi &amp; tugaskan petugas</button></div>
            </form>
        </div></div>
    </div>

    <div class="modal fade admin-modal" id="cancelMeetingModal" tabindex="-1" aria-labelledby="cancelMeetingTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="POST" id="cancel-meeting-form" data-endpoint-base="{{ url('admin/konsultasi') }}">@csrf @method('PATCH')<input type="hidden" name="action" value="cancel"><div class="modal-header"><div><span class="admin-eyebrow">Tolak permintaan</span><h2 class="modal-title" id="cancelMeetingTitle">Batalkan konsultasi</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><p class="text-muted">Permintaan: <strong id="cancel-meeting-topic"></strong></p><label class="form-label" for="cancellation-reason">Alasan pembatalan</label><textarea class="form-control" id="cancellation-reason" name="cancellation_reason" rows="4" minlength="10" maxlength="1000" placeholder="Jelaskan mengapa permintaan tidak dapat disetujui..." required></textarea></div><div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Kembali</button><button class="admin-btn admin-btn-danger" type="submit">Simpan pembatalan</button></div></form></div></div></div>

    <div class="modal fade admin-modal" id="completeMeetingModal" tabindex="-1" aria-labelledby="completeMeetingTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><form method="POST" id="complete-meeting-form" enctype="multipart/form-data" data-endpoint-base="{{ url('admin/konsultasi') }}">@csrf @method('PATCH')<input type="hidden" name="action" value="complete"><div class="modal-header"><div><span class="admin-eyebrow">Tutup sesi</span><h2 class="modal-title" id="completeMeetingTitle">Selesaikan konsultasi</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><p class="text-muted">Sesi: <strong id="complete-meeting-topic"></strong>. Setelah disimpan, pengguna dapat memberi rating dan kritik/saran.</p><div class="mb-3"><label class="form-label" for="meeting-summary">Hasil konsultasi</label><textarea class="form-control" id="meeting-summary" name="ringkasan" rows="4" minlength="10" maxlength="5000" placeholder="Rangkuman hasil, jawaban, dan tindak lanjut..." required></textarea></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="meeting-documentation">Dokumentasi gambar/video</label><input class="form-control" id="meeting-documentation" name="documentation" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime"><small class="form-text">Maksimal 25 MB. Gambar dioptimalkan saat disimpan; video disimpan dengan batas ukuran.</small></div><div class="col-md-6"><label class="form-label" for="meeting-documentation-url">Atau tautan dokumentasi</label><input class="form-control" id="meeting-documentation-url" name="documentation_url" type="url" maxlength="2048" placeholder="https://..."><small class="form-text">Isi salah satu: berkas atau tautan.</small></div></div><div class="admin-detail-error mt-2" id="documentation-choice-error" hidden>Pilih berkas atau masukkan tautan dokumentasi.</div></div><div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Kembali</button><button class="admin-btn admin-btn-success" type="submit"><i class="fe fe-check"></i> Selesaikan sesi</button></div></form></div></div></div>

    <div class="modal fade admin-modal" id="meetingDetailModal" tabindex="-1" aria-labelledby="meetingDetailTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><div><span class="admin-eyebrow">Arsip sesi</span><h2 class="modal-title" id="meetingDetailTitle">Detail konsultasi</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div><div class="modal-body"><div class="admin-approval-details"><div><small>Pengguna</small><strong id="detail-user"></strong></div><div><small>Email</small><strong id="detail-email"></strong></div><div><small>Jenis konsultasi</small><strong id="detail-topic"></strong></div><div><small>Waktu</small><strong id="detail-schedule"></strong></div><div><small>Petugas</small><strong id="detail-staff"></strong></div><div><small>Status</small><strong id="detail-status"></strong></div><div class="admin-approval-description"><small>Deskripsi</small><p id="detail-description"></p></div><div class="admin-approval-description"><small>Hasil konsultasi</small><p id="detail-summary"></p></div><div class="admin-approval-description" id="detail-cancellation-wrap"><small>Alasan pembatalan</small><p id="detail-reason"></p></div><div><small>Rating pengguna</small><strong id="detail-rating"></strong></div><div><small>Kritik/saran</small><strong id="detail-feedback"></strong></div><div class="admin-approval-description" id="detail-document-wrap"><small>Dokumentasi</small><p><a id="detail-document-link" target="_blank" rel="noopener">Buka dokumentasi</a></p></div></div></div><div class="modal-footer"><button class="admin-btn admin-btn-outline" type="button" data-bs-dismiss="modal">Tutup</button></div></div></div></div>
@include('konsultasi.partials.text-chat-modal')

@endsection

@section('scripts')
<script>
    (() => {
        const modal = document.getElementById('approveMeetingModal');
        const form = document.getElementById('approve-meeting-form');
        const select = document.getElementById('assigned-staff');
        const warning = document.getElementById('no-staff-warning');
        const submit = document.getElementById('approve-submit');
        const endpointBase = form.dataset.endpointBase;

        modal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            form.action = `${endpointBase}/${button.dataset.meetingId}/status`;
            document.getElementById('approval-user').textContent = button.dataset.user;
            document.getElementById('approval-email').textContent = button.dataset.email;
            document.getElementById('approval-avatar').textContent = button.dataset.user.charAt(0).toUpperCase();
            document.getElementById('approval-topic').textContent = button.dataset.topic;
            document.getElementById('approval-schedule').textContent = `${button.dataset.date}, ${button.dataset.start}–${button.dataset.end} WIB`;
            document.getElementById('approval-description').textContent = button.dataset.description || 'Tidak ada deskripsi tambahan.';

            const available = button.dataset.available ? button.dataset.available.split('|') : [];
            [...select.options].forEach((option) => {
                if (!option.value) return;
                option.disabled = !available.includes(option.value);
                option.textContent = `${option.value}${option.disabled ? ' · sudah terjadwal' : ' · tersedia'}`;
            });
            select.value = '';
            warning.hidden = available.length > 0;
            submit.disabled = available.length === 0;
        });

        document.getElementById('cancelMeetingModal').addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            document.getElementById('cancel-meeting-form').action = `${document.getElementById('cancel-meeting-form').dataset.endpointBase}/${button.dataset.meetingId}/status`;
            document.getElementById('cancel-meeting-topic').textContent = button.dataset.topic;
            document.getElementById('cancellation-reason').value = '';
        });

        document.getElementById('completeMeetingModal').addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            document.getElementById('complete-meeting-form').action = `${document.getElementById('complete-meeting-form').dataset.endpointBase}/${button.dataset.meetingId}/status`;
            document.getElementById('complete-meeting-topic').textContent = button.dataset.topic;
            document.getElementById('meeting-summary').value = '';
            document.getElementById('meeting-documentation').value = '';
            document.getElementById('meeting-documentation-url').value = '';
        });

        document.getElementById('complete-meeting-form').addEventListener('submit', (event) => {
            const hasFile = document.getElementById('meeting-documentation').files.length > 0;
            const hasUrl = document.getElementById('meeting-documentation-url').value.trim() !== '';
            const error = document.getElementById('documentation-choice-error');
            error.hidden = hasFile || hasUrl;
            if (!hasFile && !hasUrl) event.preventDefault();
        });

        document.getElementById('meetingDetailModal').addEventListener('show.bs.modal', (event) => {
            const data = event.relatedTarget.dataset;
            for (const [id, value] of Object.entries({
                'detail-user': data.user, 'detail-email': data.email, 'detail-topic': data.topic,
                'detail-schedule': `${data.date}, ${data.start}-${data.end} WIB`, 'detail-staff': data.staff || '—',
                'detail-status': data.status, 'detail-description': data.description || '—', 'detail-summary': data.summary || '—',
                'detail-reason': data.reason || '—', 'detail-rating': data.rating ? `${data.rating}/5` : 'Belum ada penilaian',
                'detail-feedback': data.feedback || '—'
            })) document.getElementById(id).textContent = value;
            document.getElementById('detail-cancellation-wrap').hidden = !data.reason;
            const documentWrap = document.getElementById('detail-document-wrap');
            documentWrap.hidden = !data.docUrl;
            if (data.docUrl) document.getElementById('detail-document-link').href = data.docUrl;
        });
    })();
</script>
<script>
    (() => {
        const indicators = document.querySelectorAll('[data-presence-url]');
        const updatePresence = async (indicator) => {
            try {
                const response = await fetch(indicator.dataset.presenceUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                if (!response.ok) throw new Error('presence unavailable');
                const { peer_present: present } = await response.json();
                indicator.classList.toggle('is-present', present);
                indicator.innerHTML = present
                    ? '<i class="fe fe-user-check"></i> Pengguna sudah masuk ruang'
                    : '<i class="fe fe-clock"></i> Belum ada pengguna di ruang';
            } catch {
                indicator.classList.remove('is-present');
                indicator.innerHTML = '<i class="fe fe-refresh-cw"></i> Status ruang belum tersedia';
            }
        };
        const refreshPresence = () => {
            if (document.hidden) return;
            indicators.forEach((indicator, index) => {
                window.setTimeout(() => {
                    if (!document.hidden) updatePresence(indicator);
                }, index * 250);
            });
        };
        refreshPresence();
        if (indicators.length) window.setInterval(refreshPresence, 15000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshPresence();
        });
    })();
</script>
@include('konsultasi.partials.text-chat-script')
@endsection
