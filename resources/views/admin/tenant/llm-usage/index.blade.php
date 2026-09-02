@extends('layouts.admin.master')

@section('title', __('LLM Usage'))

@section('header-actions')
    <button type="button" class="btn btn-sm btn-primary fw-bolder" data-bs-toggle="modal" data-bs-target="#kt_modal_buy_tokens">
        <i class="ki-duotone ki-plus fs-2"></i>
        {{ __('Purchase Pack') }}
    </button>
@endsection

@section('content')
    <div class="post d-flex flex-column-fluid" id="kt_post">
        <div id="kt_content_container" class="container-xxl">

            <!-- Top-up Balance Section -->
            <div class="row g-5 g-xl-10 mb-5 mb-xl-10">
                <div class="col-xl-6">
                    <div class="card card-flush h-md-100 bg-light-primary">
                        <div class="card-header pt-5">
                            <div class="card-title d-flex flex-column">
                                <span class="fs-2hx fw-bold text-primary me-2 lh-1 ls-n2">{{ number_format($topupBalance) }}</span>
                                <span class="text-primary pt-1 fw-semibold fs-6">{{ __('Top-up Token Balance') }}</span>
                            </div>
                        </div>
                        <div class="card-body d-flex flex-column justify-content-end">
                            <p class="text-gray-600 fs-7 mb-0">
                                {{ __('These tokens are used as a fallback when your monthly plan quota is exhausted.') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card card-flush h-md-100">
                        <div class="card-header pt-5">
                            <div class="card-title d-flex flex-column">
                                <span class="fs-2 fw-bold text-gray-800 me-2 lh-1">{{ __('Add More Tokens') }}</span>
                                <span class="text-gray-500 pt-1 fw-semibold fs-6">{{ __('Top-up your account instantly') }}</span>
                            </div>
                        </div>
                        <div class="card-body d-flex align-items-center">
                            <p class="text-gray-400 fw-bold fs-7 mb-0">
                                {{ __('Purchase one-time token packs to avoid service interruption and maintain seamless access to LLM features.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="row g-5 g-xl-8 mb-5 mb-xl-10">
                <div class="col-xl-3">
                    <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
                        <div class="card-body">
                            <div class="text-gray-900 fw-bolder fs-2 mb-2 mt-5">
                                {{ number_format($totalUsage->total_tokens ?? 0) }}
                            </div>
                            <div class="fw-bold text-gray-400">{{ __('Total Tokens') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
                        <div class="card-body">
                            <div class="text-gray-900 fw-bolder fs-2 mb-2 mt-5">
                                ${{ number_format($totalUsage->total_cost ?? 0, 4) }}
                            </div>
                            <div class="fw-bold text-gray-400">{{ __('Total Cost (Est.)') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
                        <div class="card-body">
                            <div class="text-gray-900 fw-bolder fs-2 mb-2 mt-5">
                                {{ number_format($totalUsage->prompt_tokens ?? 0) }}
                            </div>
                            <div class="fw-bold text-gray-400">{{ __('Input Tokens') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3">
                    <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
                        <div class="card-body">
                            <div class="text-gray-900 fw-bolder fs-2 mb-2 mt-5">
                                {{ number_format($totalUsage->completion_tokens ?? 0) }}
                            </div>
                            <div class="fw-bold text-gray-400">{{ __('Output Tokens') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Usage Table -->
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3>{{ __('Recent Activity') }}</h3>
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                            <thead>
                                <tr class="text-start text-muted fw-bolder fs-7 text-uppercase gs-0">
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Model') }}</th>
                                    <th>{{ __('User / Key') }}</th>
                                    <th>{{ __('Tokens') }}</th>
                                    <th>{{ __('Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-bold">
                                @forelse($recentUsage as $usage)
                                    <tr>
                                        <td>{{ $usage->created_at->format('M d, H:i:s') }}</td>
                                        <td>
                                            <span class="badge badge-light-primary">{{ $usage->model }}</span>
                                        </td>
                                        <td>
                                            @if($usage->user)
                                                <div class="d-flex align-items-center">
                                                    <div class="d-flex justify-content-start flex-column">
                                                        <a href="#"
                                                            class="text-dark fw-bolder text-hover-primary fs-6">{{ $usage->user->name }}</a>
                                                    </div>
                                                </div>
                                            @elseif($usage->apiKey)
                                                <span class="badge badge-light-info" title="{{ $usage->apiKey->name }}">Key:
                                                    ...{{ $usage->apiKey->key_hint }}</span>
                                            @else
                                                <span class="text-muted">System</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span>{{ number_format($usage->total_tokens) }}</span>
                                                <span class="text-muted fs-7">{{ number_format($usage->prompt_tokens) }} in /
                                                    {{ number_format($usage->completion_tokens) }} out</span>
                                            </div>
                                        </td>
                                        <td>
                                            @if($usage->cost_usd > 0)
                                                ${{ number_format($usage->cost_usd, 5) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-10">
                                            {{ __('No usage recorded yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div class="row">
                        <div
                            class="col-sm-12 col-md-5 d-flex align-items-center justify-content-center justify-content-md-start">
                        </div>
                        <div
                            class="col-sm-12 col-md-7 d-flex align-items-center justify-content-center justify-content-md-end">
                            {{ $recentUsage->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <!-- Modal: Buy Tokens -->
    <div class="modal fade" id="kt_modal_buy_tokens" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mw-650px">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="fw-bolder">{{ __('Purchase Token Pack') }}</h2>
                    <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                </div>
                <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                    <form id="kt_modal_buy_tokens_form" class="form" action="{{ route('billing.llm-checkout') }}" method="POST">
                        @csrf
                        <div class="fv-row mb-10">
                            <label class="d-flex align-items-center fs-5 fw-bold mb-2">
                                <span class="required">{{ __('Select a Token Pack') }}</span>
                            </label>
                            
                            <div class="row g-9">
                                @foreach($tokenPacks as $key => $pack)
                                    <div class="col-12">
                                        <label class="btn btn-outline btn-outline-dashed btn-outline-default d-flex text-start p-6 mb-3">
                                            <span class="form-check form-check-custom form-check-solid form-check-sm align-items-start mt-1">
                                                <input class="form-check-input" type="radio" name="pack" value="{{ $key }}" {{ $loop->first ? 'checked' : '' }} />
                                            </span>
                                            <span class="ms-5">
                                                <span class="fs-4 fw-bolder text-gray-800 d-block">{{ $pack['name'] }}</span>
                                                <span class="fw-bold text-gray-400 d-block">{{ number_format($pack['tokens']) }} tokens</span>
                                                <span class="text-primary fw-bolder fs-3 mt-2 d-block">${{ number_format($pack['price'], 2) }}</span>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="text-center">
                            <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                            <button type="submit" class="btn btn-primary" id="kt_modal_buy_tokens_submit">
                                <span class="indicator-label">{{ __('Checkout & Pay') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endpush