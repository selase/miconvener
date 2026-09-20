@extends('errors::custom-layout')

@section('title', __('Too many requests'))

@section('code', '429')

@section('message', __('You\'ve made a lot of requests in a short time. Wait a minute, then try again.'))
