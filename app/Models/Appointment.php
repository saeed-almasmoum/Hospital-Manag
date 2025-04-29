<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'mobile',
        'time_appointment',
        'note',
        'doctor_id',
        'patient_id',
    ];

    // الموعد ينتمي إلى طبيب
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    // الموعد ينتمي إلى مريض
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
