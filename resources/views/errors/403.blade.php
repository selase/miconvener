@extends('errors::custom-layout')

@section('title', __('Access denied'))

@section('code', '403')

@section('message', __($exception->getMessage() ?: 'You don\'t have permission to see this page.'))
