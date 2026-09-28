@extends('layouts.app')

@section('title', 'Add an item')

@section('content')

<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="card">
            <div class="card-header">Add an item to the catalogue</div>
            <div class="card-body">
                <p class="small text-secondary">
                    This adds the item. It puts no stock on the shelf — receive a batch
                    against it afterwards, which is what actually creates the stock.
                </p>

                <form method="POST" action="{{ route('inventory.store') }}">
                    @csrf
                    @include('inventory._form', ['item' => null])

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">Add the item</button>
                        <a href="{{ route('inventory.index') }}" class="btn btn-link">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
