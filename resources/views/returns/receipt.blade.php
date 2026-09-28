{{--
    A refund slip, on the same thermal roll as a receipt.

    Standalone and inline-styled for the same reasons as sales/receipt: it must
    print correctly with no asset bundle, and none of the application chrome
    belongs on a 58mm roll.

    Deliberately headed REFUND in capitals. A slip that looked like a sale
    receipt could be presented as proof of purchase for stock that has already
    gone back.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $return->return_number }}</title>

    <style>
        @page {
            size: {{ $settings->receipt_width ?? '80mm' }} auto;
            margin: 2mm;
        }

        body {
            width: {{ $settings->receipt_width ?? '80mm' }};
            margin: 0 auto;
            padding: 2mm;
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            color: #000;
            background: #fff;
        }

        h1 { font-size: 14px; margin: 0 0 1mm; text-align: center; }
        h2 { font-size: 13px; margin: 1mm 0; text-align: center; letter-spacing: 1px; }
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

    <h2>REFUND</h2>

    <div>
        <strong>{{ $return->return_number }}</strong><br>
        {{ \App\Support\BusinessDate::toLocal($return->return_time)->format('j M Y, g:i a') }}<br>
        Handled by {{ $return->staffMember?->full_name ?? '—' }}
        @if ($return->originalSale)
            {{-- The original invoice, so the pair can always be reconciled. --}}
            <br>Against sale {{ $return->originalSale->invoice_number }}
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
            @foreach ($return->lines as $line)
                <tr>
                    <td colspan="4">{{ $line->item?->item_name ?? '—' }}</td>
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
        <tr class="total">
            <td>REFUNDED</td>
            <td class="num">{{ $return->total_amount }}</td>
        </tr>
        <tr>
            <td class="muted">By {{ $return->refund_method }}</td>
            <td class="num muted">{{ $return->payment_reference }}</td>
        </tr>
    </table>

    <hr>

    <div class="center muted">
        The rate refunded is the rate originally paid.<br>
        Keep this slip.
    </div>

    <div class="no-print">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('returns.show', $return) }}">Back to the return</a>
    </div>

</body>
</html>
