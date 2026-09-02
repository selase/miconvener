<!DOCTYPE html>
<html lang="en">
<!--begin::Head-->

<head>
	<base href="../../../">
	<title>{{ config('app.name') }} | @yield('title')</title>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700" />
	<link href="{{ asset('assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css" />
	<link href="{{ asset('assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />
	@include('layouts.admin.partials.custom-styles')

	<!-- Vite Assets -->
	@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body id="kt_body" class="bg-body">
	<div class="d-flex flex-column flex-root">
		<!--begin::Authentication - Sign-in -->
		<div class="d-flex flex-column flex-lg-row flex-column-fluid">
			<!--begin::Aside-->
			<div class="d-flex flex-column flex-lg-row-auto w-xl-600px positon-xl-relative"
				style="background-color: #168f7f80">
				<!--begin::Wrapper-->
				<div class="d-flex flex-column position-xl-fixed top-0 bottom-0 w-xl-600px scroll-y">
					<!--begin::Content-->
					<div class="d-flex flex-row-fluid flex-column text-center p-10 pt-lg-20">
						<!--begin::Logo-->
						<a href="javascript:void(0)" class="py-9 mb-5">
							<img alt="Logo" src="{{ asset('assets/media/logos/logo-2.svg') }}" class="h-60px" />
						</a>
						<h1 class="fw-bolder fs-2qx pb-5 pb-md-10" style="color: #285a54;">Welcome to
							{{ config('app.name') }}</h1>
						<p class="fw-bold fs-2" style="color: #285a54;">Discover Amazing {{ config('app.name') }}
							<br />with great build tools
						</p>
					</div>
					<div class="d-flex flex-row-auto bgi-no-repeat bgi-position-x-center bgi-size-contain bgi-position-y-bottom min-h-100px min-h-lg-350px"
						style="background-image: url({{ ('assets/media/illustrations/sketchy-1/13.png') }}"></div>
				</div>
			</div>

			<div class="d-flex flex-column flex-lg-row-fluid py-10">
				<div class="d-flex flex-center flex-column flex-column-fluid">
					<div class="w-lg-500px p-10 p-lg-15 mx-auto">
						@yield('content')
					</div>
				</div>

				<!--begin::Footer-->
				<div class="d-flex flex-center flex-wrap fs-6 p-5 pb-0">
					<div class="d-flex flex-center fw-bold fs-6">
						<a href="https://wearepurledot.com" class="text-muted text-hover-primary px-2"
							target="_blank">Copyright &copy; {{ \App\Libraries\Helper::getCurrentYear() }} Purpledot</a>
					</div>
				</div>
				<!--end::Footer-->
			</div>
			<!--end::Body-->
		</div>
	</div>
	<script>var hostUrl = "assets/";</script>
	<script src="{{ asset('assets/plugins/global/plugins.bundle.js') }}"></script>
	<script src="{{ asset('assets/js/scripts.bundle.js') }}"></script>
	@stack('custom-scripts')
</body>

</html>