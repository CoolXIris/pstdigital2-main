<?php

namespace App\Exports;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DashboardReviewsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        private readonly string $period,
        private readonly int $year,
        private readonly int $month,
        private readonly int $rating,
        private readonly string $sort,
    ) {}

    public function query(): Builder
    {
        $rangeStart = Carbon::create($this->year, $this->month, 1)->startOfDay();
        $rangeEnd = $this->period === 'tahun'
            ? $rangeStart->copy()->endOfYear()
            : $rangeStart->copy()->endOfMonth();

        return Meeting::query()
            ->with('user:id,name,email')
            ->whereBetween('tanggal', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->whereNotNull('kritik_saran')
            ->when($this->rating > 0, fn (Builder $query) => $query->where('rating', $this->rating))
            ->orderBy($this->sort === 'baru' ? 'id' : 'tanggal', $this->sort === 'baru' ? 'desc' : 'asc');
    }

    public function map($meeting): array
    {
        return [
            Carbon::parse($meeting->tanggal)->format('Y-m-d'),
            $this->spreadsheetText($meeting->user?->name ?? 'Pengguna'),
            $this->spreadsheetText($meeting->user?->email ?? ''),
            (int) $meeting->rating,
            $this->spreadsheetText($meeting->kritik_saran),
        ];
    }

    public function headings(): array
    {
        return ['Tanggal', 'Pengguna', 'Email', 'Rating', 'Kritik dan saran'];
    }

    private function spreadsheetText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
