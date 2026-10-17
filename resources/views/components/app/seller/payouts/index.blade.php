@php
/* @var Nightcap\LaravelDevKit\Actions\PrepareCmsCache $prepare */
$prepare->prepare();
@endphp

@section('title', __('Payouts'))
@section('crumb', (fn () => [
    route('seller.dashboard') => __('Dashboard'),
    null,
])())

@section('page-header', [
    'heading' => __('Payouts'),
    'eyebrow' => __('Selling workspace'),
    'description' => __('Payments leave this workspace and settle in your ledger. Pushes to your payout account are separate from product updates.'),
])

<div class="space-y-6">
    <x-app.ui.header>
        <x-slot:default>
            <x-app.ui.seller.payouts emerging-is-empty="false" :user="auth()->user()" />
        </x-slot:default>

        @can('viewPayouts')
            <x-slot:payouts>
                <form method="POST" action="{{ route('seller.payouts.request') }}">
                    @csrf
                    @method('POST')
                    <x-app.ui.button variant="primary" icon="arrow-right-on-rectangle" :label="__('Request a payout')" />
                    @error('payouts') <x-app.ui.message dialog="Seller payouts error" /> @enderror
                </form>
            </x-slot:payouts>
        @else
            <x-slot:payouts>
                <x-app.ui.button variant="ghost" icon="arrow-right-on-rectangle" :label="__('Request a payout')" disabled="true" :hint="__('Your plan does not yet allow payouts.')" />
        </x-slot:payouts>
        @endcan
    </x-slot:payouts>

    <x-app.ui.payouts-table />
</div>

@endsection
