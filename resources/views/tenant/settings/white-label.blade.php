@extends('layouts.admin.master')

@section('title', __('White-Label Settings'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <livewire:settings.white-label-settings />
        </div>
    </div>
@endsection
