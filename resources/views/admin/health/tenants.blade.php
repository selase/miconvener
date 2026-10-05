@extends('layouts.admin.master')

@section('title', 'Customer health')

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title flex-column">
                        <h3 class="fw-bolder mb-1">Customer health</h3>
                        <div class="text-muted fs-7">
                            {{ $attentionCount }} of {{ $rows->count() }} organisations need attention: a renewal past due,
                            messages that failed this week, or a domain that is not working. They are listed first.
                        </div>
                    </div>
                    <div class="card-toolbar">
                        <input type="text" id="customer-health-filter" class="form-control form-control-solid w-250px" placeholder="Filter organisations" aria-label="Filter organisations" />
                    </div>
                </div>
                <div class="card-body py-4">
                    <table class="table align-middle table-row-dashed fs-6 gy-5" id="kt_table_tenant_health">
                        <thead>
                            <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                <th>Organisation</th>
                                <th>Plan</th>
                                <th>Billing</th>
                                <th>Last payment</th>
                                <th class="text-end">Failed messages (7 days)</th>
                                <th>Domains</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-bold">
                            @forelse ($rows as $row)
                                @php($tenant = $row['tenant'])
                                <tr data-customer-row>
                                    <td>
                                        <a href="{{ route('health.tenants.show', $tenant->uuid) }}" class="text-gray-800 text-hover-primary">{{ $tenant->name }}</a>
                                        @if ($row['needs_attention'])
                                            <span class="badge badge-light-danger ms-2">Needs attention</span>
                                        @endif
                                        <div class="text-muted fs-8">{{ $tenant->slug }}</div>
                                    </td>
                                    <td>{{ $row['plan'] }}</td>
                                    <td>
                                        <span class="badge {{ match ($row['billing']) { 'Past due' => 'badge-light-danger', 'Paid', 'Complimentary' => 'badge-light-success', 'Not paid yet' => 'badge-light-warning', default => 'badge-light' } }}">{{ $row['billing'] }}</span>
                                    </td>
                                    <td>{{ $row['last_paid'] ?? '—' }}</td>
                                    <td class="text-end {{ $row['failed_messages'] > 0 ? 'text-danger' : '' }}">{{ $row['failed_messages'] }}</td>
                                    <td class="fs-7">
                                        @if ($row['custom_domain'])
                                            <div>{{ $row['custom_domain'] }}: {{ $row['custom_domain_status'] ?? 'unknown' }}</div>
                                        @endif
                                        @if ($row['sending_domain'])
                                            <div>Sending domain: {{ $row['sending_domain'] }}</div>
                                        @endif
                                        @if (! $row['custom_domain'] && ! $row['sending_domain'])
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('health.tenants.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Open</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-10">No organisations yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-scripts')
    <script>
        document.getElementById('customer-health-filter')?.addEventListener('input', (event) => {
            const term = event.target.value.toLowerCase();
            document.querySelectorAll('[data-customer-row]').forEach((row) => {
                row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    </script>
@endpush
