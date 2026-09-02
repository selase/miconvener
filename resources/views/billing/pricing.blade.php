@extends('layouts.admin.master')

@section('title', 'Manage Subscription')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <livewire:tenant.subscription-manager />
    </div>
</div>
@endsection
