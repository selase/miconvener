@extends('layouts.admin.master')

@section('title', __('locale.menu.profile'))

{{--
    Rebuilt 2026-10-05 from the admin theme's sample page: its tasks, ticket
    counts, notification switches and edit dialogs were never wired to
    anything (one even showed a sample email address). What remains is real.
--}}
@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">
            @if (session('warning'))
                <div class="alert alert-warning mb-5">
                    {{ session('warning') }}
                </div>
            @endif
            <div class="d-flex flex-column flex-lg-row">
                <div class="flex-column flex-lg-row-auto w-lg-250px w-xl-350px mb-10">
                    <div class="card mb-5 mb-xl-8">
                        <div class="card-body">
                            <div class="d-flex flex-center flex-column py-5">
                                <div class="symbol symbol-100px symbol-circle mb-7">
                                    @if (!empty($user->photo))
                                        <img src="{{ Storage::disk(\App\Models\User::uploadDisk())->url($user->photo) }}" alt="{{ $user->displayName() }}" />
                                    @else
                                        <img src="{{ $user->gravatar }}" alt="{{ $user->displayName() }}" />
                                    @endif
                                </div>
                                <div class="fs-3 text-gray-800 fw-bolder mb-3">{{ $user->displayName() }}</div>
                                <div class="mb-5">
                                    <div class="badge badge-lg badge-light-primary d-inline">{{ $user->roles()->first()?->name ?? 'No role' }}</div>
                                </div>
                            </div>
                            <div class="fw-bolder fs-4 py-3">Details</div>
                            <div class="separator"></div>
                            <div class="pb-5 fs-6">
                                <div class="fw-bolder mt-5">Account ID</div>
                                <div class="text-gray-600">{{ $user->uuid }}</div>
                                <div class="fw-bolder mt-5">Email</div>
                                <div class="text-gray-600">{{ $user->email }}</div>
                                <div class="fw-bolder mt-5">Phone</div>
                                <div class="text-gray-600">{{ $user->phone_no ?: '—' }}</div>
                                <div class="fw-bolder mt-5">Status</div>
                                <div class="text-gray-600">{{ ucfirst((string) $user->status) }}</div>
                                <div class="fw-bolder mt-5">Last sign-in</div>
                                <div class="text-gray-600">{{ $user->lastLogin() ?: '—' }}</div>
                                <div class="fw-bolder mt-5">Last sign-in IP</div>
                                <div class="text-gray-600">{{ $user->last_login_ip ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex-lg-row-fluid ms-lg-15">
                    <ul class="nav nav-custom nav-tabs nav-line-tabs nav-line-tabs-2x border-0 fs-4 fw-bold mb-8">
                        <li class="nav-item">
                            <a class="nav-link text-active-primary pb-4 active" data-bs-toggle="tab" href="#kt_user_view_overview_security">Security</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-active-primary pb-4" data-bs-toggle="tab" href="#kt_user_view_overview_events_and_logs_tab">Sign-ins &amp; activity</a>
                        </li>
                    </ul>

                    <div class="tab-content" id="myTabContent">
                        <div class="tab-pane fade show active" id="kt_user_view_overview_security" role="tabpanel">
                            <livewire:admin.user-profile-security :user="$user" />
                        </div>

                        <div class="tab-pane fade" id="kt_user_view_overview_events_and_logs_tab" role="tabpanel">
                            @include('admin.profile._login-sessions')

                            @include('admin.profile._activity-logs')
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-scripts')
    <script src="{{ asset('js/scripts.js') }}"></script>
    <script>
        const tableLayout = "<'row'<'col-sm-6 d-flex align-items-center justify-conten-start'l><'col-sm-6 d-flex align-items-center justify-content-end'f>>" +
            "<'table-responsive'tr>" +
            "<'row'<'col-sm-12 col-md-5 d-flex align-items-center justify-content-center justify-content-md-start'i><'col-sm-12 col-md-7 d-flex align-items-center justify-content-center justify-content-md-end'p>>";

        $("#login-session-table").DataTable({ language: { lengthMenu: "Show _MENU_" }, responsive: true, dom: tableLayout });
        $("#activity-table").DataTable({ language: { lengthMenu: "Show _MENU_" }, responsive: true, dom: tableLayout });
    </script>
@endpush
