@extends('layouts.app')

@section('title', 'Add an account')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">Add a staff account</div>
            <div class="card-body">
                <form method="POST" action="{{ route('staff.store') }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="full_name" class="form-label">Full name</label>
                            <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                                   id="full_name" name="full_name" value="{{ old('full_name') }}"
                                   required autofocus maxlength="120">
                            @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control @error('username') is-invalid @enderror"
                                   id="username" name="username" value="{{ old('username') }}"
                                   required maxlength="50" autocapitalize="off" spellcheck="false">
                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Cannot be changed later — it appears throughout the audit trail.
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">
                                Phone <span class="text-secondary">(optional)</span>
                            </label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="{{ old('phone') }}" maxlength="30">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select @error('role') is-invalid @enderror"
                                    id="role" name="role" required>
                                @foreach (config('pharmacy.user_roles') as $role)
                                    <option value="{{ $role }}" @selected(old('role', 'staff') === $role)>
                                        {{ ucfirst($role) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Staff sell and receive stock. Managers also close the drawer,
                                authorise discounts and read reports. Owners also manage accounts.
                            </div>
                        </div>

                        <div class="col-12"><hr class="my-1"></div>

                        <div class="col-12 col-md-6">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" required autocomplete="new-password">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">For signing in. At least 8 characters.</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="password_confirmation" class="form-label">Password again</label>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" required autocomplete="new-password">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="pin" class="form-label">PIN</label>
                            <input type="text" inputmode="numeric" autocomplete="off"
                                   class="form-control @error('pin') is-invalid @enderror"
                                   id="pin" name="pin" value="{{ old('pin') }}" required
                                   maxlength="{{ config('pharmacy.pin.length') }}">
                            @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                {{ config('pharmacy.pin.length') }} digits, entered at the till to
                                attribute a sale. Must differ from every other active member's —
                                two people sharing a PIN would make their sales indistinguishable.
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Create the account</button>
                        <a href="{{ route('staff.index') }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
