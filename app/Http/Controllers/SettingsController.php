<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/*
 * Shop settings. Owner only, and a single row.
 *
 * One row rather than a key/value table: these are a fixed, small set of knobs
 * that the shop sets once, and a key/value store would trade a readable schema
 * for flexibility nobody has asked for.
 */
class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', ['settings' => $this->current()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shop_name' => ['nullable', 'string', 'max:200'],
            'shop_address' => ['nullable', 'string', 'max:2000'],
            'shop_phone' => ['nullable', 'string', 'max:40'],

            // The threshold above which a discount needs a manager's PIN.
            'discount_pin_threshold' => ['required', 'numeric', 'min:0', 'max:9999999999'],

            'low_stock_threshold_default' => ['required', 'integer', 'min:0', 'max:100000'],
            'expiry_alert_days' => ['required', 'integer', 'min:1', 'max:3650'],

            // 58mm and 80mm are the two thermal roll widths the shop's printers
            // take; anything else would produce a receipt that does not fit.
            'receipt_width' => ['required', 'in:58mm,80mm'],
            'printer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $settings = $this->current();
        $settings->fill($validated)->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }

    /**
     * The settings row, created on first use.
     *
     * Created lazily rather than seeded, so a fresh deployment works whether or
     * not anyone remembered to run a seeder.
     */
    private function current(): Setting
    {
        return Setting::query()->first() ?? Setting::create([
            'shop_name' => config('app.name'),
            'device_id' => config('pharmacy.device_id'),
            'discount_pin_threshold' => config('pharmacy.defaults.discount_pin_threshold'),
            'low_stock_threshold_default' => config('pharmacy.defaults.low_stock_threshold'),
            'expiry_alert_days' => config('pharmacy.defaults.expiry_alert_days'),
            'receipt_width' => config('pharmacy.defaults.receipt_width'),
            'origin_device_id' => config('pharmacy.device_id'),
        ]);
    }
}
