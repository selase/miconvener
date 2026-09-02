<div id="kt_aside" class="aside aside-{{ config('app.system_setting.sidebar_skin') }} aside-hoverable"
    data-kt-drawer="true" data-kt-drawer-name="aside" data-kt-drawer-activate="{default: true, lg: false}"
    data-kt-drawer-overlay="true" data-kt-drawer-width="{default:'200px', '300px': '250px'}"
    data-kt-drawer-direction="start" data-kt-drawer-toggle="#kt_aside_mobile_toggle">
    <!--begin::Brand-->
    <div class="aside-logo flex-column-auto" id="kt_aside_logo">
        @php
            $activeTenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        @endphp
        <!--begin::Logo-->
        <a href="{{ $activeTenant ? route('tenant.dashboard') : route('dashboard') }}">
            @if($activeTenant && $activeTenant->logoUrl)
                <img alt="Logo" src="{{ $activeTenant->logoUrl }}" class="h-25px logo" />
            @else
                <x-application-logo />
            @endif
        </a>
        <!--end::Logo-->
        <!--begin::Aside toggler-->
        <div id="kt_aside_toggle" class="btn btn-icon w-auto px-0 btn-active-color-primary aside-toggle"
            data-kt-toggle="true" data-kt-toggle-state="active" data-kt-toggle-target="body"
            data-kt-toggle-name="aside-minimize">
            <!--begin::Svg Icon | path: icons/duotune/arrows/arr079.svg-->
            <span class="svg-icon svg-icon-1 rotate-180">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path opacity="0.5"
                        d="M14.2657 11.4343L18.45 7.25C18.8642 6.83579 18.8642 6.16421 18.45 5.75C18.0358 5.33579 17.3642 5.33579 16.95 5.75L11.4071 11.2929C11.0166 11.6834 11.0166 12.3166 11.4071 12.7071L16.95 18.25C17.3642 18.6642 18.0358 18.6642 18.45 18.25C18.8642 17.8358 18.8642 17.1642 18.45 16.75L14.2657 12.5657C13.9533 12.2533 13.9533 11.7467 14.2657 11.4343Z"
                        fill="currentColor" />
                    <path
                        d="M8.2657 11.4343L12.45 7.25C12.8642 6.83579 12.8642 6.16421 12.45 5.75C12.0358 5.33579 11.3642 5.33579 10.95 5.75L5.40712 11.2929C5.01659 11.6834 5.01659 12.3166 5.40712 12.7071L10.95 18.25C11.3642 18.6642 12.0358 18.6642 12.45 18.25C12.8642 17.8358 12.8642 17.1642 12.45 16.75L8.2657 12.5657C7.95328 12.2533 7.95328 11.7467 8.2657 11.4343Z"
                        fill="currentColor" />
                </svg>
            </span>
            <!--end::Svg Icon-->
        </div>
        <!--end::Aside toggler-->
    </div>
    <!--end::Brand-->
    <!--begin::Aside menu-->
    <div class="aside-menu flex-column-fluid">
        <!--begin::Aside Menu-->
        <div class="hover-scroll-overlay-y my-5 my-lg-5" id="kt_aside_menu_wrapper" data-kt-scroll="true"
            data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-height="auto"
            data-kt-scroll-dependencies="#kt_aside_logo, #kt_aside_footer" data-kt-scroll-wrappers="#kt_aside_menu"
            data-kt-scroll-offset="0">
            <!--begin::Menu-->
            <div class="menu menu-column menu-title-gray-800 menu-state-title-primary menu-state-icon-primary menu-state-bullet-primary menu-arrow-gray-500"
                id="#kt_aside_menu" data-kt-menu="true" data-kt-menu-expand="false">
                <div class="menu-item">
                    <a class="menu-link {{ request()->is('dashboard') ? 'active' : '' }}"
                        href="{{ $activeTenant ? route('tenant.dashboard') : route('dashboard') }}">
                        <span class="menu-icon">
                            <span class="svg-icon svg-icon-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none">
                                    <path
                                        d="M3 2H10C10.6 2 11 2.4 11 3V10C11 10.6 10.6 11 10 11H3C2.4 11 2 10.6 2 10V3C2 2.4 2.4 2 3 2Z"
                                        fill="currentColor" />
                                    <path opacity="0.3"
                                        d="M14 2H21C21.6 2 22 2.4 22 3V10C22 10.6 21.6 11 21 11H14C13.4 11 13 10.6 13 10V3C13 2.4 13.4 2 14 2Z"
                                        fill="currentColor" />
                                    <path opacity="0.3"
                                        d="M3 13H10C10.6 13 11 13.4 11 14V21C11 21.6 10.6 22 10 22H3C2.4 22 2 21.6 2 21V14C2 13.4 2.4 13 3 13Z"
                                        fill="currentColor" />
                                    <path opacity="0.3"
                                        d="M14 13H21C21.6 13 22 13.4 22 14V21C22 21.6 21.6 22 21 22H14C13.4 22 13 21.6 13 21V14C13 13.4 13.4 13 14 13Z"
                                        fill="currentColor" />
                                </svg>
                            </span>
                        </span>
                        <span class="menu-title">{{ __('locale.menu.dashboard') }}</span>
                    </a>
                </div>

                    @if($activeTenant && ($activeTenant->featureEnabled('compliance_audit_log') || $activeTenant->featureEnabled('compliance_legal_hold') || $activeTenant->featureEnabled('compliance_data_retention') || $activeTenant->featureEnabled('compliance_data_export') || $activeTenant->featureEnabled('compliance_ediscovery') || $activeTenant->featureEnabled('compliance_reports')))
                        <div class="menu-item">
                            <div class="menu-content pt-8 pb-2">
                                <span class="menu-section text-muted text-uppercase fs-8 ls-1">Compliance</span>
                            </div>
                        </div>

                        @if($activeTenant->featureEnabled('compliance_audit_log'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.audit-log') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.audit-log') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <path opacity="0.3" d="M18 21.6C16.3 21.6 15 20.3 15 18.6V2.50001C15 2.20001 14.6 1.99996 14.3 2.19996L13 3.59999L11.7 2.3C11.3 1.9 10.7 1.9 10.3 2.3L9 3.59999L7.70001 2.3C7.30001 1.9 6.69999 1.9 6.29999 2.3L5 3.59999L3.70001 2.3C3.50001 2.1 3 2.20001 3 3.50001V18.6C3 20.3 4.3 21.6 6 21.6H18Z" fill="currentColor"/>
                                                <path d="M12 12.6H11C10.4 12.6 10 12.2 10 11.6C10 11 10.4 10.6 11 10.6H12C12.6 10.6 13 11 13 11.6C13 12.2 12.6 12.6 12 12.6ZM9 11.6C9 11 8.6 10.6 8 10.6H6C5.4 10.6 5 11 5 11.6C5 12.2 5.4 12.6 6 12.6H8C8.6 12.6 9 12.2 9 11.6ZM9 7.59998C9 6.99998 8.6 6.59998 8 6.59998H6C5.4 6.59998 5 6.99998 5 7.59998C5 8.19998 5.4 8.59998 6 8.59998H8C8.6 8.59998 9 8.19998 9 7.59998ZM13 7.59998C13 6.99998 12.6 6.59998 12 6.59998H11C10.4 6.59998 10 6.99998 10 7.59998C10 8.19998 10.4 8.59998 11 8.59998H12C12.6 8.59998 13 8.19998 13 7.59998Z" fill="currentColor"/>
                                                <path d="M15 18.6C15 20.3 16.3 21.6 18 21.6C19.7 21.6 21 20.3 21 18.6V12.5C21 12.2 20.6 12 20.3 12.2L19 13.6L17.7 12.3C17.3 11.9 16.7 11.9 16.3 12.3L15 13.6V18.6Z" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Audit Log</span>
                                </a>
                            </div>
                        @endif

                        @if($activeTenant->featureEnabled('compliance_legal_hold'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.legal-holds') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.legal-holds') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <path opacity="0.3" d="M20.5543 4.37824L12.1798 2.02473C12.0626 1.99176 11.9376 1.99176 11.8203 2.02473L3.44572 4.37824C3.18118 4.45258 3 4.6807 3 4.93945V13.569C3 14.6914 3.48509 15.8404 4.4417 16.984C5.17231 17.8575 6.18314 18.7345 7.446 19.5909C9.56752 21.0295 11.6566 21.912 11.7445 21.9488C11.8258 21.9829 11.9129 22 12.0001 22C12.0872 22 12.1744 21.983 12.2557 21.9488C12.3435 21.912 14.4326 21.0295 16.5541 19.5909C17.8169 18.7345 18.8277 17.8575 19.5584 16.984C20.515 15.8404 21 14.6914 21 13.569V4.93945C21 4.6807 20.8189 4.45258 20.5543 4.37824Z" fill="currentColor"/>
                                                <path d="M14.854 11.321C14.7568 11.2282 14.6388 11.1818 14.4998 11.1818H14.3422V10.2272C14.3422 9.61741 14.1086 9.09378 13.6414 8.65628C13.1742 8.21876 12.6248 8 11.9932 8C11.3616 8 10.8122 8.21876 10.345 8.65626C9.87784 9.09378 9.6442 9.61739 9.6442 10.2272V11.1818H9.48667C9.34764 11.1818 9.22963 11.2282 9.13243 11.321C9.03523 11.4137 8.98663 11.5265 8.98663 11.6591V14.5227C8.98663 14.6553 9.03523 14.7681 9.13243 14.8609C9.22963 14.9536 9.34764 15 9.48667 15H14.4998C14.6388 15 14.7568 14.9536 14.854 14.8609C14.9512 14.7681 14.9998 14.6553 14.9998 14.5227V11.6591C14.9998 11.5265 14.9512 11.4137 14.854 11.321ZM12.8437 11.1818H11.143V10.2272C11.143 9.84543 11.2828 9.52049 11.5624 9.25241C11.842 8.98434 12.1808 8.8503 12.5788 8.8503C12.9768 8.8503 13.3156 8.98434 13.5952 9.25241C13.8748 9.52049 14.0146 9.84543 14.0146 10.2272V11.1818H12.8437Z" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Legal Holds</span>
                                </a>
                            </div>
                        @endif

                        @if($activeTenant->featureEnabled('compliance_data_retention'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.retention') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.retention') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <path opacity="0.3" d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="currentColor"/>
                                                <path d="M12 8V13H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Retention Policies</span>
                                </a>
                            </div>
                        @endif

                        @if($activeTenant->featureEnabled('compliance_data_export'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.data-export') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.data-export') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <path opacity="0.3" d="M19 22H5C4.4 22 4 21.6 4 21V3C4 2.4 4.4 2 5 2H14L20 8V21C20 21.6 19.6 22 19 22Z" fill="currentColor"/>
                                                <path d="M15 8H20L14 2V7C14 7.6 14.4 8 15 8Z" fill="currentColor"/>
                                                <path d="M13 12.4V16.8C13 17.2 12.6 17.4 12.2 17.2L8.00005 14.4V17C8.00005 17.6 7.60005 18 7.00005 18C6.40005 18 6.00005 17.6 6.00005 17V11C6.00005 10.4 6.40005 10 7.00005 10C7.60005 10 8.00005 10.4 8.00005 11V13.6L12.2 10.8C12.6 10.6 13 10.8 13 11.2V12.4Z" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Data Export</span>
                                </a>
                            </div>
                        @endif

                        @if($activeTenant->featureEnabled('compliance_ediscovery'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.ediscovery') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.ediscovery') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor"/>
                                                <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">E-Discovery</span>
                                </a>
                            </div>
                        @endif

                        @if($activeTenant->featureEnabled('compliance_reports'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('tenant.compliance.reports') ? 'active' : '' }}"
                                    href="{{ route('tenant.compliance.reports') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                                <path opacity="0.3" d="M21 22H3C2.4 22 2 21.6 2 21V5C2 4.4 2.4 4 3 4H21C21.6 4 22 4.4 22 5V21C22 21.6 21.6 22 21 22Z" fill="currentColor"/>
                                                <path d="M7 10V18H9V10H7ZM11 6V18H13V6H11ZM15 13V18H17V13H15Z" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Reports</span>
                                </a>
                            </div>
                        @endif
                    @endif

                {{-- Subscription & Billing moved to Settings Hub (profile dropdown → Settings) --}}

                @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('read tenant'))
                    <div data-kt-menu-trigger="click"
                        class="menu-item menu-accordion {{ request()->is('tenants') || request()->is('tenants/*') ? 'show' : '' }}">
                        <span class="menu-link">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M16.0173 9H15.3945C14.2833 9 13.263 9.61425 12.7431 10.5963L12.154 11.7091C12.0645 11.8781 12.1072 12.0868 12.2559 12.2071L12.6402 12.5183C13.2631 13.0225 13.7556 13.6691 14.0764 14.4035L14.2321 14.7601C14.2957 14.9058 14.4396 15 14.5987 15H18.6747C19.7297 15 20.4057 13.8774 19.912 12.945L18.6686 10.5963C18.1487 9.61425 17.1285 9 16.0173 9Z"
                                            fill="currentColor" />
                                        <rect opacity="0.3" x="14" y="4" width="4" height="4" rx="2" fill="currentColor" />
                                        <path
                                            d="M4.65486 14.8559C5.40389 13.1224 7.11161 12 9 12C10.8884 12 12.5961 13.1224 13.3451 14.8559L14.793 18.2067C15.3636 19.5271 14.3955 21 12.9571 21H5.04292C3.60453 21 2.63644 19.5271 3.20698 18.2067L4.65486 14.8559Z"
                                            fill="currentColor" />
                                        <rect opacity="0.3" x="6" y="5" width="6" height="6" rx="3" fill="currentColor" />
                                    </svg>
                                </span>
                                <!--end::Svg Icon-->
                            </span>
                            <span class="menu-title">{{ __('locale.menu.tenants') }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('create tenant'))
                            <div class="menu-sub menu-sub-accordion">
                                <div class="menu-item">
                                    <a class="menu-link {{ request()->routeIs('tenants.index') || request()->routeIs('tenants.edit') || request()->routeIs('tenants.show') ? 'active' : '' }}"
                                        href="{{ route('tenants.index') }}">
                                        <span class="menu-bullet">
                                            <span class="bullet bullet-dot"></span>
                                        </span>
                                        <span class="menu-title">{{ __('locale.menu.tenants') }}</span>
                                    </a>
                                </div>
                            </div>
                            <div class="menu-sub menu-sub-accordion">
                                <div class="menu-item">
                                    <a class="menu-link {{ Route::is('tenants.create') ? 'active' : '' }}"
                                        href="{{ route('tenants.create') }}">
                                        <span class="menu-bullet">
                                            <span class="bullet bullet-dot"></span>
                                        </span>
                                        <span class="menu-title">{{ __('locale.labels.create_tenant') }}</span>
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('access-superadmin-dashboard'))
                    <div class="menu-item">
                        <a class="menu-link {{ request()->routeIs('admin.leads.*') ? 'active' : '' }}"
                            href="{{ route('admin.leads.index') }}">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <path d="M21 7H3C2.4 7 2 6.6 2 6V4C2 3.4 2.4 3 3 3H21C21.6 3 22 3.4 22 4V6C22 6.6 21.6 7 21 7Z" fill="currentColor"></path>
                                        <path opacity="0.3" d="M21 14H3C2.4 14 2 13.6 2 13V11C2 10.4 2.4 10 3 10H21C21.6 10 22 10.4 22 11V13C22 13.6 21.6 14 21 14ZM21 21H3C2.4 21 2 20.6 2 20V18C2 17.4 2.4 17 3 17H21C21.6 17 22 17.4 22 18V20C22 20.6 21.6 21 21 21Z" fill="currentColor"></path>
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">Enterprise Leads</span>
                        </a>
                    </div>
                @endif

                {{-- User Management — only in global admin view (no tenant), otherwise in Settings Hub --}}
                @if(!$activeTenant)
                    @can('read user')
                        <div data-kt-menu-trigger="click"
                            class="menu-item menu-accordion {{ request()->is('user-management/*') ? 'show' : '' }}">
                            <span class="menu-link">
                                <span class="menu-icon">
                                    <span class="svg-icon svg-icon-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                            fill="none">
                                            <path opacity="0.3"
                                                d="M20.5543 4.37824L12.1798 2.02473C12.0626 1.99176 11.9376 1.99176 11.8203 2.02473L3.44572 4.37824C3.18118 4.45258 3 4.6807 3 4.93945V13.569C3 14.6914 3.48509 15.8404 4.4417 16.984C5.17231 17.8575 6.18314 18.7345 7.446 19.5909C9.56752 21.0295 11.6566 21.912 11.7445 21.9488C11.8258 21.9829 11.9129 22 12.0001 22C12.0872 22 12.1744 21.983 12.2557 21.9488C12.3435 21.912 14.4326 21.0295 16.5541 19.5909C17.8169 18.7345 18.8277 17.8575 19.5584 16.984C20.515 15.8404 21 14.6914 21 13.569V4.93945C21 4.6807 20.8189 4.45258 20.5543 4.37824Z"
                                                fill="currentColor" />
                                            <path
                                                d="M12.0006 11.1542C13.1434 11.1542 14.0777 10.22 14.0777 9.0771C14.0777 7.93424 13.1434 7 12.0006 7C10.8577 7 9.92348 7.93424 9.92348 9.0771C9.92348 10.22 10.8577 11.1542 12.0006 11.1542Z"
                                                fill="currentColor" />
                                            <path
                                                d="M15.5652 13.814C15.5108 13.6779 15.4382 13.551 15.3566 13.4331C14.9393 12.8163 14.2954 12.4081 13.5697 12.3083C13.479 12.2993 13.3793 12.3174 13.3067 12.3718C12.9257 12.653 12.4722 12.7981 12.0006 12.7981C11.5289 12.7981 11.0754 12.653 10.6944 12.3718C10.6219 12.3174 10.5221 12.2902 10.4314 12.3083C9.70578 12.4081 9.05272 12.8163 8.64456 13.4331C8.56293 13.551 8.49036 13.687 8.43595 13.814C8.40875 13.8684 8.41781 13.9319 8.44502 13.9864C8.51759 14.1133 8.60828 14.2403 8.68991 14.3492C8.81689 14.5215 8.95295 14.6757 9.10715 14.8208C9.23413 14.9478 9.37925 15.0657 9.52439 15.1836C10.2409 15.7188 11.1026 15.9999 11.9915 15.9999C12.8804 15.9999 13.7421 15.7188 14.4586 15.1836C14.6038 15.0748 14.7489 14.9478 14.8759 14.8208C15.021 14.6757 15.1661 14.5215 15.2931 14.3492C15.3838 14.2312 15.4655 14.1133 15.538 13.9864C15.5833 13.9319 15.5924 13.8684 15.5652 13.814Z"
                                                fill="currentColor" />
                                        </svg>
                                    </span>
                                </span>
                                <span class="menu-title">{{ __('locale.menu.user_management') }}</span>
                                <span class="menu-arrow"></span>
                            </span>
                            @can('read user')
                                <div class="menu-sub menu-sub-accordion">
                                    <div class="menu-item">
                                        <a class="menu-link {{ request()->segment(2) == 'users' ? 'active' : '' }}"
                                            href="{{ route('users.index') }}">
                                            <span class="menu-bullet">
                                                <span class="bullet bullet-dot"></span>
                                            </span>
                                            <span class="menu-title">{{ __('locale.menu.users') }}</span>
                                        </a>
                                    </div>
                                </div>
                            @endcan

                            @can('read role')
                                <div class="menu-sub menu-sub-accordion">
                                    <div class="menu-item">
                                        <a class="menu-link {{ request()->segment(2) == 'roles' || request()->segment(1) == 'roles' ? 'active' : '' }}"
                                            href="{{ route('roles.index') }}">
                                            <span class="menu-bullet">
                                                <span class="bullet bullet-dot"></span>
                                            </span>
                                            <span class="menu-title">{{ __('locale.menu.roles_and_permissions') }}</span>
                                        </a>
                                    </div>
                                </div>
                            @endcan
                        </div>
                    @endcan
                @endif


                @can('read audit-trail')
                    <div data-kt-menu-trigger="click"
                        class="menu-item menu-accordion {{ request()->is('audit-trail/*') ? 'show' : '' }}">
                        <span class="menu-link">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none">
                                        <path opacity="0.3"
                                            d="M18 21.6C16.3 21.6 15 20.3 15 18.6V2.50001C15 2.20001 14.6 1.99996 14.3 2.19996L13 3.59999L11.7 2.3C11.3 1.9 10.7 1.9 10.3 2.3L9 3.59999L7.70001 2.3C7.30001 1.9 6.69999 1.9 6.29999 2.3L5 3.59999L3.70001 2.3C3.50001 2.1 3 2.20001 3 3.50001V18.6C3 20.3 4.3 21.6 6 21.6H18Z"
                                            fill="currentColor" />
                                        <path
                                            d="M12 12.6H11C10.4 12.6 10 12.2 10 11.6C10 11 10.4 10.6 11 10.6H12C12.6 10.6 13 11 13 11.6C13 12.2 12.6 12.6 12 12.6ZM9 11.6C9 11 8.6 10.6 8 10.6H6C5.4 10.6 5 11 5 11.6C5 12.2 5.4 12.6 6 12.6H8C8.6 12.6 9 12.2 9 11.6ZM9 7.59998C9 6.99998 8.6 6.59998 8 6.59998H6C5.4 6.59998 5 6.99998 5 7.59998C5 8.19998 5.4 8.59998 6 8.59998H8C8.6 8.59998 9 8.19998 9 7.59998ZM13 7.59998C13 6.99998 12.6 6.59998 12 6.59998H11C10.4 6.59998 10 6.99998 10 7.59998C10 8.19998 10.4 8.59998 11 8.59998H12C12.6 8.59998 13 8.19998 13 7.59998ZM13 15.6C13 15 12.6 14.6 12 14.6H10C9.4 14.6 9 15 9 15.6C9 16.2 9.4 16.6 10 16.6H12C12.6 16.6 13 16.2 13 15.6Z"
                                            fill="currentColor" />
                                        <path
                                            d="M15 18.6C15 20.3 16.3 21.6 18 21.6C19.7 21.6 21 20.3 21 18.6V12.5C21 12.2 20.6 12 20.3 12.2L19 13.6L17.7 12.3C17.3 11.9 16.7 11.9 16.3 12.3L15 13.6V18.6Z"
                                            fill="currentColor" />
                                    </svg>
                                </span>
                                <!--end::Svg Icon-->
                            </span>
                            <span class="menu-title">{{ __('locale.menu.audit_trail') }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div class="menu-item">
                                <a class="menu-link {{ Route::is('audit-trail.activity-logs.index') ? 'active' : ''  }}"
                                    href="{{ route('audit-trail.activity-logs.index') }}">
                                    <span class="menu-bullet">
                                        <span class="bullet bullet-dot"></span>
                                    </span>
                                    <span class="menu-title">{{ __('locale.menu.activity_logs') }}</span>
                                </a>
                            </div>
                        </div>

                        @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('access-superadmin-dashboard'))
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('llm-usage.index') ? 'active' : '' }}"
                                    href="{{ route('llm-usage.index') }}">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                fill="none">
                                                <path
                                                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"
                                                    fill="currentColor" />
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Global LLM Usage</span>
                                </a>
                            </div>

                            <div class="menu-item menu-accordion {{ request()->is('user-management/features*') || request()->is('user-management/packages*') ? 'show' : '' }}"
                                data-kt-menu-trigger="click">
                                <span class="menu-link">
                                    <span class="menu-icon">
                                        <span class="svg-icon svg-icon-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                fill="none">
                                                <path
                                                    d="M10.5 22H6.5C4 22 2 20 2 17.5C2 15 4 13 6.5 13H10.5C13 13 15 15 15 17.5C15 20 13 22 10.5 22ZM6.5 14.5C4.8 14.5 3.5 15.8 3.5 17.5C3.5 19.2 4.8 20.5 6.5 20.5H10.5C12.2 20.5 13.5 19.2 13.5 17.5C13.5 15.8 12.2 14.5 10.5 14.5H6.5Z"
                                                    fill="currentColor" />
                                                <path opacity="0.3"
                                                    d="M17.5 22H13.5C13 22 12.5 21.6 12.5 21C12.5 20.4 13 20 13.5 20H17.5C18.9 20 20 18.9 20 17.5C20 16.1 18.9 15 17.5 15H13.5C13 15 12.5 14.6 12.5 14C12.5 13.4 13 13 13.5 13H17.5C20 13 22 15 22 17.5C22 20 20 22 17.5 22Z"
                                                    fill="currentColor" />
                                                <path
                                                    d="M11 2C11 1.4 11.4 1 12 1C12.6 1 13 1.4 13 2V12C13 12.6 12.6 13 12 13C11.4 13 11 12.6 11 12V2Z"
                                                    fill="currentColor" />
                                            </svg>
                                        </span>
                                    </span>
                                    <span class="menu-title">Plans & Features</span>
                                    <span class="menu-arrow"></span>
                                </span>
                                <div class="menu-sub menu-sub-accordion">
                                    <div class="menu-item">
                                        <a class="menu-link {{ request()->routeIs('features.*') ? 'active' : '' }}"
                                            href="{{ route('features.index') }}">
                                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                            <span class="menu-title">Feature Registry</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endif



                    </div>
                @endcan

                @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('access-superadmin-dashboard'))
                     <!-- Billing & Finance Section -->
                    <div data-kt-menu-trigger="click" class="menu-item menu-accordion {{ request()->is('admin/billing*') ? 'show' : '' }}">
                        <span class="menu-link">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <path opacity="0.3" d="M12.5 22H10.5C10.2 22 10 21.8 10 21.5V12H6V21.5C6 21.8 5.8 22 5.5 22H3.5C3.2 22 3 21.8 3 21.5V11C3 10.7 3.2 10.5 3.5 10.5H12.5C12.8 10.5 13 10.7 13 11V21.5C13 21.8 12.8 22 12.5 22Z" fill="currentColor"/>
                                        <path d="M20.5 22H18.5C18.2 22 18 21.8 18 21.5V5C18 4.7 18.2 4.5 18.5 4.5H20.5C20.8 4.5 21 4.7 21 5V21.5C21 21.8 20.8 22 20.5 22Z" fill="currentColor"/>
                                        <path opacity="0.3" d="M16.5 22H14.5C14.2 22 14 21.8 14 21.5V8C14 7.7 14.2 7.5 14.5 7.5H16.5C16.8 7.5 17 7.7 17 8V21.5C17 21.8 16.8 22 16.5 22Z" fill="currentColor"/>
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">Billing & Finance</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('admin.billing.analytics.usage') ? 'active' : '' }}" href="{{ route('admin.billing.analytics.usage') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Usage Analytics</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a class="menu-link {{ request()->is('admin/billing/invoices*') ? 'active' : '' }}" href="{{ route('admin.billing.invoices.index') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Invoices</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Subscription Plans</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('admin.billing.rate-cards.index') ? 'active' : '' }}" href="{{ route('admin.billing.rate-cards.index') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Rate Cards & Taxes</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('admin.billing.transactions.index') ? 'active' : '' }}" href="{{ route('admin.billing.transactions.index') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Transactions</span>
                                </a>
                            </div>
                            <div class="menu-item">
                                <a class="menu-link {{ request()->routeIs('admin.billing.subscriptions.index') ? 'active' : '' }}" href="{{ route('admin.billing.subscriptions.index') }}">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">Subscriptions</span>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif

                @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('read application health'))
                    <div class="menu-item">
                        <a class="menu-link" href="{{ route('application.health') }}" target="_blank">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path opacity="0.3"
                                            d="M12.025 4.725C9.725 2.425 6.025 2.425 3.725 4.725C1.425 7.025 1.425 10.725 3.725 13.025L11.325 20.625C11.725 21.025 12.325 21.025 12.725 20.625L20.325 13.025C22.625 10.725 22.625 7.025 20.325 4.725C18.025 2.425 14.325 2.425 12.025 4.725Z"
                                            fill="currentColor" />
                                        <path
                                            d="M14.025 17.125H13.925C13.525 17.025 13.125 16.725 13.025 16.325L11.925 11.125L11.025 14.325C10.925 14.725 10.625 15.025 10.225 15.025C9.825 15.125 9.425 14.925 9.225 14.625L7.725 12.325L6.525 12.925C6.425 13.025 6.225 13.025 6.125 13.025H3.125C2.525 13.025 2.125 12.625 2.125 12.025C2.125 11.425 2.525 11.025 3.125 11.025H5.925L7.725 10.125C8.225 9.925 8.725 10.025 9.025 10.425L9.825 11.625L11.225 6.72498C11.325 6.32498 11.725 6.02502 12.225 6.02502C12.725 6.02502 13.025 6.32495 13.125 6.82495L14.525 13.025L15.225 11.525C15.425 11.225 15.725 10.925 16.125 10.925H21.125C21.725 10.925 22.125 11.325 22.125 11.925C22.125 12.525 21.725 12.925 21.125 12.925H16.725L15.025 16.325C14.725 16.925 14.425 17.125 14.025 17.125Z"
                                            fill="currentColor" />
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">{{ __('locale.menu.application_health') }}</span>
                        </a>
                    </div>
                    
                    @if(auth()->user()->isGlobalSuperAdmin() || auth()->user()->can('access-superadmin-dashboard'))
                    <div class="menu-item">
                        <a class="menu-link {{ request()->routeIs('health.tenants') ? 'active' : '' }}" href="{{ route('health.tenants') }}">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <rect opacity="0.5" x="17.0365" y="15.1223" width="8.15546" height="2" rx="1" transform="rotate(45 17.0365 15.1223)" fill="currentColor"></rect>
                                        <path d="M11 19C6.55556 19 3 15.4444 3 11C3 6.55556 6.55556 3 11 3C15.4444 3 19 6.55556 19 11C19 15.4444 15.4444 19 11 19ZM11 5C7.53333 5 5 7.53333 5 11C5 14.4667 7.53333 17 11 17C14.4667 17 17 14.4667 17 11C17 7.53333 14.4667 5 11 5Z" fill="currentColor"></path>
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">Tenant Health (Drill-down)</span>
                        </a>
                    </div>

                    <div class="menu-item">
                        <a class="menu-link {{ Route::is('audit-trail.login-history.index') ? 'active' : ''  }}"
                            href="{{ route('audit-trail.login-history.index') }}">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <path opacity="0.3" d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="currentColor"></path>
                                        <path d="M12 8V13H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">{{ __('locale.menu.login_history') }}</span>
                        </a>
                    </div>

                    <div class="menu-item">
                        <a class="menu-link" href="{{ url('log-viewer') }}" target="_blank">
                            <span class="menu-icon">
                                <span class="svg-icon svg-icon-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                                        <path opacity="0.3" d="M19 22H5C4.4 22 4 21.6 4 21V3C4 2.4 4.4 2 5 2H14L20 8V21C20 21.6 19.6 22 19 22Z" fill="currentColor"></path>
                                        <path d="M15 8H20L14 2V7C14 7.6 14.4 8 15 8Z" fill="currentColor"></path>
                                    </svg>
                                </span>
                            </span>
                            <span class="menu-title">{{ __('locale.menu.web_log_viewer') }}</span>
                        </a>
                    </div>
                    @endif
                @endif

            </div>
        </div>
    </div>

    <div class="aside-footer flex-column-auto pt-5 pb-7 px-5" id="kt_aside_footer">
        <a href="javascript:void(0)" class="btn btn-custom btn-primary w-100" data-bs-trigger="hover"
            data-bs-dismiss-="click">
            <span class="btn-label">{{ $activeTenant ? $activeTenant->name : config('app.name') }}</span>
            <span class="svg-icon btn-icon svg-icon-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path opacity="0.3"
                        d="M19 22H5C4.4 22 4 21.6 4 21V3C4 2.4 4.4 2 5 2H14L20 8V21C20 21.6 19.6 22 19 22ZM12.5 18C12.5 17.4 12.6 17.5 12 17.5H8.5C7.9 17.5 8 17.4 8 18C8 18.6 7.9 18.5 8.5 18.5L12 18C12.6 18 12.5 18.6 12.5 18ZM16.5 13C16.5 12.4 16.6 12.5 16 12.5H8.5C7.9 12.5 8 12.4 8 13C8 13.6 7.9 13.5 8.5 13.5H15.5C16.1 13.5 16.5 13.6 16.5 13ZM12.5 8C12.5 7.4 12.6 7.5 12 7.5H8C7.4 7.5 7.5 7.4 7.5 8C7.5 8.6 7.4 8.5 8 8.5H12C12.6 8.5 12.5 8.6 12.5 8Z"
                        fill="currentColor" />
                    <rect x="7" y="17" width="6" height="2" rx="1" fill="currentColor" />
                    <rect x="7" y="12" width="10" height="2" rx="1" fill="currentColor" />
                    <rect x="7" y="7" width="6" height="2" rx="1" fill="currentColor" />
                    <path d="M15 8H20L14 2V7C14 7.6 14.4 8 15 8Z" fill="currentColor" />
                </svg>
            </span>
        </a>
    </div>
</div>
