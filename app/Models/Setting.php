<?php

namespace App\Models;

/**
 * Shop configuration. A single row - use Setting::current().
 */
class Setting extends BaseModel
{
    protected $table = 'settings';

    protected $attributes = [
        'discount_pin_threshold' => 100,
        'low_stock_threshold_default' => 10,
        'expiry_alert_days' => 30,
        'sync_interval_seconds' => 30,
        'receipt_width' => '80mm',
    ];

    protected function modelCasts(): array
    {
        return [
            'discount_pin_threshold' => 'decimal:2',
            'low_stock_threshold_default' => 'integer',
            'expiry_alert_days' => 'integer',
            'sync_interval_seconds' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
