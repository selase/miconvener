@props([
    'width' => '700px',
    'direction' => 'end',
])

<!-- {{ $title.' drawer' }} -->
<div
    id="{{ $id }}"

    class="bg-white"
    data-kt-drawer="true"
    data-kt-drawer-activate="true"
    data-kt-drawer-toggle="#{{ $id }}_button"
    data-kt-drawer-close="#{{ $id }}_close"
    data-kt-drawer-overlay="true"
    data-kt-drawer-width="{{ $width }}"
    data-kt-drawer-direction="{{ $direction }}"
>
	<div class="card rounded-0 w-100">
		<div class="card-header pe-5">
			<div class="card-title">
				<div class="d-flex justify-content-center flex-column me-3">
					<a href="javascript:void(0)" class="fs-4 fw-bold text-gray-900 text-hover-primary me-1 lh-1">{{ $title }}</a>
				</div>
			</div>
			<div class="card-toolbar">
				<div class="btn btn-sm btn-icon btn-active-light-primary" id="{{ $id }}_close">
					<span class="svg-icon svg-icon-2">
						<x-svg-icon-close />
					</span>
				</div>
			</div>
		</div>
		<div class="card-body hover-scroll-overlay-y">
			{{ $slot }}
		</div>
	</div>
</div>