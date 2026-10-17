@extends('layout.user-portal')

@section('title', 'Konsultasi Statistik | PST Digital')
@section('topbar_title', 'Konsultasi statistik')

@section('content')
<section class="portal-heading">
    <div><span class="portal-eyebrow">Layanan konsultasi</span>
        <h1>Konsultasi Statistik</h1>
        <p>Ajukan sesi bersama petugas BPS untuk memperoleh penjelasan mengenai data, indikator, atau metodologi statistik.</p>
    </div>
    <button class="portal-btn portal-btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newMeetingModal"><i class="bi bi-plus-lg"></i> Buat konsultasi</button>
</section>

<div class="portal-stat-row mb-3">
    <article class="portal-stat"><span>Permintaan dan jadwal aktif</span><strong>{{ number_format($data_mendatang->total()) }}</strong></article>
    <article class="portal-stat"><span>Riwayat konsultasi</span><strong>{{ number_format($data_riwayat->total()) }}</strong></article>
    <article class="portal-stat"><span>Format layanan</span><strong style="font-size:12px">Ruang audio/video PST</strong></article>
</div>

<section class="mb-4">
    <div class="portal-panel-head px-0 pb-2">
        <div>
            <h2>Jadwal Mendatang</h2>
            <p>Permintaan menunggu persetujuan dan sesi yang sudah dikonfirmasi.</p>
        </div>
    </div>
    <div class="portal-meeting-list">
        @forelse ($data_mendatang as $meeting)
        @php($isConfirmed = (int) $meeting->status === 2)
        <article class="portal-meeting portal-meeting-card {{ $isConfirmed ? 'is-confirmed' : '' }}">
            <div class="portal-meeting-top">
                <div class="portal-meeting-heading">
                    <h3>{{ $meeting->name }}</h3>
                    <span class="portal-status {{ $isConfirmed ? 'is-confirmed' : '' }}"><i class="bi {{ $isConfirmed ? 'bi-check-circle' : 'bi-hourglass-split' }}"></i>{{ $isConfirmed ? 'Terkonfirmasi' : 'Menunggu persetujuan' }}</span>
                </div>
                <p class="portal-meeting-description mb-0">{{ \Illuminate\Support\Str::limit($meeting->description, 150) }}</p>
            </div>
            <div class="portal-meeting-meta mt-3">
                <span><i class="bi bi-calendar3"></i>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}</span>
                <span><i class="bi bi-clock"></i>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</span>
                <span><i class="bi bi-person-badge"></i>{{ $meeting->assigned_staff ?: 'Petugas ditentukan saat persetujuan' }}</span>
            </div>
            @if ($isConfirmed)
                <div class="portal-meeting-actions">
                    <div class="portal-meeting-presence-chat">
                        <span class="portal-room-presence" data-presence-url="{{ route('konsultasi.presence', $meeting) }}" aria-live="polite"><i class="bi bi-arrow-repeat"></i> Memeriksa kehadiran...</span>
                    <button class="portal-btn portal-btn-outline consultation-chat-open" type="button" data-consultation-chat data-chat-url="{{ route('konsultasi.messages', $meeting) }}" data-chat-status-url="{{ route('konsultasi.messages.status', $meeting) }}" data-chat-topic="{{ $meeting->name }}" data-chat-person="{{ $meeting->assigned_staff ?: 'Petugas konsultasi' }}" aria-label="Buka chat konsultasi"><i class="bi bi-chat-dots-fill"></i> Chat<span class="consultation-chat-unread-dot" hidden aria-hidden="true"></span></button>
                    </div>
                    @if ($meeting->room_open)
                        <form method="GET" action="{{ route('konsultasi.room', $meeting) }}"><button class="portal-btn portal-btn-primary portal-meeting-join" type="submit"><i class="bi bi-camera-video-fill"></i> Masuk ruang meeting</button></form>
                    @else
                        <button class="portal-btn portal-btn-outline portal-meeting-join" type="button" disabled><i class="bi bi-lock"></i> Ruang belum dapat diakses</button>
                    @endif
                </div>
                @unless ($meeting->room_open)<p class="portal-meeting-hint"><i class="bi bi-clock me-1"></i>Tombol masuk aktif pada tanggal dan rentang waktu sesi.</p>@endunless
            @else
                <p class="portal-meeting-hint"><i class="bi bi-info-circle me-1"></i>Admin sedang meninjau permintaan Anda.</p>
            @endunless
        </article>
        @empty
        <div class="portal-empty"><i class="bi bi-calendar2-plus"></i><strong>Belum ada permintaan konsultasi</strong><span>Ajukan jadwal baru untuk mulai berkonsultasi dengan petugas BPS.</span></div>
        @endforelse
    </div>
    @if ($data_mendatang->hasPages())<div class="mt-3">{{ $data_mendatang->links() }}</div>@endif
</section>

<section>
    <div class="portal-panel-head px-0 pb-2">
        <div>
            <h2>Riwayat Konsultasi</h2>
            <p>Sesi yang telah selesai atau dibatalkan.</p>
        </div>
    </div>
    <div class="portal-table-wrap">
        <table class="portal-table">
            <thead>
                <tr>
                    <th>Jenis konsultasi</th>
                    <th>Tanggal</th>
                    <th>Waktu</th>
                    <th>Petugas</th>
                    <th>Status</th>
                    <th>Hasil</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($data_riwayat as $meeting)
                <tr>
                    <td><strong>{{ $meeting->name }}</strong><small class="d-block text-muted mt-1">{{ \Illuminate\Support\Str::limit($meeting->description, 90) }}</small></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}</td>
                    <td>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }}</td>
                    <td>{{ $meeting->assigned_staff ?: '—' }}</td>
                    <td><span class="portal-status {{ (int) $meeting->status === 1 ? 'is-done' : 'is-cancelled' }}">{{ (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan' }}</span>@if ((int) $meeting->status === 9 && $meeting->cancellation_reason)<small class="d-block text-muted mt-1">{{ \Illuminate\Support\Str::limit($meeting->cancellation_reason, 70) }}</small>@endif</td>
                    <td>{{ \Illuminate\Support\Str::limit($meeting->ringkasan ?: '—', 95) }}@if ($meeting->documentation_path || $meeting->link_dokumentasi)<a class="d-block mt-1" href="{{ $meeting->link_dokumentasi ?: route('konsultasi.documentation', $meeting) }}" target="_blank" rel="noopener">Buka dokumentasi</a>@endif</td>
                    <td>@if ((int) $meeting->status === 1 && !$meeting->rating)<button class="portal-rate-button" type="button" data-bs-toggle="modal" data-bs-target="#ratingModal{{ $meeting->id }}"><i class="bi bi-star"></i> Beri rating</button>@elseif ($meeting->rating)<span style="color:#bd771a">{{ str_repeat('★', (int) $meeting->rating) }}</span>@else<span class="text-muted">—</span>@endif</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center py-4 text-muted">Riwayat konsultasi akan tampil setelah sesi selesai atau dibatalkan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($data_riwayat->hasPages())<div class="mt-3">{{ $data_riwayat->links() }}</div>@endif
</section>

@foreach ($data_riwayat as $meeting)
@if ((int) $meeting->status === 1 && !$meeting->rating)
<div class="modal fade portal-modal" id="ratingModal{{ $meeting->id }}" tabindex="-1" aria-labelledby="ratingTitle{{ $meeting->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ url('konsultasi_rating/'.$meeting->id) }}">@csrf
                <div class="modal-header">
                    <div><span class="portal-eyebrow">Umpan balik layanan</span>
                        <h2 class="modal-title" id="ratingTitle{{ $meeting->id }}">Nilai konsultasi</h2>
                        <p class="portal-panel-head p-0 m-0">{{ $meeting->name }}</p>
                    </div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <fieldset class="portal-rating-fieldset">
                        <legend>Bagaimana pengalaman Anda?</legend>
                        <div class="portal-star-picker">@for ($star = 5; $star >= 1; $star--)<input id="rating-{{ $meeting->id }}-{{ $star }}" type="radio" name="rating" value="{{ $star }}" required><label for="rating-{{ $meeting->id }}-{{ $star }}" aria-label="{{ $star }} bintang">★</label>@endfor</div>
                    </fieldset><label class="form-label mt-3" for="feedback-{{ $meeting->id }}">Kritik atau saran <span class="text-muted">(opsional)</span></label><textarea class="form-control" id="feedback-{{ $meeting->id }}" name="kritik" rows="4" maxlength="2000" placeholder="Apa yang berjalan baik atau perlu ditingkatkan?"></textarea>
                </div>
                <div class="modal-footer"><button class="portal-btn portal-btn-outline" type="button" data-bs-dismiss="modal">Nanti</button><button class="portal-btn portal-btn-primary" type="submit"><i class="bi bi-send"></i> Kirim penilaian</button></div>
            </form>
        </div>
    </div>
</div>
@endif
@endforeach

<div class="modal fade portal-modal" id="newMeetingModal" data-open-on-load="{{ $errors->any() || old('name') ? 'true' : 'false' }}" tabindex="-1" aria-labelledby="newMeetingTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ url('konsultasi') }}" id="new-meeting-form">
                @csrf
                <div class="modal-header">
                    <div><span class="portal-eyebrow mb-1">Permintaan baru</span>
                        <h2 class="modal-title" id="newMeetingTitle">Ajukan Konsultasi</h2>
                    </div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="portal-form-section mb-3">
                        <h2>Topik konsultasi</h2>
                        <p>Pilih bidang yang paling sesuai dengan kebutuhan Anda.</p><label class="form-label" for="meeting-topic">Jenis konsultasi</label><input class="form-control" type="text" name="name" id="meeting-topic" list="meeting-topics" value="{{ old('name') }}" placeholder="Ketik atau pilih jenis konsultasi" autocomplete="off" required>
                        <datalist id="meeting-topics">
                            @foreach (['Statistik secara umum', 'Rekomendasi Statistik', 'Metodologi Statistik', 'Statistik Sektoral', 'Sains data', 'Data Spasial', 'Data Ekonomi', 'Data Sosial dan Kependudukan', 'Data Pertanian', 'Potensi Desa', 'Ekspor-Impor', 'Harga & Inflasi', 'Tenaga Kerja', 'Kemiskinan', 'Pertumbuhan Ekonomi', 'Statistik Industri', 'Statistik Produksi', 'Indeks Pembangunan Manusia', 'Data Transportasi dan Distribusi'] as $topic)
                            <option value="{{ $topic }}">
                            @endforeach
                        </datalist>
                    </div>
                    <div class="mb-3"><label class="form-label" for="meeting-description">Deskripsi kebutuhan</label><textarea class="form-control" id="meeting-description" name="deskripsi" rows="4" minlength="10" maxlength="3000" placeholder="Ceritakan data, indikator, atau metodologi yang ingin dikonsultasikan..." required>{{ old('deskripsi') }}</textarea><small class="form-text text-muted">Minimal 10 karakter. Jelaskan konteks pertanyaan agar petugas dapat menyiapkan konsultasi.</small></div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="meeting-date">Tanggal konsultasi</label><input class="form-control" id="meeting-date" type="date" name="tanggal" min="{{ now('Asia/Jakarta')->toDateString() }}" value="{{ old('tanggal') }}" required></div>
                        <div class="col-md-4"><label class="form-label" for="meeting-start">Waktu mulai</label><select class="form-select" id="meeting-start" name="waktu_mulai" required disabled>
                                <option value="">Pilih waktu</option>@foreach (['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'] as $time)<option value="{{ $time }}" @selected(old('waktu_mulai')===$time)>{{ $time }} WIB</option>@endforeach
                            </select><small class="form-text text-muted">Untuk tanggal hari ini, waktu yang sudah lewat tidak tersedia.</small></div>
                        <div class="col-md-4"><label class="form-label" for="meeting-end">Waktu selesai</label><input class="form-control" id="meeting-end" name="waktu_selesai" type="time" value="{{ old('waktu_selesai') }}" required readonly><small class="form-text text-muted">Terisi otomatis 45 menit setelah waktu mulai.</small></div>
                    </div>
                    <div class="portal-chat-note mt-3"><i class="bi bi-info-circle me-1"></i>Permintaan akan berstatus menunggu sampai admin menyetujui dan menetapkan petugas.</div>
                </div>
                <div class="modal-footer"><button class="portal-btn portal-btn-outline" type="button" data-bs-dismiss="modal">Batal</button><button class="portal-btn portal-btn-primary" type="submit"><i class="bi bi-send"></i> Kirim permintaan</button></div>
            </form>
        </div>
    </div>
</div>
@include('konsultasi.partials.text-chat-modal')

@endsection

@section('scripts')
<script>
    (() => {
        const form = document.getElementById('new-meeting-form');
        const date = document.getElementById('meeting-date');
        const start = document.getElementById('meeting-start');
        const end = document.getElementById('meeting-end');
        const jakartaDateTime = () => {
            const parts = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Jakarta',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }).formatToParts(new Date()).reduce((values, part) => {
                values[part.type] = part.value;
                return values;
            }, {});
            return {
                date: `${parts.year}-${parts.month}-${parts.day}`,
                time: `${parts.hour}:${parts.minute}`,
            };
        };
        const syncEndTime = () => {
            end.setCustomValidity('');
            if (!start.value) {
                end.value = '';
                return;
            }

            const [hours, minutes] = start.value.split(':').map(Number);
            const endMinutes = hours * 60 + minutes + 45;
            end.value = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
        };
        const updateStartTimes = () => {
            const now = jakartaDateTime();
            const selectedDate = date.value;
            start.disabled = !selectedDate || selectedDate < now.date;
            [...start.options].forEach((option) => {
                option.disabled = Boolean(option.value) && (
                    selectedDate < now.date || (selectedDate === now.date && option.value <= now.time)
                );
            });
            if (start.selectedOptions[0]?.disabled) start.value = '';
            syncEndTime();
        };
        date.addEventListener('change', updateStartTimes);
        start.addEventListener('change', updateStartTimes);
        window.addEventListener('focus', updateStartTimes);
        window.setInterval(updateStartTimes, 30000);
        form.addEventListener('submit', (event) => {
            if (!start.value || !end.value) {
                event.preventDefault();
                start.setCustomValidity('Pilih waktu mulai untuk mengisi waktu selesai secara otomatis.');
                start.reportValidity();
            } else {
                start.setCustomValidity('');
            }
        });
        start.addEventListener('change', () => start.setCustomValidity(''));
        updateStartTimes();

        if (document.getElementById('newMeetingModal').dataset.openOnLoad === 'true') {
            new bootstrap.Modal(document.getElementById('newMeetingModal')).show();
        }
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
                    ? '<i class="bi bi-person-check-fill"></i> Petugas sudah masuk ruang'
                    : '<i class="bi bi-hourglass-split"></i> Belum ada petugas di ruang';
            } catch {
                indicator.classList.remove('is-present');
                indicator.innerHTML = '<i class="bi bi-arrow-repeat"></i> Status ruang belum tersedia';
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