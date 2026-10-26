<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationStaff extends Model
{
    protected $table = 'consultation_staff';

    protected $fillable = ['name', 'sort_order'];
}
