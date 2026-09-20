@extends('errors::custom-layout')

@section('title', __('Page expired'))

@section('code', '419')

@section('message', __('This page sat open too long, so we couldn\'t process your request. Refresh the page and try again.'))
