<?php

namespace App\Exports;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ConsultationHistoryExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly string $search = '')
    {
    }

    public function query(): Builder
    {
        return Meeting::query()
            ->with('user:id,name,email')
            ->whereIn('status', [1, 9])
            ->when($this->search !== '', function (Builder $query) {
                $query->where(function (Builder $builder) {
                    $builder->where('name', 'like', "%{$this->search}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$this->search}%")
                            ->orWhere('email', 'like', "%{$this->search}%"));
                });
            })
            ->orderByDesc('tanggal')
            ->orderByDesc('start_time');
    }

    public function map($meeting): array
    {
        $documentation = $meeting->link_dokumentasi
            ?: ($meeting->documentation_path ? url("konsultasi/{$meeting->id}/documentation") : '');

        return [
            (int) $meeting->id,
            $this->spreadsheetText($meeting->user?->name ?? 'Pengguna dihapus'),
            $this->spreadsheetText($meeting->user?->email ?? ''),
            $this->spreadsheetText($meeting->name),
            $this->spreadsheetText($meeting->description),
            Carbon::parse($meeting->tanggal)->format('Y-m-d'),
            substr($meeting->start_time, 0, 5),
            substr($meeting->end_time, 0, 5),
            $this->spreadsheetText($meeting->assigned_staff),
            (int) $meeting->status === 1 ? 'Selesai' : 'Dibatalkan',
            $this->spreadsheetText($meeting->ringkasan),
            $this->spreadsheetText($meeting->cancellation_reason),
            $meeting->rating === null ? '' : (int) $meeting->rating,
            $this->spreadsheetText($meeting->kritik_saran),
            $this->spreadsheetText($documentation),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Pengguna',
            'Email',
            'Topik konsultasi',
            'Deskripsi',
            'Tanggal',
            'Waktu mulai',
            'Waktu selesai',
            'Petugas',
            'Status akhir',
            'Ringkasan hasil',
            'Alasan pembatalan',
            'Rating',
            'Kritik dan saran',
            'Dokumentasi',
        ];
    }

    private function spreadsheetText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
