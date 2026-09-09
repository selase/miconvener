<div id="kt_header" style="" class="header align-items-stretch">
    <!--begin::Container-->
    <div class="container-fluid d-flex align-items-stretch justify-content-between">
        <!--begin::Aside mobile toggle-->
        <div class="d-flex align-items-center d-lg-none ms-n2 me-2" title="Show aside menu">
            <div class="btn btn-icon btn-active-light-primary w-30px h-30px w-md-40px h-md-40px"
                id="kt_aside_mobile_toggle">
                <!--begin::Svg Icon | path: icons/duotune/abstract/abs015.svg-->
                <span class="svg-icon svg-icon-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M21 7H3C2.4 7 2 6.6 2 6V4C2 3.4 2.4 3 3 3H21C21.6 3 22 3.4 22 4V6C22 6.6 21.6 7 21 7Z"
                            fill="currentColor" />
                        <path opacity="0.3"
                            d="M21 14H3C2.4 14 2 13.6 2 13V11C2 10.4 2.4 10 3 10H21C21.6 10 22 10.4 22 11V13C22 13.6 21.6 14 21 14ZM22 20V18C22 17.4 21.6 17 21 17H3C2.4 17 2 17.4 2 18V20C2 20.6 2.4 21 3 21H21C21.6 21 22 20.6 22 20Z"
                            fill="currentColor" />
                    </svg>
                </span>
                <!--end::Svg Icon-->
            </div>
        </div>
        <!--end::Aside mobile toggle-->
        <!--begin::Mobile logo-->
        <div class="d-flex align-items-center flex-grow-1 flex-lg-grow-0">
            <a href="#" class="d-lg-none">
                @if(app(\App\Services\Tenancy\TenantContext::class)->getTenant() && app(\App\Services\Tenancy\TenantContext::class)->getTenant()->logoUrl)
                    <img alt="Logo" src="{{ app(\App\Services\Tenancy\TenantContext::class)->getTenant()->logoUrl }}"
                        class="h-30px" />
                @else
                    <img alt="{{ config('app.name') }}" src="{{ asset('assets/img/brand/miconvener.png') }}" class="h-30px" />
                @endif
            </a>
        </div>
        <!--end::Mobile logo-->
        <!--begin::Wrapper-->
        <div class="d-flex align-items-stretch justify-content-between flex-lg-grow-1">
            <!--begin::Page Title-->
            <div class="d-flex align-items-center" id="kt_header_nav">
                <h3 class="text-dark fw-bolder my-0 fs-4 d-none d-lg-block">@yield('title')</h3>
            </div>
            <!--end::Page Title-->

            <!--begin::Header Actions-->
            <div class="d-flex align-items-center gap-2 me-3">
                @yield('header-actions')

                {{-- Superadmin Tenant Context Return --}}
                @if (Auth::check() && Auth::user()->isGlobalSuperAdmin() && app(\App\Services\Tenancy\TenantContext::class)->getTenant())
                    <a href="{{ route('tenants.reset') }}" class="btn btn-sm btn-light-primary">
                        <i class="fas fa-arrow-left me-1"></i>Global View
                    </a>
                @endif

                {{-- User impersonation --}}
                @if (Session::has('original_user'))
                    <a href="{{ route('impersonation.stop') }}" class="btn btn-sm btn-light-danger">
                        <i class="fas fa-user-shield me-1"></i>Stop Impersonation
                    </a>
                @endif
            </div>
            <!--end::Header Actions-->

            <!--begin::Toolbar wrapper-->
            <div class="d-flex align-items-stretch flex-shrink-0">



                <!--begin::User menu-->
                <div class="d-flex align-items-center ms-1 ms-lg-3" id="kt_header_user_menu_toggle">
                    <!--begin::Menu wrapper-->
                    <div class="cursor-pointer symbol symbol-30px symbol-md-40px" data-kt-menu-trigger="click"
                        data-kt-menu-attach="parent" data-kt-menu-placement="bottom-end">
                        @if (!empty(Auth::user()->photo))
                            <img src="{{ Storage::disk('local')->url(Auth::user()->photo) }}" alt="user" />
                        @else
                            <img src="{{ Auth::user()->gravatar }}" alt="user" />
                        @endif

                    </div>
                    <!--begin::User account menu-->
                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-primary fw-bold py-4 fs-6 w-275px"
                        data-kt-menu="true">
                        <!--begin::Menu item-->
                        <div class="menu-item px-3">
                            <div class="menu-content d-flex align-items-center px-3">
                                <div class="symbol symbol-50px me-5">
                                    @if (!empty(Auth::user()->photo))
                                        <img src="{{ Storage::disk('local')->url(Auth::user()->photo) }}" alt="user" />
                                    @else
                                        <img src="{{ Auth::user()->gravatar }}" alt="user" />
                                    @endif
                                </div>
                                <div class="d-flex flex-column">
                                    <div class="fw-bolder d-flex align-items-center fs-5">
                                        {{ Auth::user()->displayName() }}
                                        <span
                                            class="badge badge-light-success fw-bolder fs-8 px-2 py-1 ms-2">{{ Auth::user()->roles()->first()?->name }}</span>
                                    </div>
                                    <a href="#"
                                        class="fw-bold text-muted text-hover-primary fs-7">{{ Auth::user()->email }}</a>
                                </div>
                            </div>
                        </div>
                        <div class="separator my-2"></div>
                        <div class="menu-item px-5">
                            <a href="{{ route('profile.index', Auth::user()->uuid) }}"
                                class="menu-link px-5">Profile</a>
                        </div>

                        @if(app(\App\Services\Tenancy\TenantContext::class)->getTenant())
                            <div class="menu-item px-5">
                                <a href="{{ route('tenant.settings.hub') }}"
                                    class="menu-link px-5">
                                    <span class="menu-icon me-0"><i class="fas fa-cog fs-7 text-muted"></i></span>
                                    Settings
                                </a>
                            </div>
                        @endif

                        @if (auth()->user()->tenants()->count() > 1)
                            <div class="separator my-2"></div>
                            <div class="text-center text-gray-600 fs-7">{{ __('locale.labels.switch_organization') }}</div>
                            @foreach (auth()->user()->tenants as $tenant)
                                <div class="menu-item px-5">
                                    <a href="{{ route('tenants.change', $tenant->id) }}"
                                        class="menu-link px-5 @if(auth()->user()->tenant_id == $tenant->id) text-danger @endif">{{ $tenant->name }}</a>
                                </div>
                            @endforeach
                        @endif

                        <div class="separator my-2"></div>
                        <div class="menu-item px-5">
                            <form action="{{ route('logout') }}" method="post">
                                @csrf
                                <a href="{{ route('logout') }}" onclick="event.preventDefault();
                                this.closest('form').submit();" class="menu-link px-5">Sign Out</a>
                            </form>
                        </div>
                        <div class="separator my-2"></div>
                        <div class="menu-item px-5">
                            <div class="menu-content px-5">
                                <label
                                    class="form-check form-switch form-check-custom form-check-solid pulse pulse-success"
                                    for="kt_user_menu_dark_mode_toggle">
                                    <input class="form-check-input w-30px h-20px" type="checkbox" value="1" name="mode"
                                        id="kt_user_menu_dark_mode_toggle" data-kt-url="#" />
                                    <span class="pulse-ring ms-n1"></span>
                                    <span class="form-check-label text-gray-600 fs-7">Dark Mode</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center d-lg-none ms-2 me-n3" title="Show header menu">
                    <div class="btn btn-icon btn-active-light-primary w-30px h-30px w-md-40px h-md-40px"
                        id="kt_header_menu_mobile_toggle">
                        <!--begin::Svg Icon | path: icons/duotune/text/txt001.svg-->
                        <span class="svg-icon svg-icon-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none">
                                <path
                                    d="M13 11H3C2.4 11 2 10.6 2 10V9C2 8.4 2.4 8 3 8H13C13.6 8 14 8.4 14 9V10C14 10.6 13.6 11 13 11ZM22 5V4C22 3.4 21.6 3 21 3H3C2.4 3 2 3.4 2 4V5C2 5.6 2.4 6 3 6H21C21.6 6 22 5.6 22 5Z"
                                    fill="currentColor" />
                                <path opacity="0.3"
                                    d="M21 16H3C2.4 16 2 15.6 2 15V14C2 13.4 2.4 13 3 13H21C21.6 13 22 13.4 22 14V15C22 15.6 21.6 16 21 16ZM14 20V19C14 18.4 13.6 18 13 18H3C2.4 18 2 18.4 2 19V20C2 20.6 2.4 21 3 21H13C13.6 21 14 20.6 14 20Z"
                                    fill="currentColor" />
                            </svg>
                        </span>
                        <!--end::Svg Icon-->
                    </div>
                </div>
                <!--end::Header menu toggle-->
            </div>
            <!--end::Toolbar wrapper-->
        </div>
        <!--end::Wrapper-->
    </div>
    <!--end::Container-->
</div>