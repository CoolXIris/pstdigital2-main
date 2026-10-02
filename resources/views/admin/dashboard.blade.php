@extends('layout.admin-rebrand')

@section('title', 'Dashboard | PST Digital')
@section('topbar_title', 'Dashboard layanan')

@section('content')
    <section class="admin-page-heading admin-dashboard-heading">
        <div>
            <span class="admin-eyebrow">Pusat kendali layanan</span>
            <h1>Dashboard PST DIGITAL</h1>
            <p>Periode: {{ $periodLabel }}</p>
        </div>
    </section>

    <form class="admin-filter-bar admin-dashboard-filters" method="GET" action="{{ route('dashboard') }}">
        <div class="admin-filter-field">
            <label for="periode">Jenis Periode</label>
            <select class="form-select" id="periode" name="periode">
                <option value="bulan" @selected($period === 'bulan')>Bulanan</option>
                <option value="tahun" @selected($period === 'tahun')>Tahunan</option>
            </select>
        </div>
        <div class="admin-filter-field" id="month-filter">
            <label for="bulan">Bulan</label>
            <select class="form-select" id="bulan" name="bulan">
                @foreach ([1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'] as $monthNumber => $monthName)
                    <option value="{{ $monthNumber }}" @selected($month === $monthNumber)>{{ $monthName }}</option>
                @endforeach
            </select>
        </div>
        <div class="admin-filter-field">
            <label for="tahun">Tahun</label>
            <select class="form-select" id="tahun" name="tahun">
                @for ($availableYear = now()->year; $availableYear >= now()->year - 5; $availableYear--)
                    <option value="{{ $availableYear }}" @selected($year === $availableYear)>{{ $availableYear }}</option>
                @endfor
            </select>
        </div>
        <button class="admin-btn admin-btn-primary" type="submit">Terapkan</button>
    </form>

    <div class="admin-dashboard-stats">
        <article class="admin-stat admin-stat-blue"><div><strong class="admin-stat-value">{{ number_format($data['jml_user']) }}</strong><span class="admin-stat-label">Pengguna Aktif</span></div><span class="admin-stat-icon"><i class="fe fe-users"></i></span></article>
        <article class="admin-stat admin-stat-blue"><div><strong class="admin-stat-value">{{ number_format($data['total']) }}</strong><span class="admin-stat-label">Total Konsultasi</span></div><span class="admin-stat-icon"><i class="fe fe-calendar"></i></span></article>
        <article class="admin-stat admin-stat-red"><div><strong class="admin-stat-value">{{ number_format($data['menunggu']) }}</strong><span class="admin-stat-label">Konsultasi Aktif</span></div><span class="admin-stat-icon"><i class="fe fe-alert-circle"></i></span></article>
        <article class="admin-stat admin-stat-amber"><div><strong class="admin-stat-value">{{ number_format($data['dibatalkan']) }}</strong><span class="admin-stat-label">Konsultasi Dibatalkan</span></div><span class="admin-stat-icon"><i class="fe fe-clock"></i></span></article>
        <article class="admin-stat admin-stat-green"><div><strong class="admin-stat-value">{{ number_format($data['selesai']) }}</strong><span class="admin-stat-label">Konsultasi Selesai</span></div><span class="admin-stat-icon"><i class="fe fe-check-circle"></i></span></article>
    </div>

    <section class="admin-panel admin-dashboard-trend mb-3">
        <div class="admin-panel-head"><div><h2 class="admin-panel-title">Tren Aktivitas Layanan</h2><p class="admin-panel-subtitle">Jumlah konsultasi per bulan selama {{ $year }}.</p></div></div>
        <div class="admin-panel-body"><div class="admin-dashboard-line-chart"><canvas id="consultationTrend" data-chart="{{ json_encode($chart_data) }}" aria-label="Grafik jumlah konsultasi per bulan" role="img"></canvas></div><div class="admin-chart-legend"><span></span>Konsultasi</div></div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <section class="admin-panel admin-dashboard-donut h-100">
                <div class="admin-panel-head"><div><h2 class="admin-panel-title">Distribusi Konsultasi per Jenis</h2><p class="admin-panel-subtitle">Konsultasi pada {{ $periodLabel }} berdasarkan topik.</p></div></div>
                <div class="admin-panel-body"><div class="admin-dashboard-donut-chart"><canvas id="topicDistribution" data-chart="{{ json_encode($donut_data['topics']) }}" aria-label="Distribusi konsultasi menurut topik" role="img"></canvas></div></div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="admin-panel admin-dashboard-donut h-100">
                <div class="admin-panel-head"><div><h2 class="admin-panel-title">Distribusi Pengguna per Kategori</h2><p class="admin-panel-subtitle">Pengguna berdasarkan kategori pekerjaan yang tercatat.</p></div></div>
                <div class="admin-panel-body"><div class="admin-dashboard-donut-chart"><canvas id="jobDistribution" data-chart="{{ json_encode($donut_data['jobs']) }}" aria-label="Distribusi pengguna menurut pekerjaan" role="img"></canvas></div></div>
            </section>
        </div>
    </div>

    <section class="admin-panel admin-dashboard-reviews">
        <div class="admin-panel-head admin-review-heading">
            <div><h2 class="admin-panel-title">Kritik dan Saran Pengguna</h2><div class="admin-dashboard-rating"><span>★</span><strong>{{ $data['average_rating'] !== null ? number_format($data['average_rating'], 1) : '-' }}</strong><small>rata-rata dari {{ number_format($data['review_total']) }} ulasan</small></div></div>
            <form method="GET" action="{{ route('dashboard') }}" class="admin-review-sort">
                <input type="hidden" name="periode" value="{{ $period }}"><input type="hidden" name="bulan" value="{{ $month }}"><input type="hidden" name="tahun" value="{{ $year }}"><input type="hidden" name="rating" value="{{ $ratingFilter }}">
                <label class="visually-hidden" for="urut">Urutan ulasan</label><select class="form-select" id="urut" name="urut" onchange="this.form.submit()"><option value="baru" @selected($reviewSort === 'baru')>Terbaru</option><option value="lama" @selected($reviewSort === 'lama')>Terlama</option></select>
            </form>
        </div>
        <div class="admin-panel-body pt-2">
            <nav class="admin-review-tabs" aria-label="Filter rating ulasan">
                <a class="{{ $ratingFilter === 0 ? 'is-active' : '' }}" href="{{ route('dashboard', ['periode' => $period, 'bulan' => $month, 'tahun' => $year, 'urut' => $reviewSort]) }}">Semua ({{ $data['review_total'] }})</a>
                @for ($star = 5; $star >= 1; $star--)
                    <a class="{{ $ratingFilter === $star ? 'is-active' : '' }}" href="{{ route('dashboard', ['periode' => $period, 'bulan' => $month, 'tahun' => $year, 'urut' => $reviewSort, 'rating' => $star]) }}">Bintang {{ $star }} ({{ $data['review_counts']->get($star, 0) }})</a>
                @endfor
            </nav>
            <div class="admin-review-list">
                @forelse ($data['reviews'] as $review)
                    <article class="admin-review">
                        <div class="admin-review-meta"><span>{{ \Illuminate\Support\Carbon::parse($review->tanggal)->translatedFormat('d M Y') }} · {{ $review->user?->name ?? 'Pengguna' }}</span><span class="admin-stars">{{ str_repeat('★', (int) $review->rating) }}{{ str_repeat('☆', max(0, 5 - (int) $review->rating)) }}</span></div>
                        <p>{{ $review->kritik_saran }}</p>
                    </article>
                @empty
                    <div class="admin-empty"><i class="fe fe-message-circle"></i><strong>Belum ada ulasan pada filter ini</strong><span>Ulasan akan muncul setelah konsultasi dinilai oleh pengguna.</span></div>
                @endforelse
            </div>
        </div>
    </section>
@endsection

@section('scripts')
<script>
    (() => {
        const periodSelect = document.getElementById('periode');
        const monthFilter = document.getElementById('month-filter');
        const syncMonthFilter = () => { monthFilter.hidden = periodSelect.value === 'tahun'; };
        periodSelect.addEventListener('change', syncMonthFilter);
        syncMonthFilter();

        const renderChart = (id, type, options) => {
            const canvas = document.getElementById(id);
            if (!canvas || !window.Chart) return;
            const chart = JSON.parse(canvas.dataset.chart);
            new Chart(canvas.getContext('2d'), {
                type,
                data: type === 'doughnut' ? { labels: chart.labels, datasets: [{ data: chart.data, backgroundColor: chart.colors, borderColor: '#fff', borderWidth: 2 }] } : chart,
                options
            });
        };

        renderChart('consultationTrend', 'line', {
            responsive: true, maintainAspectRatio: false, legend: { display: false },
            tooltips: { intersect: false, mode: 'index' },
            scales: {
                yAxes: [{ ticks: { beginAtZero: true, precision: 0, fontColor: '#7c8998' }, gridLines: { color: '#e9edf2', drawBorder: false } }],
                xAxes: [{ ticks: { fontColor: '#7c8998' }, gridLines: { display: false } }]
            }
        });
        const donutOptions = { responsive: true, maintainAspectRatio: false, cutoutPercentage: 48, legend: { position: 'bottom', labels: { boxWidth: 22, padding: 10, fontSize: 10, fontColor: '#66768a' } } };
        renderChart('topicDistribution', 'doughnut', donutOptions);
        renderChart('jobDistribution', 'doughnut', donutOptions);
    })();
</script>
@endsection
