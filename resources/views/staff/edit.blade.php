@extends('layouts.app')

@section('title', 'Edit '.$staff->full_name)

@section('content')

<div class="row justify-content-center g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>{{ $staff->full_name }}</span>
                <code class="small">{{ $staff->username }}</code>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('staff.update', $staff) }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="full_name" class="form-label">Full name</label>
                            <input type="text" class="form-control @error('full_name') is-invalid @enderror"
                                   id="full_name" name="full_name"
                                   value="{{ old('full_name', $staff->full_name) }}" required maxlength="120">
                            @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   value="{{ old('phone', $staff->phone) }}" maxlength="30">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select @error('role') is-invalid @enderror"
                                    id="role" name="role" required>
                                @foreach (config('pharmacy.user_roles') as $role)
                                    <option value="{{ $role }}" @selected(old('role', $staff->role) === $role)>
                                        {{ ucfirst($role) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                A role change is recorded in the audit trail — it hands over or
                                removes the ability to authorise discounts and close the drawer.
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="{{ route('staff.index') }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">Reset this PIN</div>
            <div class="card-body">
                @if ($staff->isPinLocked())
                    <div class="alert alert-warning py-2 small">
                        This PIN is locked until
                        {{ \App\Support\BusinessDate::toLocal($staff->pin_locked_until)->format('g:ia') }}
                        after five wrong attempts. Setting a new PIN clears the lock immediately.
                    </div>
                @endif

                <p class="small text-secondary">
                    No current PIN is asked for — this exists for the case where it has been
                    forgotten. The reset is recorded against your name, because it hands you
                    the ability to ring a sale as this person.
                </p>

                <form method="POST" action="{{ route('staff.pin.reset', $staff) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="pin" class="form-label">New PIN</label>
                        <input type="text" inputmode="numeric" autocomplete="off"
                               class="form-control @error('pin') is-invalid @enderror"
                               id="pin" name="pin" required
                               maxlength="{{ config('pharmacy.pin.length') }}">
                        @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            Tell it to them in person, and ask them to change it themselves.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-outline-primary">Reset the PIN</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
