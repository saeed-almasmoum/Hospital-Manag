<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Foundation\Auth\User as Authenticatable;


class Patient extends Authenticatable implements JWTSubject
{
    protected $fillable = [
        'name',
        'email',
        'password',

    ];


    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function specialty()
    {
        return $this->belongsTo(specialty::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    
}
