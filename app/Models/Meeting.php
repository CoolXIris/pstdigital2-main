<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    //

    protected $table = 'meeting';
    protected $guarded = [];
    protected $casts = [
        'room_opened_at' => 'immutable_datetime',
    ];

    public function isRoomOpen(?Carbon $at = null): bool
    {
        if ((int) $this->status !== 2) {
            return false;
        }

        $now = ($at ?? now('Asia/Jakarta'))->copy()->setTimezone('Asia/Jakarta');
        $start = Carbon::parse($this->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $this->start_time);
        $end = Carbon::parse($this->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $this->end_time);

        if ($this->room_opened_at) {
            $openedAt = Carbon::parse($this->room_opened_at)->setTimezone('Asia/Jakarta');
            if ($openedAt->lt($start)) {
                $start = $openedAt;
            }
        }

        return $now->betweenIncluded($start, $end);
    }

    public function canBeOpenedEarly(?Carbon $at = null): bool
    {
        if ((int) $this->status !== 2 || $this->room_opened_at) {
            return false;
        }

        $now = ($at ?? now('Asia/Jakarta'))->copy()->setTimezone('Asia/Jakarta');
        $start = Carbon::parse($this->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $this->start_time);
        $end = Carbon::parse($this->tanggal, 'Asia/Jakarta')->setTimeFromTimeString((string) $this->end_time);

        return $now->lt($start) && $now->lt($end);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
