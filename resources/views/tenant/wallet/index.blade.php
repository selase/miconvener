@extends('layouts.admin.master')

@section('title', 'Billing Wallet')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <livewire:billing.wallet-dashboard />
    </div>
</div>
@endsection
