@extends('layouts.app')

@section('title', 'Add a supplier')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">Add a supplier</div>
            <div class="card-body">
                <form method="POST" action="{{ route('suppliers.store') }}">
                    @csrf
                    @include('suppliers._form', ['supplier' => null])

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Add the supplier</button>
                        <a href="{{ route('suppliers.index') }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
