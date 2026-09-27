<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/*
 * Device-prefixed, daily-resetting human-readable numbers.
 *
 *   sales          WEB-YYYYMMDD-NNNN
 *   returns        WEB-R-YYYYMMDD-NNNN
 *   purchase order WEB-PO-YYYYMMDD-NNNN
 *   goods receipt  WEB-GRN-YYYYMMDD-NNNN
 *
 * The device prefix means two offline clients can never mint the same number,
 * so WEB- and PC1- streams never collide. The UUID is the sync identity; this
 * number is only for humans.
 *
 * Ported from shared/utils/invoice_number.py.
 */
class DocumentNumber
{
    public static function invoice(?string $businessDate = null, ?string $deviceId = null): string
    {
        return self::next('sales', 'invoice_number', self::prefix('', $businessDate, $deviceId));
    }

    public static function return(?string $businessDate = null, ?string $deviceId = null): string
    {
        return self::next('returns', 'return_number', self::prefix('R-', $businessDate, $deviceId));
    }

    public static function purchaseOrder(?string $businessDate = null, ?string $deviceId = null): string
    {
        return self::next('purchase_orders', 'po_number', self::prefix('PO-', $businessDate, $deviceId));
    }

    public static function goodsReceipt(?string $businessDate = null, ?string $deviceId = null): string
    {
        return self::next('purchases', 'purchase_number', self::prefix('GRN-', $businessDate, $deviceId));
    }

    private static function prefix(string $kind, ?string $businessDate, ?string $deviceId): string
    {
        $device = $deviceId ?? config('pharmacy.device_id');
        $day = str_replace('-', '', $businessDate ?? BusinessDate::today());

        return "{$device}-{$kind}{$day}-";
    }

    /**
     * `prefix + NNNN`, where NNNN counts rows already carrying this prefix in
     * this database. Soft-deleted rows still count: a voided invoice must not
     * hand its number to the next sale.
     */
    private static function next(string $table, string $column, string $prefix): string
    {
        $used = DB::table($table)->where($column, 'like', $prefix.'%')->count();

        return $prefix.str_pad((string) ($used + 1), 4, '0', STR_PAD_LEFT);
    }
}
