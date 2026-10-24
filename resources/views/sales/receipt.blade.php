{{--
    A thermal receipt, not a page.

    Standalone rather than extending the app layout: this prints on a 58mm or
    80mm roll, and every part of the application chrome would either waste paper
    or jam the layout. The width comes from settings because the shop's printer
    decides it, not the browser.

    It opens the print dialog on load, since the only reason to be on this page is
    to print - and a staff member holding a receipt roll should not also have to
    find Ctrl+P.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sale->invoice_number }}</title>

    <style>
        /* Inline, deliberately: a receipt must print correctly even if the asset
           bundle failed to load, and it needs none of Bootstrap.

           This shop prints on plain A4, not a thermal roll. A single receipt
           is pinned to the top-left quadrant (~105mm x 148mm, a quarter of
           A4) so the same sheet can be fed back in three more times to fill
           the other quadrants before it's cut into four receipts. */
        @page {
            size: A4;
            margin: 0;
        }

        body {
            width: 105mm;
            min-height: 148mm;
            margin: 0;
            padding: 3mm;
            position: absolute;
            top: 0;
            left: 0;
            /* A monospace stack so columns align on a printer with no font
               metrics of its own. */
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            color: #000;
            background: #fff;
            box-sizing: border-box;
        }

        h1 { font-size: 14px; margin: 0 0 1mm; text-align: center; }
        .center { text-align: center; }
        .muted { color: #444; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.4mm 0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .total { font-weight: bold; font-size: 12px; }

        .no-print { margin-top: 4mm; text-align: center; }

        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

    <h1>{{ $settings->shop_name ?? config('app.name') }}</h1>

    <div class="center muted">
        @if ($settings?->shop_address)
            {{ $settings->shop_address }}<br>
        @endif
        @if ($settings?->shop_phone)
            {{ $settings->shop_phone }}
        @endif
    </div>

    <hr>

    <div>
        <strong>{{ $sale->invoice_number }}</strong><br>
        {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M Y, g:i a') }}<br>
        Served by {{ $sale->staffMember?->full_name ?? '—' }}
        @if ($sale->customer)
            <br>{{ $sale->customer->primary_contact_name ?? $sale->customer->phone_number }}
        @endif
    </div>

    <hr>

    <table>
        <thead>
            <tr>
                <th style="text-align: left;">Item</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->lines as $line)
                <tr>
                    {{-- The name wraps onto its own row rather than being cut:
                         a customer checking a receipt needs to read what it was.
                         Numbered, so whoever bags the order can count items
                         against the list on a narrow roll where a separate
                         "#" column would not fit. --}}
                    <td colspan="4">{{ $loop->iteration }}. {{ $line->item?->item_name ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="muted">{{ $line->item?->item_code }}</td>
                    <td class="num">{{ $line->quantity }}</td>
                    <td class="num">{{ $line->rate }}</td>
                    <td class="num">{{ $line->amount }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <hr>

    <table>
        <tr>
            {{-- Total pieces, not line count: lets whoever bags the order
                 count items against the receipt without re-adding every
                 line's quantity by hand. --}}
            <td>Total items</td>
            <td class="num">{{ $sale->lines->sum('quantity') }}</td>
        </tr>
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ $sale->subtotal_amount }}</td>
        </tr>
        @if (! \App\Support\Money::isZero($sale->discount_amount))
            <tr>
                <td>Discount</td>
                <td class="num">−{{ $sale->discount_amount }}</td>
            </tr>
        @endif
        <tr class="total">
            <td>TOTAL</td>
            <td class="num">{{ $sale->net_amount }}</td>
        </tr>
        <tr>
            <td class="muted">Paid by {{ $sale->payment_method }}</td>
            <td class="num muted">{{ $sale->payment_reference }}</td>
        </tr>
        @if ($sale->amount_tendered !== null)
            {{-- Printed proof of what changed hands, for the dispute that
                 shows up a week later - "the bill" alone only proves what was
                 owed. --}}
            <tr>
                <td class="muted">Tendered</td>
                <td class="num muted">{{ $sale->amount_tendered }}</td>
            </tr>
            <tr>
                <td class="muted">Change</td>
                <td class="num muted">{{ $sale->change_due }}</td>
            </tr>
        @endif
    </table>

    <hr>

    <div class="center muted">
        @if ($sale->print_count > 1)
            {{-- Marked, so a reprint cannot be passed off as the original. --}}
            <strong>REPRINT ({{ $sale->print_count }})</strong><br>
        @endif
        Medicines are not returnable without this receipt.<br>
        Thank you.
    </div>

    <div class="no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('sales.show', $sale) }}">Back to the sale</a>
    </div>

</body>
</html>
