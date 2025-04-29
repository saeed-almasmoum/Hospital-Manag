<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AboutUs extends Model
{
    protected $table = 'about_us';

    protected $fillable = [
        'hospital_name',
        'mobile',
        'instgram',
        'facebook',
        'X',
    ];
}
