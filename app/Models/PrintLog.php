<?php

namespace App\Models;

/**
 * Append-only record of every receipt and report printed. Reprints are counted
 * and stamped on the document itself, which is the shop's control against a
 * receipt being printed twice and one copy pocketed.
 */
class PrintLog extends BaseModel
{
    protected $table = 'print_log';

    protected $attributes = [
        'pin_verified' => false,
        'print_count_at_time' => 0,
    ];

    protected function modelCasts(): array
    {
        return [
            'pin_verified' => 'boolean',
            'print_count_at_time' => 'integer',
            'printed_at' => 'datetime',
        ];
    }
}
