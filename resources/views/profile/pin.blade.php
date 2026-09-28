@extends('layouts.app')

@section('title', 'Change my PIN')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="card">
            <div class="card-header">Change my PIN</div>
            <div class="card-body">
                <p class="small text-secondary">
                    Your PIN is what attributes a sale to you. Nobody else should know it —
                    and if someone does, change it now.
                </p>

                <form method="POST" action="{{ route('profile.pin.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="current_pin" class="form-label">Current PIN</label>
                        {{--
                            type=password with inputmode=numeric: it must not be
                            readable over a shoulder, but the till may be a
                            touchscreen where a text keyboard would be wrong.
                        --}}
                        <input type="password" inputmode="numeric" autocomplete="off"
                               class="form-control @error('current_pin') is-invalid @enderror"
                               id="current_pin" name="current_pin" required autofocus
                               maxlength="{{ config('pharmacy.pin.length') }}">
                        @error('current_pin')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="new_pin" class="form-label">New PIN</label>
                        <input type="password" inputmode="numeric" autocomplete="off"
                               class="form-control @error('new_pin') is-invalid @enderror"
                               id="new_pin" name="new_pin" required
                               maxlength="{{ config('pharmacy.pin.length') }}">
                        @error('new_pin')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            {{ config('pharmacy.pin.length') }} digits, and not one another
                            member of staff is already using.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="new_pin_confirmation" class="form-label">New PIN again</label>
                        <input type="password" inputmode="numeric" autocomplete="off"
                               class="form-control" id="new_pin_confirmation"
                               name="new_pin_confirmation" required
                               maxlength="{{ config('pharmacy.pin.length') }}">
                    </div>

                    <button type="submit" class="btn btn-primary">Change my PIN</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
