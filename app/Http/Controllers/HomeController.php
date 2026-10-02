<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Meeting;
use Illuminate\Support\Carbon;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('periode') === 'tahun' ? 'tahun' : 'bulan';
        $year = max(2020, min((int) $request->query('tahun', now()->year), now()->year));
        $month = max(1, min((int) $request->query('bulan', now()->month), 12));
        $rangeStart = Carbon::create($year, $month, 1)->startOfDay();
        $rangeEnd = $period === 'tahun' ? $rangeStart->copy()->endOfYear() : $rangeStart->copy()->endOfMonth();
        $baseMeetings = Meeting::whereBetween('tanggal', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);

        $monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $trendLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $trendCounts = array_fill(1, 12, 0);
        foreach (Meeting::whereYear('tanggal', $year)->get(['tanggal']) as $meeting) {
            $trendCounts[(int) Carbon::parse($meeting->tanggal)->format('n')]++;
        }
        $periodLabel = $period === 'tahun' ? "Tahun {$year}" : $monthNames[$month - 1]." {$year}";

        $ratingFilter = (int) $request->query('rating', 0);
        if ($ratingFilter < 1 || $ratingFilter > 5) {
            $ratingFilter = 0;
        }
        $reviewSort = $request->query('urut') === 'lama' ? 'lama' : 'baru';
        $reviewBase = (clone $baseMeetings)->whereNotNull('kritik_saran');
        $reviewCounts = (clone $reviewBase)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $reviewsQuery = (clone $reviewBase)->with('user:id,name');
        if ($ratingFilter) {
            $reviewsQuery->where('rating', $ratingFilter);
        }
        $reviews = $reviewsQuery
            ->orderBy($reviewSort === 'baru' ? 'id' : 'tanggal', $reviewSort === 'baru' ? 'desc' : 'asc')
            ->limit(6)
            ->get();

        $topicRows = (clone $baseMeetings)->selectRaw('name, COUNT(*) as total')->groupBy('name')->orderByDesc('total')->limit(5)->get();
        $jobRows = User::query()->whereNotNull('pekerjaan')->where('pekerjaan', '!=', '')
            ->selectRaw('pekerjaan, COUNT(*) as total')->groupBy('pekerjaan')->orderByDesc('total')->limit(5)->get();

        $topics = $topicRows->map(fn ($row) => ['label' => $row->name, 'value' => (int) $row->total])->values();
        $jobs = $jobRows->map(fn ($row) => ['label' => $row->pekerjaan, 'value' => (int) $row->total])->values();
        $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#0891b2'];

        $data = [
            'jml_user' => User::whereHas('roles', fn ($query) => $query->where('name', 'user'))
                ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
                ->count(),
            'jml_admin' => User::role(['admin', 'super_admin'])->count(),
            'total' => (clone $baseMeetings)->count(),
            'menunggu' => (clone $baseMeetings)->where('status', 0)->count(),
            'selesai' => (clone $baseMeetings)->where('status', 1)->count(),
            'dibatalkan' => (clone $baseMeetings)->where('status', 9)->count(),
            'reviews' => $reviews,
            'review_total' => (clone $reviewBase)->count(),
            'review_counts' => $reviewCounts,
            'average_rating' => (clone $reviewBase)->whereNotNull('rating')->avg('rating'),
            'topic_distribution' => $topicRows,
            'job_distribution' => $jobRows,
        ];

        $chart_data = [
            'labels' => $trendLabels,
            'datasets' => [[
                'label' => 'Konsultasi',
                'data' => array_values($trendCounts),
                'borderWidth' => 2,
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                'borderColor' => '#2563eb',
                'pointBackgroundColor' => '#ffffff',
                'pointBorderColor' => '#2563eb',
                'fill' => true,
            ]],
        ];

        $donut_data = [
            'topics' => ['labels' => $topics->pluck('label'), 'data' => $topics->pluck('value'), 'colors' => array_slice($palette, 0, max(1, $topics->count()))],
            'jobs' => ['labels' => $jobs->pluck('label'), 'data' => $jobs->pluck('value'), 'colors' => array_slice(['#0891b2', '#059669', '#d97706', '#64748b', '#2563eb'], 0, max(1, $jobs->count()))],
        ];

        return view('admin.dashboard', compact('data', 'chart_data', 'donut_data', 'period', 'year', 'month', 'periodLabel', 'ratingFilter', 'reviewSort'));
    }
}
