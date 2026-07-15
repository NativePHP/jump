<?php

namespace App\NativeComponents\Playground;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Wallet\PaymentCancelled;
use Native\Mobile\Events\Wallet\PaymentCompleted;
use Native\Mobile\Events\Wallet\PaymentFailed;
use Native\Mobile\Facades\MobileWallet;

class WalletDemo extends NativeComponent
{
    public bool $available = false;

    public string $state = 'idle';

    public string $detail = '';

    public function navTitle(): string
    {
        return 'Wallet';
    }

    public function mount(): void
    {
        $this->available = MobileWallet::isAvailable();
    }

    public function pay(): void
    {
        $this->state = 'processing';
        $this->detail = '';

        $intent = MobileWallet::createPaymentIntent(1999, 'usd', ['demo' => 'playground']);

        if (! $intent || empty($intent->clientSecret)) {
            $this->state = 'failed';
            $this->detail = 'Could not create a payment intent — Stripe is not configured on this build.';

            return;
        }

        MobileWallet::presentPaymentSheet(
            $intent->clientSecret,
            'Playground Demo Store',
            config('services.stripe.publishable', ''),
            config('services.stripe.apple_merchant_id', ''),
        );
    }

    #[On(PaymentCompleted::class)]
    public function handleCompleted(string $paymentIntentId, int $amount): void
    {
        $this->state = 'completed';
        $this->detail = 'Paid $'.number_format($amount / 100, 2)." · {$paymentIntentId}";
    }

    #[On(PaymentFailed::class)]
    public function handleFailed(): void
    {
        $this->state = 'failed';
        $this->detail = 'The payment could not be processed.';
    }

    #[On(PaymentCancelled::class)]
    public function handleCancelled(): void
    {
        $this->state = 'idle';
        $this->detail = 'Payment cancelled.';
    }

    public function reset(): void
    {
        $this->state = 'idle';
        $this->detail = '';
    }

    public function render(): View
    {
        return view('native.playground.wallet-demo');
    }
}
