@php
    $primaryColor = App\Libraries\Helper::getTenantBranding('primary_color', config('app.system_setting.theme_primary_color'));
@endphp
<style>
    .btn-primary {
        background-color:
            {{ $primaryColor }}
            !important;
    }

    .btn-check:active+.btn.btn-primary,
    .btn-check:checked+.btn.btn-primary,
    .btn.btn-primary.active,
    .btn.btn-primary.show,
    .btn.btn-primary:active:not(.btn-active),
    .btn.btn-primary:focus:not(.btn-active),
    .btn.btn-primary:hover:not(.btn-active),
    .show>.btn.btn-primary {
        color: #fff;
        border-color:
            {{ $primaryColor }}
        ;
        background-color: #168273 !important;
    }

    .form-check.form-check-solid .form-check-input:checked {
        background-color:
            {{ $primaryColor }}
            !important;
    }

    .menu-state-title-primary .menu-item .menu-link.active .menu-title {
        color:
            {{ $primaryColor }}
            !important;
    }

    .menu-state-icon-primary .menu-item .menu-link.active .menu-icon,
    .menu-state-icon-primary .menu-item .menu-link.active .menu-icon .svg-icon,
    .menu-state-icon-primary .menu-item .menu-link.active .menu-icon i {
        color:
            {{ $primaryColor }}
            !important;
    }

    .menu-state-title-primary .menu-item .menu-link.active {
        transition: color .2s ease, background-color .2s ease;
        color:
            {{ $primaryColor }}
            !important;
    }

    .menu-state-bullet-primary .menu-item .menu-link.active .menu-bullet .bullet {
        background-color:
            {{ $primaryColor }}
        ;
    }

    .menu-state-title-primary .menu-item.show>.menu-link .menu-title {
        color:
            {{ $primaryColor }}
        ;
    }

    .menu-state-icon-primary .menu-item.show>.menu-link .menu-icon,
    .menu-state-icon-primary .menu-item.show>.menu-link .menu-icon .svg-icon,
    .menu-state-icon-primary .menu-item.show>.menu-link .menu-icon i {
        color:
            {{ $primaryColor }}
        ;
    }

    .svg-icon.svg-icon-primary {
        color:
            {{ $primaryColor }}
        ;
    }

    .btn-check:active+.btn.btn-active-color-primary .svg-icon,
    .btn-check:active+.btn.btn-active-color-primary i,
    .btn-check:checked+.btn.btn-active-color-primary .svg-icon,
    .btn-check:checked+.btn.btn-active-color-primary i,
    .btn.btn-active-color-primary.active .svg-icon,
    .btn.btn-active-color-primary.active i,
    .btn.btn-active-color-primary.show .svg-icon,
    .btn.btn-active-color-primary.show i,
    .btn.btn-active-color-primary:active:not(.btn-active) .svg-icon,
    .btn.btn-active-color-primary:active:not(.btn-active) i,
    .btn.btn-active-color-primary:focus:not(.btn-active) .svg-icon,
    .btn.btn-active-color-primary:focus:not(.btn-active) i,
    .btn.btn-active-color-primary:hover:not(.btn-active) .svg-icon,
    .btn.btn-active-color-primary:hover:not(.btn-active) i,
    .show>.btn.btn-active-color-primary .svg-icon,
    .show>.btn.btn-active-color-primary i {
        color:
            {{ $primaryColor }}
        ;
    }

    .menu-state-title-primary .menu-item.hover:not(.here)>.menu-link:not(.disabled):not(.active):not(.here) .menu-title,
    .menu-state-title-primary .menu-item:not(.here) .menu-link:hover:not(.disabled):not(.active):not(.here) .menu-title {
        color:
            {{ $primaryColor }}
        ;
    }

    .menu-state-icon-primary .menu-item.hover:not(.here)>.menu-link:not(.disabled):not(.active):not(.here) .menu-icon,
    .menu-state-icon-primary .menu-item.hover:not(.here)>.menu-link:not(.disabled):not(.active):not(.here) .menu-icon .svg-icon,
    .menu-state-icon-primary .menu-item.hover:not(.here)>.menu-link:not(.disabled):not(.active):not(.here) .menu-icon i,
    .menu-state-icon-primary .menu-item:not(.here) .menu-link:hover:not(.disabled):not(.active):not(.here) .menu-icon,
    .menu-state-icon-primary .menu-item:not(.here) .menu-link:hover:not(.disabled):not(.active):not(.here) .menu-icon .svg-icon,
    .menu-state-icon-primary .menu-item:not(.here) .menu-link:hover:not(.disabled):not(.active):not(.here) .menu-icon i {
        color:
            {{ $primaryColor }}
        ;
    }

    .btn.btn-light-primary {
        color:
            {{ $primaryColor }}
        ;
        border-color: #f1faff;
        background-color: #f1faff;
    }

    .btn.btn-light-primary .svg-icon,
    .btn.btn-light-primary i {
        color:
            {{ $primaryColor }}
        ;
    }

    .link-primary {
        color:
            {{ $primaryColor }}
        ;
    }

    .link-primary:hover {
        color:
            {{ config('app.system_setting.theme_primary_color_dark') }}
        ;
    }

    .btn-check:active+.btn.btn-light-primary,
    .btn-check:checked+.btn.btn-light-primary,
    .btn.btn-light-primary.active,
    .btn.btn-light-primary.show,
    .btn.btn-light-primary:active:not(.btn-active),
    .btn.btn-light-primary:focus:not(.btn-active),
    .btn.btn-light-primary:hover:not(.btn-active),
    .show>.btn.btn-light-primary {
        color: #fff;
        border-color:
            {{ $primaryColor }}
        ;
        background-color:
            {{ $primaryColor }}
            !important;
    }

    .page-item.active .page-link {
        z-index: 3;
        color: #fff;
        background-color:
            {{ $primaryColor }}
        ;
        border-color: transparent;
    }

    .nav-line-tabs .nav-item .nav-link.active,
    .nav-line-tabs .nav-item .nav-link:hover:not(.disabled),
    .nav-line-tabs .nav-item.show .nav-link {
        background-color: transparent;
        border: 0;
        border-bottom: 1px solid
            {{ $primaryColor }}
        ;
        transition: color .2s ease, background-color .2s ease;
    }

    .text-active-primary.active {
        transition: color 0.2s ease, background-color 0.2s ease;
        color:
            {{ $primaryColor }}
            !important;
    }

    .text-primary {
        color:
            {{ $primaryColor }}
            !important;
    }

    .nav-pills .nav-link.active,
    .nav-pills .show>.nav-link {
        color: #fff;
        background-color:
            {{ $primaryColor }}
        ;
    }

    .nav-link {
        display: block;
        padding: 0.5rem 1rem;
        color:
            {{ $primaryColor }}
        ;
        transition: color .15s ease-in-out, background-color .15s ease-in-out, border-color .15s ease-in-out;
    }

    .nav-link:focus,
    .nav-link:hover {
        color: #1d6057;
        text-decoration: none;
    }

    .menu-state-bullet-primary .menu-item:not(.here) .menu-link:hover:not(.disabled):not(.active):not(.here) .menu-bullet .bullet {
        background-color:
            {{ $primaryColor }}
        ;
    }

    .btn-active-color-primary:hover {
        color:
            {{ $primaryColor }}
            !important;
    }

    .text-hover-primary:hover {
        color:
            {{ $primaryColor }}
            !important;
    }
</style>
