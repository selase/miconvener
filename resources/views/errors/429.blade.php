@extends('errors::custom-layout')

@section('title', __('Too Many Requests'))

@section('code', '429')

@section('message', __('Too many requests from the same client IP; Dynamic IP Restriction Maximum request rate limit reached.'))

{{--  @section('image')
    <div class="d-flex flex-row-auto bgi-no-repeat bgi-position-x-center bgi-size-contain bgi-position-y-bottom min-h-100px min-h-lg-350px" style="background-image: url({{ asset('assets/media/illustrations/sketchy-1/17.png') }}"></div>
@endsection  --}}
