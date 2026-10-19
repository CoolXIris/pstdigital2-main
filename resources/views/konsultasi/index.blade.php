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
                <div class="portal-meeting-description-wrap" style="display: flex; justify-content: flex-end;"><button class="portal-detail-button" type="button" data-bs-toggle="modal" data-bs-target="#meetingDetailModal{{ $meeting->id }}"><i class="bi bi-eye"></i> Detail</button></div>
            </div>
            <div class="portal-meeting-meta mt-3">
                <span><i class="bi bi-calendar3"></i>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}</span>
                <span><i class="bi bi-clock"></i>{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</span>
                <span><i class="bi bi-person-badge"></i>{{ $meeting->assigned_staff ?: 'Petugas ditentukan saat persetujuan' }}</span>
            </div>
            @if ($isConfirmed)
                <div class="portal-meeting-actions" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">

                    <!-- Bagian 1: Teks Status (Otomatis menetap di paling kiri) -->
                    <span class="portal-room-presence" data-presence-url="{{ route('konsultasi.presence', $meeting) }}" aria-live="polite">
                        <i class="bi bi-arrow-repeat"></i> Status ruang belum tersedia
                    </span>

                    <!-- Bagian 2: Grup Tombol (Otomatis bersanding menetap di paling kanan) -->
                    <div class="portal-meeting-presence-chat" style="display: flex; gap: 10px; align-items: center;">

                        <!-- Tombol Chat -->
                        <button class="portal-btn portal-btn-outline consultation-chat-open" type="button" data-consultation-chat data-chat-url="{{ route('konsultasi.messages', $meeting) }}" data-chat-status-url="{{ route('konsultasi.messages.status', $meeting) }}" data-chat-topic="{{ $meeting->name }}" data-chat-person="{{ $meeting->assigned_staff ?: 'Petugas konsultasi' }}" aria-label="Buka chat konsultasi">
                            <i class="bi bi-chat-dots-fill"></i> Chat<span class="consultation-chat-unread-dot" hidden aria-hidden="true"></span>
                        </button>

                        <!-- Tombol Masuk / Status Ruang -->
                        @if ($meeting->room_open)
                            <form method="GET" action="{{ route('konsultasi.room', $meeting) }}" style="margin: 0;">
                                <button class="portal-btn portal-btn-primary portal-meeting-join" type="submit">
                                    <i class="bi bi-camera-video-fill"></i> Masuk ruang meeting
                                </button>
                            </form>
                        @else
                            <button class="portal-btn portal-btn-outline portal-meeting-join" type="button" disabled>
                                <i class="bi bi-lock"></i> Ruang belum dapat diakses
                            </button>
                        @endif

                    </div>
                </div>
                @unless ($meeting->room_open)<p class="portal-meeting-hint"><i class="bi bi-clock me-1"></i>Tombol masuk aktif pada tanggal dan rentang waktu sesi.</p>@endunless
            @else
                <p class="portal-meeting-hint"><i class="bi bi-info-circle me-1"></i>Admin sedang meninjau permintaan Anda.</p>
            @endunless
        </article>
        <div class="modal fade portal-modal" id="meetingDetailModal{{ $meeting->id }}" tabindex="-1" aria-labelledby="meetingDetailTitle{{ $meeting->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><div><span class="portal-eyebrow">Detail konsultasi</span><h2 class="modal-title" id="meetingDetailTitle{{ $meeting->id }}">{{ $meeting->name }}</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
                <div class="modal-body"><dl class="portal-meeting-detail-list"><div><dt>Jadwal</dt><dd>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}, {{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</dd></div><div><dt>Petugas</dt><dd>{{ $meeting->assigned_staff ?: 'Belum ditentukan' }}</dd></div><div><dt>Status</dt><dd>{{ $isConfirmed ? 'Terkonfirmasi' : 'Menunggu persetujuan' }}</dd></div><div><dt>Deskripsi kebutuhan</dt><dd class="portal-detail-long-text">{{ $meeting->description }}</dd></div></dl></div>
            </div></div>
        </div>
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
        <table class="portal-table portal-history-table">
            <thead><tr><th>Rating</th><th>Konsultasi</th><th>Jadwal</th><th>Petugas</th><th>Status</th><th>Riwayat chat</th></tr></thead>
            <tbody>
                @forelse ($data_riwayat as $meeting)
                <tr>
                    <td>@if ((int) $meeting->status === 1 && !$meeting->rating)<button class="portal-rate-button" type="button" data-bs-toggle="modal" data-bs-target="#ratingModal{{ $meeting->id }}"><i class="bi bi-star"></i> Beri rating</button>@elseif ($meeting->rating)<span class="portal-rating-stars" aria-label="Rating {{ (int) $meeting->rating }} dari 5">{{ str_repeat('★', (int) $meeting->rating) }}</span>@else<span class="text-muted">—</span>@endif</td>
                    <td><strong>{{ $meeting->name }}</strong><button class="portal-detail-button d-flex mt-2" type="button" data-bs-toggle="modal" data-bs-target="#meetingDetailModal{{ $meeting->id }}"><i class="bi bi-eye"></i> Detail</button></td>
                    <td><span class="d-block">{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('d M Y') }}</span><small class="text-muted">{{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</small></td>
                    <td>{{ $meeting->assigned_staff ?: '—' }}</td>
                    <td><span class="portal-status {{ (int) $meeting->status === 1 ? 'is-done' : 'is-cancelled' }}">{{ (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan' }}</span></td>
                    <td>@if ((int) $meeting->status === 1)<button class="portal-btn portal-btn-outline portal-history-button" type="button" data-consultation-history data-history-url="{{ route('konsultasi.messages.history', $meeting) }}" data-chat-topic="{{ $meeting->name }}"><i class="bi bi-chat-left-text"></i> Riwayat chat</button>@else<span class="text-muted">—</span>@endif</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center py-4 text-muted">Riwayat konsultasi akan tampil setelah sesi selesai atau dibatalkan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($data_riwayat->hasPages())<div class="mt-3">{{ $data_riwayat->links() }}</div>@endif
</section>

@php($pendingRatingId = $data_riwayat->getCollection()->first(fn ($item) => (int) $item->status === 1 && !$item->rating)?->id)
@foreach ($data_riwayat as $meeting)
<div class="modal fade portal-modal" id="meetingDetailModal{{ $meeting->id }}" tabindex="-1" aria-labelledby="meetingDetailTitle{{ $meeting->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><div><span class="portal-eyebrow">Detail konsultasi</span><h2 class="modal-title" id="meetingDetailTitle{{ $meeting->id }}">{{ $meeting->name }}</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body"><dl class="portal-meeting-detail-list">
            <div><dt>Jadwal</dt><dd>{{ \Illuminate\Support\Carbon::parse($meeting->tanggal)->translatedFormat('l, d F Y') }}, {{ substr($meeting->start_time, 0, 5) }}–{{ substr($meeting->end_time, 0, 5) }} WIB</dd></div>
            <div><dt>Petugas</dt><dd>{{ $meeting->assigned_staff ?: 'Belum ditentukan' }}</dd></div>
            <div><dt>Status</dt><dd>{{ (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan' }}</dd></div>
            <div><dt>Deskripsi kebutuhan</dt><dd class="portal-detail-long-text">{{ $meeting->description }}</dd></div>
            @if ($meeting->ringkasan)<div><dt>Ringkasan hasil</dt><dd class="portal-detail-long-text">{{ $meeting->ringkasan }}</dd></div>@endif
            @if ((int) $meeting->status === 9 && $meeting->cancellation_reason)<div><dt>Alasan pembatalan</dt><dd class="portal-detail-long-text">{{ $meeting->cancellation_reason }}</dd></div>@endif
            @if ($meeting->rating)<div><dt>Penilaian Anda</dt><dd><span class="portal-rating-stars">{{ str_repeat('★', (int) $meeting->rating) }}</span>@if ($meeting->kritik_saran)<p class="mb-0 mt-1">{{ $meeting->kritik_saran }}</p>@endif</dd></div>@endif
        </dl></div>
    </div></div>
</div>
@if ((int) $meeting->status === 1 && !$meeting->rating)
<div class="modal fade portal-modal" id="ratingModal{{ $meeting->id }}" data-open-on-load="{{ (int) $meeting->id === (int) $pendingRatingId ? 'true' : 'false' }}" tabindex="-1" aria-labelledby="ratingTitle{{ $meeting->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ url('konsultasi_rating/'.$meeting->id) }}">@csrf
                <div class="modal-header">
                    <div><span class="portal-eyebrow">Umpan balik layanan</span>
                        <h2 class="modal-title" id="ratingTitle{{ $meeting->id }}">Bagaimana sesi konsultasi Anda?</h2>
                        <p class="portal-panel-head p-0 m-0">{{ $meeting->name }}</p>
                    </div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Sesi ini sudah selesai. Bagikan penilaian dan saran Anda untuk membantu kami meningkatkan layanan.</p>
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
                        <p>Pilih bidang yang paling sesuai dengan kebutuhan Anda.</p><label class="form-label" for="meeting-topic">Jenis konsultasi</label><select class="form-select" name="name" id="meeting-topic" required>
                            <option value="">Pilih jenis konsultasi</option>
                            @foreach (['Statistik secara umum', 'Rekomendasi Statistik', 'Metodologi Statistik', 'Statistik Sektoral', 'Sains data', 'Data Spasial', 'Data Ekonomi', 'Data Sosial dan Kependudukan', 'Data Pertanian', 'Potensi Desa', 'Ekspor-Impor', 'Harga & Inflasi', 'Tenaga Kerja', 'Kemiskinan', 'Pertumbuhan Ekonomi', 'Statistik Industri', 'Statistik Produksi', 'Indeks Pembangunan Manusia', 'Data Transportasi dan Distribusi'] as $topic)
                            <option value="{{ $topic }}" @selected(old('name')===$topic)>{{ $topic }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label" for="meeting-description">Deskripsi kebutuhan</label><textarea class="form-control" id="meeting-description" name="deskripsi" rows="4" minlength="50" maxlength="3000" placeholder="Ceritakan data, indikator, atau metodologi yang ingin dikonsultasikan..." required>{{ old('deskripsi') }}</textarea><small class="form-text text-muted">Tuliskan minimal 50 karakter agar petugas dapat memahami kebutuhan konsultasi Anda.</small></div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="meeting-date-display">Tanggal konsultasi</label>
                            <div class="portal-date-picker" id="consultation-date-picker" data-today="{{ $jakartaToday }}" data-now-time="{{ $jakartaNowTime }}" data-holidays='@json($consultationHolidays)'>
                                <div class="portal-date-picker-control"><input class="form-control" id="meeting-date-display" type="text" placeholder="Pilih hari kerja" readonly aria-haspopup="dialog" aria-expanded="false"><button class="portal-date-picker-toggle" type="button" aria-label="Buka kalender"><i class="bi bi-calendar3"></i></button></div>
                                <input id="meeting-date" type="hidden" name="tanggal" value="{{ old('tanggal') }}">
                                <div class="portal-calendar" id="consultation-calendar" role="dialog" aria-label="Pilih tanggal konsultasi" hidden>
                                    <div class="portal-calendar-header"><button type="button" data-calendar-prev aria-label="Bulan sebelumnya"><i class="bi bi-chevron-left"></i></button><strong data-calendar-month></strong><button type="button" data-calendar-next aria-label="Bulan berikutnya"><i class="bi bi-chevron-right"></i></button></div>
                                    <div class="portal-calendar-weekdays" aria-hidden="true"><span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span></div>
                                    <div class="portal-calendar-days" data-calendar-days></div>
                                    <small class="portal-calendar-note">Akhir pekan, hari libur nasional, dan cuti bersama tidak tersedia.</small>
                                </div>
                                <small class="form-text text-muted" data-calendar-message>Pilih tanggal pada hari kerja.</small>
                            </div>
                        </div>
                        <div class="col-md-4"><label class="form-label" for="meeting-start">Waktu mulai</label><select class="form-select" id="meeting-start" name="waktu_mulai" required>
                                <option value="">Pilih waktu mulai</option>@foreach ($consultationStartTimes as $time)<option value="{{ $time }}" @selected(old('waktu_mulai')===$time)>{{ $time }} WIB</option>@endforeach
                            </select></div>
                        <div class="col-md-4"><label class="form-label" for="meeting-end">Waktu selesai otomatis</label><input class="form-control" id="meeting-end" type="text" placeholder="Pilih waktu mulai" readonly aria-describedby="meeting-end-help"><small class="form-text text-muted" id="meeting-end-help">Durasi sesi konsultasi {{ $consultationDuration }} menit.</small></div>
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
        const start = document.getElementById('meeting-start');
        const end = document.getElementById('meeting-end');
        const datePicker = document.getElementById('consultation-date-picker');
        const dateInput = document.getElementById('meeting-date');
        const dateDisplay = document.getElementById('meeting-date-display');
        const calendar = document.getElementById('consultation-calendar');
        const calendarDays = calendar.querySelector('[data-calendar-days]');
        const calendarMonth = calendar.querySelector('[data-calendar-month]');
        const calendarMessage = datePicker.querySelector('[data-calendar-message]');
        const holidays = new Set(JSON.parse(datePicker.dataset.holidays));
        const today = datePicker.dataset.today;
        const nowTime = datePicker.dataset.nowTime;
        const duration = {{ (int) $consultationDuration }};
        const asDate = (iso) => {
            const [year, month, day] = iso.split('-').map(Number);
            return new Date(Date.UTC(year, month - 1, day));
        };
        const toIso = (date) => [date.getUTCFullYear(), String(date.getUTCMonth() + 1).padStart(2, '0'), String(date.getUTCDate()).padStart(2, '0')].join('-');
        const isValidIsoDate = (iso) => /^\d{4}-\d{2}-\d{2}$/.test(iso) && toIso(asDate(iso)) === iso;
        const isWeekend = (iso) => isValidIsoDate(iso) && [0, 6].includes(asDate(iso).getUTCDay());
        const isUnavailable = (iso) => !isValidIsoDate(iso) || iso < today || isWeekend(iso) || holidays.has(iso);
        const formatDate = (iso) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(asDate(iso));
        let visibleMonth = dateInput.value && !isUnavailable(dateInput.value) ? asDate(dateInput.value) : asDate(today);
        visibleMonth.setUTCDate(1);

        const setCalendarOpen = (open) => {
            calendar.hidden = !open;
            dateDisplay.setAttribute('aria-expanded', String(open));
        };
        const selectDate = (iso) => {
            if (isUnavailable(iso)) return;
            dateInput.value = iso;
            dateDisplay.value = formatDate(iso);
            calendarMessage.textContent = 'Tanggal tersedia untuk konsultasi.';
            calendarMessage.classList.remove('text-danger');
            dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            setCalendarOpen(false);
        };
        const renderCalendar = () => {
            const year = visibleMonth.getUTCFullYear();
            const month = visibleMonth.getUTCMonth();
            calendarMonth.textContent = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(visibleMonth);
            calendarDays.replaceChildren();
            const firstWeekday = (new Date(Date.UTC(year, month, 1)).getUTCDay() + 6) % 7;
            const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
            for (let blank = 0; blank < firstWeekday; blank++) {
                const spacer = document.createElement('span');
                spacer.className = 'portal-calendar-spacer';
                calendarDays.append(spacer);
            }
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(Date.UTC(year, month, day));
                const iso = toIso(date);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'portal-calendar-day';
                button.textContent = String(day);
                button.disabled = isUnavailable(iso);
                if (iso === dateInput.value) button.classList.add('is-selected');
                if (iso === today) button.classList.add('is-today');
                if (button.disabled) button.title = iso < today ? 'Tanggal sudah lewat' : (isWeekend(iso) ? 'Akhir pekan' : 'Hari libur atau cuti bersama');
                button.addEventListener('click', () => selectDate(iso));
                calendarDays.append(button);
            }
            const minMonth = asDate(today);
            minMonth.setUTCDate(1);
            calendar.querySelector('[data-calendar-prev]').disabled = visibleMonth <= minMonth;
        };
        const positionCalendar = () => {
            if (calendar.hidden) return;
            const anchor = dateDisplay.closest('.portal-date-picker-control').getBoundingClientRect();
            const width = Math.min(320, window.innerWidth - 24);
            const left = Math.max(12, Math.min(anchor.left, window.innerWidth - width - 12));
            calendar.style.width = `${width}px`;
            calendar.style.left = `${left}px`;
            const below = anchor.bottom + 7;
            calendar.style.top = `${below + calendar.offsetHeight <= window.innerHeight - 12 ? below : Math.max(12, anchor.top - calendar.offsetHeight - 7)}px`;
        };
        const openCalendar = () => { renderCalendar(); setCalendarOpen(true); positionCalendar(); };
        dateDisplay.addEventListener('click', openCalendar);
        datePicker.querySelector('.portal-date-picker-toggle').addEventListener('click', () => {
            if (calendar.hidden) openCalendar(); else setCalendarOpen(false);
        });
        calendar.querySelector('[data-calendar-prev]').addEventListener('click', () => {
            visibleMonth.setUTCMonth(visibleMonth.getUTCMonth() - 1);
            renderCalendar();
        });
        calendar.querySelector('[data-calendar-next]').addEventListener('click', () => {
            visibleMonth.setUTCMonth(visibleMonth.getUTCMonth() + 1);
            renderCalendar();
        });
        document.addEventListener('click', (event) => {
            if (!datePicker.contains(event.target) && !calendar.contains(event.target)) setCalendarOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setCalendarOpen(false);
        });
        window.addEventListener('resize', positionCalendar);
        document.addEventListener('scroll', positionCalendar, true);
        document.getElementById('newMeetingModal')?.addEventListener('hidden.bs.modal', () => setCalendarOpen(false));
        if (dateInput.value && !isUnavailable(dateInput.value)) dateDisplay.value = formatDate(dateInput.value);

        const updateTimes = () => {
            const selectedDate = dateInput.value;
            [...start.options].forEach((option) => {
                if (!option.value) return;
                option.disabled = !selectedDate || isUnavailable(selectedDate) || (selectedDate === today && option.value <= nowTime);
            });
            if (start.selectedOptions[0]?.disabled) start.value = '';
            if (!start.value) {
                end.value = '';
                end.placeholder = 'Pilih waktu mulai';
                return;
            }
            const [hour, minute] = start.value.split(':').map(Number);
            const endMinutes = hour * 60 + minute + duration;
            end.value = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')} WIB`;
        };
        dateInput.addEventListener('change', updateTimes);
        start.addEventListener('change', updateTimes);
        updateTimes();
        form.addEventListener('submit', (event) => {
            if (!dateInput.value || isUnavailable(dateInput.value)) {
                event.preventDefault();
                calendarMessage.textContent = 'Silakan pilih tanggal hari kerja yang tersedia.';
                calendarMessage.classList.add('text-danger');
                openCalendar();
                dateDisplay.focus();
                return;
            }
            if (!start.value || !end.value) {
                event.preventDefault();
                start.reportValidity();
            }
        });

        const pendingRating = document.querySelector('[id^="ratingModal"][data-open-on-load="true"]');
        if (pendingRating) {
            new bootstrap.Modal(pendingRating).show();
        } else if (document.getElementById('newMeetingModal').dataset.openOnLoad === 'true') {
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
