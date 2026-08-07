<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldCity extends Model
{
    protected $fillable = [
        'geoname_id',
        'name',
        'ascii_name',
        'country_code',
        'lat',
        'lng',
        'population',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'population' => 'integer',
    ];
}
