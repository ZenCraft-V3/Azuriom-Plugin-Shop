@extends('layouts.app')

@section('title', trans('shop::messages.profile.payments'))

@section('content')
    <h1>{{ trans('shop::messages.profile.payments') }}</h1>

    <div class="card mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead class="table-dark">
                    <tr>
{{--                        <th scope="col">#</th>--}}
                        <th scope="col">{{ trans('messages.fields.date') }}</th>
                        <th scope="col">{{ trans('shop::messages.fields.price') }}</th>
                        <th scope="col">{{ trans('messages.fields.type') }}</th>
                        <th scope="col">{{ trans('messages.fields.status') }}</th>
                        <th scope="col">{{ trans('shop::messages.fields.payment_id') }}</th>
                        <th scope="col">{{ trans('messages.fields.action') }}</th>
                    </tr>
                    </thead>
                    <tbody>

                    @foreach($payments as $payment)
                        <tr>
{{--                            <th scope="row">{{ $payment->id }}</th>--}}
                            <td>{{ format_date($payment->created_at, true) }}</td>
                            <td>{{ $payment->formatPrice() }}</td>
                            <td>{{ $payment->getTypeName() }}</td>
                            <td>
                                <span class="badge bg-{{ $payment->statusColor() }}">
                                    {{ trans('shop::admin.payments.status.'.$payment->status) }}
                                </span>
                            </td>
                            <td>{{ $payment->transaction_id ?? trans('messages.unknown') }}</td>
                            <td>
                                @if($payment->transaction_id !== null && Str::startsWith($payment->transaction_id, 'pi_'))
                                    <a href="{{ route('shop.payments.receipt', $payment) }}" class="btn btn-primary btn-sm" target="_blank">
                                        <i class="bi bi-gear"></i> {{ trans('shop::messages.actions.manage') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if(! $subscriptions->isEmpty())
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="card-title">{{ trans('shop::messages.profile.subscriptions') }}</h2>

                <div class="table-responsive">
                    <table class="table">
                        <thead class="table-dark">
                        <tr>
{{--                            <th scope="col">#</th>--}}
                            <th scope="col">{{ trans('messages.fields.date') }}</th>
                            <th scope="col">{{ trans('shop::messages.fields.price') }}</th>
{{--                            @if(! use_site_money())--}}
{{--                                <th scope="col">{{ trans('messages.fields.type') }}</th>--}}
{{--                            @endif--}}
                            <th scope="col">{{ trans('shop::messages.fields.package') }}</th>
                            <th scope="col">{{ trans('messages.fields.status') }}</th>
{{--                            <th scope="col">{{ trans('shop::messages.fields.subscription_id') }}</th>--}}

                            <th scope="col">{{ trans('shop::messages.fields.renewal_date') }}</th>
                            <th scope="col">{{ trans('messages.fields.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>

                        @foreach($subscriptions as $subscription)
                            <tr>
{{--                                <th scope="row">{{ $subscription->id }}</th>--}}
                                <td>{{ format_date($subscription->created_at) }}</td>
                                <td>{{ $subscription->formatPrice() }}</td>
{{--                                @if(! use_site_money())--}}
{{--                                    <td>{{ $subscription->getTypeName() }}</td>--}}
{{--                                @endif--}}
                                <td>{{ $subscription->package?->name ?? trans('messages.unknown') }}</td>
                                <td>
                                    <span class="badge bg-{{ $subscription->statusColor() }}">
                                        {{ trans('shop::admin.subscriptions.status.'.$subscription->status) }}
                                    </span>
                                </td>
{{--                                <td>{{ $subscription->subscription_id ?? trans('messages.unknown') }}</td>--}}
                                <td>
                                    @if($subscription->isCanceled())
                                        <span class="badge bg-{{ $subscription->statusColor() }}">
                                            {{ "Résiliation le ".format_date($subscription->ends_at) }}
                                        </span>
                                    @else
                                        {{ format_date($subscription->ends_at) }}
                                    @endif
                                </td>
                                <td>
                                    @if($subscription->isActive())
                                        @if($subscription->isWithSiteMoney() && ! $subscription->isCanceled())
                                            <form action="{{ route('shop.subscriptions.destroy', $subscription) }}" method="POST">
                                                @method('DELETE')
                                                @csrf

                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="bi bi-x-circle"></i> {{ trans('messages.actions.cancel') }}
                                                </button>
                                            </form>
                                        @elseif(! $subscription->isWithSiteMoney())
                                            <a href="{{ route('shop.subscriptions.manage', $subscription) }}" class="btn btn-primary btn-sm">
                                                <i class="bi bi-gear"></i> {{ trans('shop::messages.actions.manage') }}
                                            </a>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if(use_site_money())
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">{{ trans('shop::messages.giftcards.add') }}</h2>

                <form action="{{ route('shop.giftcards.add') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <input type="text" class="form-control @error('code') is-invalid @enderror" placeholder="{{ trans('shop::messages.fields.code') }}" id="code" name="code" value="{{ old('code', $giftCardCode) }}">

                        @error('code')
                        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        {{ trans('messages.actions.send') }}
                    </button>
                </form>
            </div>
        </div>
    @endif
@endsection
