@extends('layouts.app')

@section('title', 'Edit customer')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Edit customer</span>
                <code class="small">{{ $customer->phone_number }}</code>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('customers.update', $customer) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Phone number</label>
                        <input type="text" class="form-control" value="{{ $customer->phone_number }}" disabled>
                        <div class="form-text">
                            Fixed. It is the identity, and changing it would move this
                            household's whole history onto another number.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="primary_contact_name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="primary_contact_name" name="primary_contact_name"
                               value="{{ old('primary_contact_name', $customer->primary_contact_name) }}" maxlength="120">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control" id="city" name="city"
                                   value="{{ old('city', $customer->city) }}" maxlength="60">
                        </div>
                        <div class="col-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="email" name="email" value="{{ old('email', $customer->email) }}" maxlength="120">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address"
                                  rows="2">{{ old('address', $customer->address) }}</textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active"
                               @checked(old('is_active', $customer->is_active))>
                        <label class="form-check-label" for="is_active">Active</label>
                        <div class="form-text">
                            Unticking hides them from the customer list. Their history is kept —
                            records are never deleted, because every sale points at one.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save changes</button>
                    <a href="{{ route('customers.show', $customer) }}" class="btn btn-link">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
