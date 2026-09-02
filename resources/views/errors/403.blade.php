@extends('errors::custom-layout')

@section('title', __('Forbidden'))

@section('code', '403')

@section('message', __($exception->getMessage() ?: 'Forbidden'))


{{--  @section('image')
    <div class="d-flex flex-row-auto bgi-no-repeat bgi-position-x-center bgi-size-contain bgi-position-y-bottom min-h-100px min-h-lg-350px" style="background-image: url({{ asset('assets/media/illustrations/sketchy-1/17.png') }}"></div>
@endsection  --}}
