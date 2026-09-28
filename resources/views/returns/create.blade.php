@extends('layouts.app')

@section('title', 'Return against '.$sale->invoice_number)

@section('content')

<h1 class="h4 mb-1">Return against {{ $sale->invoice_number }}</h1>
<p class="text-secondary small mb-3">
    Sold {{ \App\Support\BusinessDate::toLocal($sale->sale_time)->format('j M Y, g:ia') }}
    @if ($sale->customer)
        to {{ $sale->customer->primary_contact_name ?? $sale->customer->phone_number }}
    @endif
</p>

@error('lines')
    <div class="alert alert-danger">{{ $message }}</div>
@enderror

@unless ($drawer)
    {{--
        A refund with no open drawer is recorded but belongs to no session, so
        tonight's expected cash will not account for the money handed back.
    --}}
    <div class="alert alert-warning">
        No drawer is open. A cash refund will be recorded but will not come off any
        session's expected cash, so tonight's count will read over by the refund.
        <a href="{{ route('drawer.show') }}">Open the drawer first</a>.
    </div>
@endunless

<form method="POST" action="{{ route('returns.store', $sale) }}">
    @csrf

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Sold</th>
                        <th class="text-end">Already back</th>
                        <th class="text-end">Can return</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end" style="width: 8rem;">Returning</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $row)
                        @php($line = $row['line'])
                        <tr class="{{ $row['returnable'] === 0 ? 'text-secondary' : '' }}">
                            <td>
                                {{ $line->item?->item_name ?? '—' }}
                                <span class="small text-secondary d-block">
                                    {{ $line->item?->item_code }}
                                    @if ($line->batch)
                                        &middot; batch {{ $line->batch->batch_number }}
                                    @endif
                                </span>
                            </td>
                            <td class="qty">{{ $line->quantity }}</td>
                            <td class="qty">{{ $row['returned'] ?: '—' }}</td>
                            <td class="qty fw-semibold">{{ $row['returnable'] }}</td>
                            {{--
                                The ORIGINAL rate, not today's price. A price rise
                                between sale and return must not hand the customer
                                more than they paid.
                            --}}
                            <td class="money">{{ $line->rate }}</td>
                            <td>
                                <input type="hidden" name="lines[{{ $i }}][sale_item_uuid]" value="{{ $line->uuid }}">
                                <input type="number" min="0" max="{{ $row['returnable'] }}" inputmode="numeric"
                                       class="form-control qty"
                                       name="lines[{{ $i }}][quantity]"
                                       value="{{ old("lines.{$i}.quantity", 0) }}"
                                       @disabled($row['returnable'] === 0)>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-secondary">
            {{--
                Stated because it is the rule the Python got wrong: the cap is
                against what REMAINS, not the original quantity.
            --}}
            A line can only be returned up to what remains — what was sold, less
            anything already returned against it.
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-4">
            <label for="refund_method" class="form-label">Refund by</label>
            <select class="form-select" id="refund_method" name="refund_method" required>
                @foreach (config('pharmacy.payment_methods') as $method)
                    <option value="{{ $method }}" @selected(old('refund_method', $sale->payment_method) === $method)>
                        {{ ucfirst($method) }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">
                Defaults to how they paid. Only a cash refund comes out of the drawer.
            </div>
        </div>

        <div class="col-12 col-md-4">
            <label for="payment_reference" class="form-label">
                Reference <span class="text-secondary">(optional)</span>
            </label>
            <input type="text" class="form-control" id="payment_reference" name="payment_reference"
                   value="{{ old('payment_reference') }}" maxlength="80"
                   placeholder="mobile transfer id">
        </div>

        <div class="col-12 col-md-4 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-lg w-100">Record the refund</button>
        </div>
    </div>
</form>

<p class="small text-secondary mt-3">
    This does not change the original sale. It writes a new return record, puts the
    stock back into the batch it came from, and flags the sale as having had
    something returned.
</p>

@endsection
