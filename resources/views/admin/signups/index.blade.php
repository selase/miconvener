@extends('layouts.admin.master')

@section('title', 'Signups')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif

        <div class="row g-5 mb-6">
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Signed up</div>
                <div class="fs-2hx fw-bolder">{{ $totals['all'] }}</div>
                <div class="text-muted fs-8">{{ $totals['this_week'] }} in the last 7 days</div>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Finished onboarding</div>
                <div class="fs-2hx fw-bolder">{{ $totals['onboarded'] }}</div>
                <div class="text-muted fs-8">{{ $totals['all'] > 0 ? round($totals['onboarded'] / $totals['all'] * 100) : 0 }}% of signups</div>
            </div></div></div>
            <div class="col-md-4"><div class="card h-100"><div class="card-body">
                <div class="text-muted fw-bold fs-7 text-uppercase">Not onboarded yet</div>
                <div class="fs-2hx fw-bolder">{{ $totals['all'] - $totals['onboarded'] }}</div>
                <div class="text-muted fs-8">Worth a follow-up</div>
            </div></div></div>
        </div>

        <div class="card mb-6">
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder mb-1">Signups</h3>
                    <div class="text-muted fs-7">Everyone who has created an organisation, and how far they got.</div>
                </div>
                <div class="card-toolbar">
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <select name="stage" class="form-select form-select-sm form-select-solid w-200px" aria-label="Stage" onchange="this.form.submit()">
                            @foreach ($stages as $key => $label)
                                <option value="{{ $key }}" @selected($stage === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm form-control-solid w-200px" placeholder="Name, email or phone" aria-label="Search signups">
                        <button type="submit" class="btn btn-sm btn-light">Search</button>
                    </form>
                </div>
            </div>
            <div class="card-body py-4">
                <table class="table align-middle table-row-dashed fs-7 gy-4">
                    <thead>
                        <tr class="text-muted fw-bolder text-uppercase">
                            <th>Organisation</th><th>Contact</th><th>Plan</th><th>Signed up</th><th>Stage</th><th>Follow-up</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-bold">
                        @forelse ($signups as $signup)
                            <tr>
                                <td><a href="{{ route('admin.signups.show', $signup->uuid) }}">{{ $signup->name }}</a></td>
                                <td>{{ $signup->email ?: '—' }}<div class="text-muted fs-8">{{ $signup->phone_number ?: '' }}</div></td>
                                <td>{{ $signup->package?->name ?? '—' }}</td>
                                <td class="text-nowrap">{{ $signup->created_at?->format('j M Y') }}<div class="text-muted fs-8">{{ $signup->created_at?->diffForHumans() }}</div></td>
                                <td>
                                    @if ($signup->onboarding_completed_at)
                                        <span class="badge badge-light-success">Onboarded</span>
                                        <div class="text-muted fs-8">{{ $signup->onboarding_completed_at->format('j M Y') }}</div>
                                    @else
                                        <span class="badge badge-light-warning">Signed up</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($followUp = $latest->get($signup->id))
                                        <span class="badge badge-light">{{ $followUp->label() }}</span>
                                        <div class="text-muted fs-8">{{ $followUp->author?->first_name ?? 'Someone' }}, {{ $followUp->created_at?->diffForHumans() }}</div>
                                    @else
                                        <span class="text-muted fs-8">Not followed up</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center py-10">No signups match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                {{ $signups->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
