@extends('layouts.app')

@section('title', 'Edit '.$item->item_name)

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Edit {{ $item->item_name }}</span>
                <code class="small">{{ $item->item_code }}</code>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('inventory.update', $item) }}">
                    @csrf
                    @method('PUT')
                    @include('inventory._form', ['item' => $item])

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="{{ route('inventory.show', $item) }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
