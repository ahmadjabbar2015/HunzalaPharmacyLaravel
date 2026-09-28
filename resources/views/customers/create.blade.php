@extends('layouts.app')

@section('title', 'Add a customer')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">Add a customer</div>
            <div class="card-body">
                <p class="small text-secondary">
                    Only needed for someone whose history the shop should keep. A one-off
                    sale to a stranger needs no record and should not have one.
                </p>

                <form method="POST" action="{{ route('customers.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="phone_number" class="form-label">Phone number</label>
                        <input type="text" inputmode="tel"
                               class="form-control form-control-lg @error('phone_number') is-invalid @enderror"
                               id="phone_number" name="phone_number" value="{{ old('phone_number') }}"
                               required autofocus maxlength="30">
                        @error('phone_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            This is the customer's identity and cannot be changed later. It is
                            how they are found at the counter.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="primary_contact_name" class="form-label">
                            Name <span class="text-secondary">(optional)</span>
                        </label>
                        <input type="text" class="form-control" id="primary_contact_name"
                               name="primary_contact_name" value="{{ old('primary_contact_name') }}" maxlength="120">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control" id="city" name="city"
                                   value="{{ old('city') }}" maxlength="60">
                        </div>
                        <div class="col-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email') }}" maxlength="120">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2">{{ old('address') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Add the customer</button>
                    <a href="{{ route('customers.index') }}" class="btn btn-link">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
