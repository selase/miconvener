@extends('layouts.admin.master')

@section('title', 'Message delivery')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder mb-1">Did they get our message?</h3>
                    <div class="text-muted fs-7">Search one person by email address or phone number, across every organisation: tickets and other event emails, texts, announcements and billing emails. Event emails are recorded from 5 October 2026.</div>
                </div>
            </div>
            <div class="card-body py-4">
                <form method="GET" action="{{ route('admin.messages.index') }}" class="d-flex gap-3 mb-8">
                    <input type="text" name="q" value="{{ $term }}" class="form-control form-control-solid mw-450px" placeholder="ama@example.com or 024 123 4567" aria-label="Email or phone">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>

                @if ($results !== null)
                    @if ($results === [])
                        <div class="text-muted py-5">
                            Nothing found for "{{ $term }}". Check the address or number; phone searches need at least 9 digits.
                        </div>
                    @else
                        <table class="table align-middle table-row-dashed fs-7 gy-4">
                            <thead>
                                <tr class="text-muted fw-bolder text-uppercase">
                                    <th>When</th><th>Organisation</th><th>What</th><th>To</th><th>Subject</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 fw-bold">
                                @foreach ($results as $row)
                                    <tr>
                                        <td class="text-nowrap">{{ $row['at'] ?? '—' }}</td>
                                        <td>{{ $row['organisation'] }}</td>
                                        <td><span class="badge badge-light">{{ $row['channel'] }}</span> {{ $row['kind'] }}</td>
                                        <td>{{ $row['to'] }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($row['subject'], 60) }}</td>
                                        <td class="{{ str_starts_with($row['status'], 'failed') ? 'text-danger' : '' }}">
                                            {{ $row['status'] }}
                                            @if ($row['error'])
                                                <div class="text-danger fs-8">{{ \Illuminate\Support\Str::limit($row['error'], 140) }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
