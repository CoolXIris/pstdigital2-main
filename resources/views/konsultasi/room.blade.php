@extends('layout.user-portal')

@section('title', 'Ruang Konsultasi | PST Digital')
@section('topbar_title', 'Ruang konsultasi')

@section('content')
<section class="portal-heading">
    <div>
        <span class="portal-eyebrow">Sesi terjadwal</span>
        <h1>{{ $meeting->name }}</h1>
        <p>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }} · {{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</p>
    </div>
    <a class="portal-btn portal-btn-outline" href="{{ $returnUrl }}"><i class="bi bi-arrow-left"></i> Kembali</a>
</section>

<div class="portal-room-layout" id="consultation-room"
    data-meeting-id="{{ $meeting->id }}"
    data-current-user-id="{{ $currentUserId }}"
    data-current-user-name="{{ Auth::user()->name }}"
    data-current-user-picture="{{ Auth::user()->picture }}"
    data-is-requester="{{ $isRequester ? 'true' : 'false' }}"
    data-can-moderate="{{ $canModerateRoom ? 'true' : 'false' }}"
    data-room-ends-at="{{ $roomEndsAt }}"
    data-room-server-now="{{ $roomServerNow }}"
    data-room-session="{{ $roomSessionId }}"
    data-room-capacity="{{ $roomCapacity }}"
    data-last-signal-id="{{ $roomLastSignalId }}"
    data-signals-url="{{ route('konsultasi.signals.send', $meeting) }}"
    data-poll-url="{{ route('konsultasi.signals.poll', $meeting) }}"
    data-return-url="{{ $returnUrl }}"
    data-ice-servers="{{ json_encode($iceServers) }}">
    <section class="portal-room-stage">
        <div class="portal-room-gallery" id="room-gallery" data-count="1">
            <div class="portal-room-screen-stage" id="room-screen-stage" hidden>
                <video id="room-screen-video" autoplay playsinline></video>
                <span id="room-screen-label"></span>
            </div>
            <div class="portal-room-pinned-stage" id="room-pinned-stage" hidden>
                <video id="room-pinned-video" autoplay playsinline></video>
                <div class="portal-room-camera-off portal-room-pinned-avatar" id="room-pinned-avatar" hidden></div>
                <span id="room-pinned-label"></span>
                <button class="portal-room-unpin" id="room-unpin-camera" type="button">Lepas sematan</button>
            </div>
            <div class="portal-room-camera-strip" id="room-camera-strip" aria-live="polite">
                <div class="portal-room-placeholder" id="remote-placeholder">
                    <span><i class="bi bi-person-video3"></i></span>
                    <strong>Menunggu peserta lain bergabung</strong>
                    <small>Ruang ini menyediakan {{ $roomCapacity }} kursi.</small>
                </div>
            </div>
        </div>
        <div class="portal-room-caption">
            <span class="portal-room-live-dot"></span>
            <span id="room-status">Menghubungkan ke ruang...</span>
            <span class="ms-auto" id="room-participant-count">1/{{ $roomCapacity }} peserta</span>
        </div>
        <div class="portal-room-notice" id="room-moderation-notice" role="status" hidden></div>
        <div class="portal-room-ending-notice" id="room-ending-notice" role="alert" aria-live="assertive" aria-atomic="true" hidden>
            <strong>Sesi konsultasi segera berakhir</strong>
            <span>Ruang akan ditutup dalam <b id="room-ending-countdown">05:00</b>.</span>
        </div>
        <div class="portal-room-controls">
            <button class="portal-room-control" id="toggle-mic" type="button" aria-label="Matikan mikrofon"><i class="bi bi-mic-fill"></i></button>
            <button class="portal-room-control" id="toggle-camera" type="button" aria-label="Matikan kamera"><i class="bi bi-camera-video-fill"></i></button>
            <button class="portal-room-control" id="share-screen" type="button" aria-label="Bagikan layar"><i class="bi bi-display"></i></button>
            <button class="portal-room-control" id="toggle-fullscreen" type="button" aria-label="Layar penuh"><i class="bi bi-fullscreen"></i></button>
            <button class="portal-room-control portal-room-hangup" id="leave-room" type="button" aria-label="Keluar ruang"><i class="bi bi-telephone-x-fill"></i></button>
        </div>
    </section>

    <aside class="portal-room-aside">
        <span class="portal-eyebrow">Detail sesi</span>
        <h2>{{ $meeting->name }}</h2>
        <dl>
            <div>
                <dt>Pengguna</dt>
                <dd>{{ $meeting->user?->name }}</dd>
            </div>
            <div>
                <dt>Petugas utama</dt>
                <dd>{{ $meeting->assigned_staff }}</dd>
            </div>
            <div>
                <dt>Jadwal</dt>
                <dd>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}<br>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</dd>
            </div>
        </dl>
        <p class="text-muted small">Untuk menyertakan suara, pilih tab browser saat berbagi layar lalu aktifkan opsi berbagi audio tab.</p>

        @if ($canManageRoomInvites)
            <details class="portal-room-invite">
                <summary><i class="bi bi-person-plus"></i> Tambah petugas (maksimal 2)</summary>
                <p>Pilih satu atau dua akun petugas admin. Setelah disimpan, tombol bergabung tersedia pada halaman mereka.</p>
                <div class="portal-room-invited" data-room-invited-list @if ($invitedUsers->isEmpty()) hidden @endif>
                    @if ($invitedUsers->isNotEmpty())
                        <strong>Petugas yang diundang:</strong>
                        @foreach ($invitedUsers as $invitedUser)
                            <span>{{ $invitedUser->name }}</span>
                        @endforeach
                    @endif
                </div>
                @if ($availableStaff->isNotEmpty() && $invitedUsers->count() < 2)
                    <form method="POST" action="{{ route('admin.konsultasi.room-invites.store', $meeting) }}"
                        data-room-invite-form data-max-invites="{{ 2 - $invitedUsers->count() }}"
                        data-total-invites="{{ $invitedUsers->count() }}">
                        @csrf
                        @foreach ($availableStaff as $staffUser)
                            <label><input type="checkbox" name="staff_user_ids[]" value="{{ $staffUser->id }}"> {{ $staffUser->name }}</label>
                        @endforeach
                        @error('staff_user_ids')<small class="text-danger">{{ $message }}</small>@enderror
                        <button class="portal-btn portal-btn-primary" type="submit">Undang petugas</button>
                        <small data-room-invite-status role="status" aria-live="polite"></small>
                    </form>
                @elseif ($invitedUsers->count() >= 2)
                    <small data-room-invite-capacity>Batas dua petugas tambahan sudah tercapai.</small>
                @else
                    <small>Tidak ada akun petugas lain yang tersedia.</small>
                @endif
            </details>
        @endif

        <div class="portal-room-chat-entry">
            <div><strong>Perlu mengirim pesan?</strong><small>Gunakan chat teks tanpa meninggalkan ruang konsultasi.</small></div>
            <button class="portal-btn portal-btn-primary consultation-chat-open" type="button"
                data-consultation-chat
                data-chat-url="{{ route('konsultasi.messages', $meeting) }}"
                data-chat-status-url="{{ route('konsultasi.messages.status', $meeting) }}"
                data-chat-topic="{{ $meeting->name }}"
                data-chat-person="{{ $isRequester ? ($meeting->assigned_staff ?: 'Petugas konsultasi') : ($meeting->user?->name ?? 'Pengguna') }}"
                aria-label="Buka chat konsultasi">
                <i class="bi bi-chat-dots-fill" aria-hidden="true"></i> Buka chat teks<span class="consultation-chat-unread-dot" hidden aria-hidden="true"></span>
            </button>
        </div>
        <div class="portal-chat-note"><i class="bi bi-shield-check me-1"></i>Media dikirim langsung antarperangkat jika memungkinkan, atau melalui TURN saat jaringan membatasi koneksi. Laravel menangani signaling.</div>
        <div class="portal-room-quality">
            <label for="screen-share-quality"><i class="bi bi-display me-1"></i>Kualitas berbagi layar</label>
            <select id="screen-share-quality" class="form-select" aria-describedby="screen-share-quality-status">
                <option value="auto" selected>Otomatis · 720p–1080p, 30–60 FPS</option>
                <option value="720p30">Hemat jaringan · 720p, 30 FPS</option>
                <option value="1080p30">Tinggi · 1080p, 30 FPS</option>
                <option value="1080p60">Gerakan halus · 1080p, 60 FPS</option>
            </select>
            <small id="screen-share-quality-status" aria-live="polite">Mode otomatis memilih kualitas sesuai kondisi koneksi saat berbagi layar aktif.</small>
        </div>
        <div class="portal-chat-note" id="turn-configuration-note"><i class="bi bi-info-circle me-1"></i>Izinkan akses kamera dan mikrofon. Koneksi lintas jaringan memerlukan konfigurasi STUN/TURN.</div>
    </aside>
</div>
@include('konsultasi.partials.text-chat-modal')
@endsection

@section('scripts')
<script src="{{ asset('consultation-room.js') }}?v=10" defer></script>
@include('konsultasi.partials.text-chat-script')
@endsection