<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayPricingWindow extends Model
{
    protected $fillable = [
        'key',
        'label',
        'month',
        'day',
        'is_easter_based',
        'window_before_days',
        'window_after_days',
        'price_multiplier',
        'source',
    ];

    protected $casts = [
        'month' => 'integer',
        'day' => 'integer',
        'is_easter_based' => 'boolean',
        'window_before_days' => 'integer',
        'window_after_days' => 'integer',
        'price_multiplier' => 'float',
    ];
}
