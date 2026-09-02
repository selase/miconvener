<!DOCTYPE html>
<html lang="en">
	<head><base href="../../">
		<title>{{ config('app.name') }} | @yield('title')</title>
		<meta charset="utf-8" />
		<link rel="shortcut icon" href="{{ asset('assets/media/logos/favicon.ico') }}" />
		<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700" />
		<link href="{{ asset('assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet" type="text/css" />
		<link href="{{ asset('assets/css/style.bundle.css') }}" rel="stylesheet" type="text/css" />
	</head>
	<body id="kt_body" class="auth-bg">
		<div class="d-flex flex-column flex-root">
			<div class="d-flex flex-column flex-column-fluid">
				<div class="d-flex flex-column flex-column-fluid text-center p-10 py-lg-15">
					<a href="/" class="mb-10 pt-lg-10">
						<img alt="Logo" src="{{ asset('assets/media/logos/logo-1.svg') }}" class="h-40px mb-5" />
					</a>
					<div class="pt-lg-10 mb-10">
						<h1 class="fw-bolder fs-2qx text-gray-800 mb-10">@yield('code') @yield('title')</h1>
						<div class="fw-bold fs-5 text-muted mb-15">@yield('message')</div>
						<div class="text-center">
							<a href="javascript:history.go(-1)" class="btn btn-lg btn-primary fw-bolder">Go back</a>
						</div>
					</div>
					@yield('image')
				</div>
				<div class="d-flex flex-center flex-column-auto p-10">
					<div class="d-flex align-items-center fw-bold fs-6">
						<a href="{{ config('app.system_setting.provider.url') }}" class="text-muted text-hover-primary px-2" target="_blank">Copyright &copy; {{ \App\Libraries\Helper::getCurrentYear() }}  {{ config('app.system_setting.provider.name') }}</a>
					</div>
				</div>
			</div>
		</div>
		<script>var hostUrl = "assets/";</script>
		<script src="{{ asset('assets/plugins/global/plugins.bundle.js') }}"></script>
		<script src="{{ asset('assets/js/scripts.bundle.js') }}"></script>
	</body>
</html>
