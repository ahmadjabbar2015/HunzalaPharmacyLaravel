@extends('layouts.app')

@section('title', 'Staff')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Staff</h1>
    <a href="{{ route('staff.create') }}" class="btn btn-primary">Add an account</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($staff as $member)
                    <tr class="{{ $member->is_active ? '' : 'text-secondary' }}">
                        <td>{{ $member->full_name }}</td>
                        <td><code>{{ $member->username }}</code></td>
                        <td>
                            <span class="badge text-bg-secondary">{{ $member->role }}</span>
                        </td>
                        <td>
                            @if (! $member->is_active)
                                <span class="badge text-bg-light">inactive</span>
                            @elseif ($member->isPinLocked())
                                {{--
                                    A locked PIN is shown here because the owner
                                    is the one who can clear it, and the staff
                                    member standing at the till cannot.
                                --}}
                                <span class="badge text-bg-warning">PIN locked</span>
                            @else
                                <span class="badge text-bg-success">active</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('staff.edit', $member) }}" class="btn btn-sm btn-outline-secondary">
                                Edit
                            </a>

                            @if ($member->is_active)
                                {{--
                                    Deactivate, never delete: every sale and audit
                                    row names this person, and removing the row
                                    would orphan the shop's record of who did what.
                                --}}
                                <form method="POST" action="{{ route('staff.deactivate', $member) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Stop {{ $member->full_name }} signing in? Their history stays intact.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('staff.reactivate', $member) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Reactivate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="small text-secondary mt-3">
    Accounts are never deleted. Deactivating one stops the login and the PIN while
    leaving every sale, adjustment and audit entry still attributed to that person.
</p>

@endsection
