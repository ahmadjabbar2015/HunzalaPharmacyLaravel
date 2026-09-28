@extends('layouts.app')

@section('title', 'Returns')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Returns</h1>
    <a href="{{ route('returns.find') }}" class="btn btn-primary">Process a return</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Return</th>
                    <th>Against</th>
                    <th>Customer</th>
                    <th>By</th>
                    <th>How</th>
                    <th class="text-end">Refunded</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($returns as $return)
                    <tr>
                        <td class="small">
                            {{ \App\Support\BusinessDate::toLocal($return->return_time)->format('j M y, g:ia') }}
                        </td>
                        <td>
                            <a href="{{ route('returns.show', $return) }}">{{ $return->return_number }}</a>
                        </td>
                        <td class="small">
                            @if ($return->originalSale)
                                <a href="{{ route('sales.show', $return->originalSale) }}">
                                    {{ $return->originalSale->invoice_number }}
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="small">
                            {{ $return->customer?->primary_contact_name ?? $return->customer?->phone_number ?? 'walk-in' }}
                        </td>
                        <td class="small">{{ $return->staffMember?->full_name ?? '—' }}</td>
                        <td class="small">{{ $return->refund_method }}</td>
                        <td class="money">{{ $return->total_amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-secondary py-4 text-center">No returns yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $returns->links() }}</div>

@endsection
