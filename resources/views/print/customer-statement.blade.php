@extends('print.layout')
@section('title', 'كشف حساب '.$s['customer']->name)
@section('content')
    @include('print.partials.statement-body')
@endsection
