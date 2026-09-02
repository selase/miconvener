@extends('layouts.admin.auth')

@section('title', '2FA Challenge')

@section('content')
    <form class="form w-100" method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf
        <div class="text-center mb-10">
            <h1 class="text-dark mb-3">Two-Factor Authentication</h1>
            <div class="text-gray-400 fw-bold fs-4">
                Please enter the 6-digit code from your authenticator app to complete the login process.
            </div>
            
            @if ($errors->any())
                <div class="mt-7">
                    <ul class="list-none text-sm text-danger">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="fv-row mb-10">
            <label class="form-label fs-6 fw-bolder text-dark">Authentication Code</label>
            <input class="form-control form-control-lg form-control-solid text-center" 
                type="text" 
                name="one_time_password" 
                inputmode="numeric" 
                pattern="[0-9]*" 
                autocomplete="one-time-code" 
                placeholder="000000" 
                autofocus />
        </div>

        <div class="text-center">
            <button type="submit" class="btn btn-lg btn-primary w-100 mb-5">
                Verify Code
            </button>
            <a href="{{ route('logout') }}" 
               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
               class="link-primary fs-6 fw-bolder">Cancel and Sign Out</a>
        </div>
    </form>

    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
        @csrf
    </form>
@endsection
