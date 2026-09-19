@extends('layouts.admin.master')

@section('title', 'Operations')

@section('content')
<div class="row g-5">
    <div class="col-lg-7">
        @foreach($groups as $groupName => $commands)
            <div class="card mb-5">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="fw-bold m-0">{{ $groupName }}</h3>
                    </div>
                </div>
                <div class="card-body py-4">
                    @foreach($commands as $key => $command)
                        <div class="d-flex flex-stack py-4 {{ ! $loop->last ? 'border-bottom border-gray-300 border-bottom-dashed' : '' }}">
                            <div class="me-5">
                                <div class="fs-6 fw-bold text-gray-800">
                                    {{ $command['label'] }}
                                    @if($command['guarded'])
                                        <span class="badge badge-light-warning ms-2">Confirm</span>
                                    @endif
                                </div>
                                <div class="fs-7 text-muted">{{ $command['description'] }}</div>
                                <code class="fs-8 text-muted">{{ $command['command'] }}</code>
                            </div>
                            <div class="text-end">
                                <form action="{{ route('admin.operations.store') }}" method="POST" class="d-flex flex-column gap-2 align-items-end">
                                    @csrf
                                    <input type="hidden" name="command" value="{{ $key }}">

                                    @foreach($command['options'] as $option => $optionLabel)
                                        <label class="form-check form-check-sm form-check-custom">
                                            <input class="form-check-input" type="checkbox" name="options[]" value="{{ $option }}">
                                            <span class="form-check-label fs-8">{{ $optionLabel }}</span>
                                        </label>
                                    @endforeach

                                    @if($command['guarded'])
                                        <input type="text" name="confirmation" class="form-control form-control-sm form-control-solid w-200px"
                                               placeholder="Type {{ $command['command'] }}" autocomplete="off">
                                    @endif

                                    <button type="submit" class="btn btn-sm {{ $command['guarded'] ? 'btn-light-warning' : 'btn-light-primary' }}">
                                        Run
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title"><h3 class="fw-bold m-0">Recent runs</h3></div>
            </div>
            <div class="card-body py-4">
                @forelse($runs as $run)
                    <a href="{{ route('admin.operations.show', $run->uuid) }}"
                       class="d-flex flex-stack py-3 {{ ! $loop->last ? 'border-bottom border-gray-300 border-bottom-dashed' : '' }} text-hover-primary">
                        <div class="me-3">
                            <div class="fs-7 fw-bold text-gray-800">{{ $run->command }}</div>
                            <div class="fs-8 text-muted">
                                {{ $run->triggered_by_email ?? 'system' }} &middot; {{ $run->created_at->diffForHumans() }}
                            </div>
                        </div>
                        <span class="badge badge-light-{{ match($run->status) {
                            'success' => 'success',
                            'failed' => 'danger',
                            'running' => 'primary',
                            default => 'secondary',
                        } }}">{{ ucfirst($run->status) }}</span>
                    </a>
                @empty
                    <div class="text-muted fs-7 py-4">
                        Nothing has been run from here yet. Commands you run will be listed with their output.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
