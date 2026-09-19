@extends('layouts.admin.master')

@section('title', 'Command run')

@section('content')
<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <div>
                <h3 class="fw-bold m-0">{{ $definition['label'] ?? $run->command }}</h3>
                <code class="fs-8 text-muted">
                    {{ $run->command }}{{ filled($run->options) ? ' '.implode(' ', $run->options) : '' }}
                </code>
            </div>
        </div>
        <div class="card-toolbar">
            <a href="{{ route('admin.operations.index') }}" class="btn btn-sm btn-light">Back to operations</a>
        </div>
    </div>
    <div class="card-body py-4">
        <div class="row mb-6">
            <div class="col-sm-3">
                <div class="fs-8 text-muted">Status</div>
                <span class="badge badge-light-{{ match($run->status) {
                    'success' => 'success',
                    'failed' => 'danger',
                    'running' => 'primary',
                    default => 'secondary',
                } }}">{{ ucfirst($run->status) }}</span>
            </div>
            <div class="col-sm-3">
                <div class="fs-8 text-muted">Triggered by</div>
                <div class="fs-7 fw-bold">{{ $run->triggered_by_email ?? 'system' }}</div>
            </div>
            <div class="col-sm-3">
                <div class="fs-8 text-muted">Started</div>
                <div class="fs-7 fw-bold">{{ $run->started_at?->format('M j, Y H:i:s') ?? '-' }}</div>
            </div>
            <div class="col-sm-3">
                <div class="fs-8 text-muted">Duration</div>
                <div class="fs-7 fw-bold">{{ $run->durationForHumans() }}</div>
            </div>
        </div>

        @if(! $run->isFinished())
            {{-- The job runs on the queue, so the result arrives after this page did. --}}
            <div class="alert alert-primary d-flex align-items-center">
                <span>Still running. This page refreshes every few seconds.</span>
            </div>
            <script>
                setTimeout(() => window.location.reload(), 5000);
            </script>
        @endif

        <div class="fs-8 text-muted mb-2">Output</div>
        <pre class="bg-light-dark p-5 rounded fs-8" style="white-space: pre-wrap; max-height: 28rem; overflow-y: auto;">{{ filled($run->output) ? $run->output : 'No output.' }}</pre>
    </div>
</div>
@endsection
