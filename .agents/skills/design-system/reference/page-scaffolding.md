# Page Scaffolding Reference

## Creating a New Tenant Page

### 1. Route (routes/subdomain.php)

```php
Route::get('/settings/meetings', fn (string $subdomain) => view('tenant.settings.meetings'))
    ->middleware('feature:meetings')
    ->name('tenant.settings.meetings');
```

**CRITICAL**: Subdomain routes use `{subdomain}` domain parameter. Controllers must accept `string $subdomain` as first parameter.

### 2. View (resources/views/tenant/)

```blade
@extends('layouts.admin.master')
@section('content')
<div class="container-fluid">
    <livewire:settings.meeting-settings />
</div>
@endsection
```

### 3. Sidebar Entry (resources/views/layouts/admin/partials/sidebar.blade.php)

```blade
<div class="menu-item">
    <a class="menu-link {{ request()->routeIs('tenant.settings.meetings') ? 'active' : '' }}"
       href="{{ route('tenant.settings.meetings') }}">
        <span class="menu-icon">
            <i class="fas fa-cog fs-4"></i>
        </span>
        <span class="menu-title">Meeting Settings</span>
    </a>
</div>
```

**Menu with submenu:**

```blade
<div data-kt-menu-trigger="click" class="menu-item menu-accordion">
    <span class="menu-link">
        <span class="menu-icon"><i class="fas fa-video fs-4"></i></span>
        <span class="menu-title">Meetings</span>
        <span class="menu-arrow"></span>
    </span>
    <div class="menu-sub menu-sub-accordion">
        <div class="menu-item">
            <a class="menu-link {{ request()->routeIs('tenant.meetings.index') ? 'active' : '' }}"
               href="{{ route('tenant.meetings.index') }}">
                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                <span class="menu-title">All Meetings</span>
            </a>
        </div>
    </div>
</div>
```

## Full Page Example

A typical settings page structure:

```blade
<div>
    {{-- Flash message --}}
    @if(session('message'))
        <div class="alert alert-success d-flex align-items-center p-5 mb-10">
            <i class="fas fa-check-circle text-success me-4 fs-2"></i>
            <span>{{ session('message') }}</span>
        </div>
    @endif

    {{-- Main card --}}
    <div class="card card-flush mb-10">
        <div class="card-header pt-7">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bolder text-dark">Page Title</span>
                <span class="text-muted mt-1 fw-bold fs-7">Description of this page</span>
            </h3>
        </div>
        <div class="card-body pt-5">
            <form wire:submit="save">
                {{-- Section 1 --}}
                <div class="row mb-5">
                    <div class="col-md-6 mb-5">
                        {{-- Field --}}
                    </div>
                    <div class="col-md-6 mb-5">
                        {{-- Field --}}
                    </div>
                </div>

                <div class="separator my-7"></div>

                {{-- Section 2 --}}
                <h4 class="fw-bolder text-dark mb-5">Section Title</h4>
                <div class="row mb-5">
                    {{-- More fields --}}
                </div>

                <div class="separator my-7"></div>

                {{-- Submit --}}
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

## Dashboard Page Example

```blade
<div>
    {{-- Stats row --}}
    <div class="row g-5 g-xl-8 mb-8">
        @foreach($stats as $label => $value)
            <div class="col-xl-3">
                <div class="card card-flush shadow-sm h-100">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-gray-400 fw-bold fs-7">{{ $label }}</span>
                            <div class="fs-2hx fw-boldest text-dark">{{ $value }}</div>
                        </div>
                        <i class="fas fa-chart-bar fs-2x text-primary"></i>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Content card with table --}}
    <div class="card card-flush shadow-sm">
        <div class="card-header border-0 pt-6">
            <h3 class="card-title fw-boldest text-dark">Items</h3>
            <div class="card-toolbar">
                <input type="text" wire:model.live.debounce.300ms="search"
                       class="form-control form-control-solid w-250px"
                       placeholder="Search...">
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                    {{-- Table content --}}
                </table>
            </div>
        </div>
        <div class="card-footer flex-wrap pt-0">
            {{ $items->links() }}
        </div>
    </div>
</div>
```

## Empty States

```blade
@forelse($items as $item)
    {{-- Item --}}
@empty
    <div class="text-center py-10">
        <i class="fas fa-inbox fs-2x text-gray-400 mb-3"></i>
        <p class="text-muted fw-bold fs-6">No items found</p>
    </div>
@endforelse
```
