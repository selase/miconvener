@extends('errors::custom-layout')

@section('title', __('Not Found'))

@section('code', '404')

@section('message', __('The page you\'re looking for can\'t be found.'))

@section('image')
    <div class="d-flex flex-row-auto bgi-no-repeat bgi-position-x-center bgi-size-contain bgi-position-y-bottom min-h-100px min-h-lg-350px" style="background-image: url({{ asset('assets/media/illustrations/sketchy-1/18.png') }}"></div>
@endsection
