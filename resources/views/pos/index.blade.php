@extends('layouts.app')

@section('title', 'Sell')

@section('content')
    {{--
        A thin wrapper around the Livewire component rather than routing the
        component directly. Livewire renders into a slot-style layout, and this
        application's layout is an @extends/@yield one - routing the component
        straight at it silently drops the component's own markup, which is a
        failure that looks like a blank panel rather than an error.
    --}}
    @livewire('point-of-sale')
@endsection
