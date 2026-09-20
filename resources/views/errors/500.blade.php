@extends('errors::custom-layout')

@section('title', __('Something went wrong'))

@section('code', '500')

@section('message', __('We hit a problem on our side. Try again in a moment. If it keeps happening, contact support.'))
