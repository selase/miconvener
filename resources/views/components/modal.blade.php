@props([
    'type' => 'md'
])

<div class="modal fade" tabindex="-1" id="{{ $id }}">
    <div class="modal-dialog modal-dialog-centered modal-{{ $type }}">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">{{ $title }}</h3>

                <!--begin::Close-->
                <div class="btn btn-icon btn-sm btn-active-light-primary ms-2" data-bs-dismiss="modal" aria-label="Close">
                    <span class="svg-icon svg-icon-1">
                        <x-svg-icon-close />
                    </span>
                </div>
                <!--end::Close-->
            </div>

            <div class="modal-body">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>