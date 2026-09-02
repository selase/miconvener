@extends('layouts.admin.master')

@section('title', __('LLM Configurations'))

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">

            <div class="card mb-5 mb-xl-10">
                <div class="card-header border-0 cursor-pointer">
                    <div class="card-title m-0">
                        <h3 class="fw-bolder m-0">{{ __('Provider Settings') }}</h3>
                    </div>
                </div>

                <div class="card-body border-top p-9">
                    <form action="{{ route('tenant.llm-config.update') }}" method="POST">
                        @csrf
                        @method('PUT')

                        <!-- OpenAI -->
                        @include('admin.tenant.llm-config.partials.provider-form', [
                            'provider' => 'openai',
                            'label' => 'OpenAI',
                            'config' => $configs['openai'] ?? null
                        ])

    <div class="separator separator-dashed my-6"></div>
                            <!-- Anthropic -->
                        @include('admin.tenant.llm-config.partials.provider-form', [
                            'provider' => 'anthropic',
                            'label' => 'Anthropic',
                            'config' => $configs['anthropic'] ?? null
                        ])

                        <div class="separator separator-dashed my-6"></div>
                        <!-- Google -->
                        @include('admin.tenant.llm-config.partials.provider-form', [
                            'provider' => 'google',
                            'label' => 'Google Gemini',
                            'config' => $configs['google'] ?? null
                        ])

                            <div class="d-flex justify-content-end py-6 px-9">
                                <button type="submit" class="btn btn-primary">{{ __('Save Changes') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
@endsection
