<div class="card pt-4 mb-6 mb-xl-9">
    <div class="card-header border-0">
        <div class="card-title">
            <h2>Activity Logs</h2>
        </div>
        <div class="card-toolbar">
            <button type="button" class="btn btn-sm btn-flex btn-light-primary" id="kt_modal_sign_out_sesions">
                <span class="svg-icon svg-icon-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <rect opacity="0.3" x="4" y="11" width="12" height="2" rx="1" fill="currentColor" />
                        <path d="M5.86875 11.6927L7.62435 10.2297C8.09457 9.83785 8.12683 9.12683 7.69401 8.69401C7.3043 8.3043 6.67836 8.28591 6.26643 8.65206L3.34084 11.2526C2.89332 11.6504 2.89332 12.3496 3.34084 12.7474L6.26643 15.3479C6.67836 15.7141 7.3043 15.6957 7.69401 15.306C8.12683 14.8732 8.09458 14.1621 7.62435 13.7703L5.86875 12.3073C5.67684 12.1474 5.67684 11.8526 5.86875 11.6927Z" fill="currentColor" />
                        <path d="M8 5V6C8 6.55228 8.44772 7 9 7C9.55228 7 10 6.55228 10 6C10 5.44772 10.4477 5 11 5H18C18.5523 5 19 5.44772 19 6V18C19 18.5523 18.5523 19 18 19H11C10.4477 19 10 18.5523 10 18C10 17.4477 9.55228 17 9 17C8.44772 17 8 17.4477 8 18V19C8 20.1046 8.89543 21 10 21H19C20.1046 21 21 20.1046 21 19V5C21 3.89543 20.1046 3 19 3H10C8.89543 3 8 3.89543 8 5Z" fill="#C4C4C4" />
                    </svg>
                </span>
            </button>
        </div>
    </div>
    <div class="card-body pt-0 pb-5">
        <div class="table-responsive">
            <table class="table align-middle table-row-dashed gy-5" id="activity-table">
                <thead class="border-bottom border-gray-200 fs-7 fw-bolder">
                    <tr class="text-start text-muted text-uppercase gs-0">
                        <th class="min-w-100px">{{ __('locale.labels.log_id') }}</th>
                        <th>{{ __('locale.labels.location') }}</th>
                        <th>{{ __('locale.labels.event') }}</th>
                        <th class="min-w-125px">{{ __('locale.labels.subject_type') }}</th>
                        <th class="min-w-125px">{{ __('locale.labels.created_at') }}</th>
                        <th class="min-w-70px">{{ __('locale.labels.properties') }}</th>
                    </tr>
                </thead>
                <tbody class="fs-6 fw-bold text-gray-600">
                    @foreach ($activityLogs as $activityLog)
                        <tr>
                            <td>{{ $activityLog->id }}</td>
                            <td>{{ $activityLog->log_name }}</td>
                            <td>{{ $activityLog->description }}</td>
                            <td>{{  $activityLog->subject_type }}</td>
                            <td>{{ \App\Libraries\Helper::getHumanDate($activityLog->created_at) }}</td>
                            <td>
                                <textarea rows="8" disabled>{{ $activityLog->properties }}</textarea>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
