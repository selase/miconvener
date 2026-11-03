@extends('layouts.admin.master')
@section('title', 'Attendee profile moderation')
@section('content')
<div class="container-xxl"><div class="card"><div class="card-header"><h1 class="card-title">{{ $tenant->name }} · Attendee profile moderation</h1></div><div class="card-body">
<p>Releasing a hold never publishes a profile. The attendee must give fresh consent.</p>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@forelse($profiles as $profile)
<form class="border p-5 mb-5" method="POST" action="{{ route('admin.attendee-profiles.release', [$tenant, $profile->id]) }}">@csrf
<p>Hidden profile {{ $profile->id }} · {{ $profile->moderated_at->format('d M Y') }}</p>
<label class="form-label" for="reason-{{ $profile->id }}">Reason for releasing the hold</label><input class="form-control mb-3" id="reason-{{ $profile->id }}" name="reason" maxlength="240" required minlength="3">
<button class="btn btn-primary" type="submit">Release moderation hold</button></form>
@empty<p>No profiles are held for review.</p>@endforelse
{{ $profiles->links() }}
</div></div></div>
@endsection
