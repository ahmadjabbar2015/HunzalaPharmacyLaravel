@extends('layouts.app')

@section('title', 'Edit '.$supplier->supplier_name)

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header">Edit {{ $supplier->supplier_name }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route('suppliers.update', $supplier) }}">
                    @csrf
                    @method('PUT')
                    @include('suppliers._form', ['supplier' => $supplier])

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="{{ route('suppliers.show', $supplier) }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
