<?php

namespace Azuriom\Plugin\Shop\Payment\Method;

use Azuriom\Azuriom;
use Azuriom\Models\User;
use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Package;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Models\Subscription;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Coupon;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\Charge as StripeCharge;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\BillingPortal\Session as StripeBillingSession;
use Stripe\Webhook;
use Carbon\Carbon;

class StripeMethod extends PaymentMethod
{
    // https://docs.stripe.com/currencies#zero-decimal
    protected const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    // https://docs.stripe.com/currencies#three-decimal
    protected const THREE_DECIMAL_CURRENCIES = [
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    /**
     * The payment method id name.
     *
     * @var string
     */
    protected $id = 'stripe';

    /**
     * The payment method display name.
     *
     * @var string
     */
    protected $name = 'Stripe';

    public function startPayment(Cart $cart, float $amount, string $currency)
    {
        $this->setup();

        $items = $cart->itemsPrice()->map(fn (array $data) => [
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $this->convertAmount($data['unit_price'], $currency),
                'product_data' => [
                    'name' => $data['item']->name(),
                    'description' => $data['item']->buyable()->getDescription(),
                ],
            ],
            'quantity' => $data['item']->quantity,
        ]);

        $payment = $this->createPayment($cart, $amount, $currency);
        $coupon = $this->applyGiftcards($payment, $currency);
        $successUrl = route('shop.payments.success', [$this->id, '%id%']);

        $session = Session::create([
            'mode' => 'payment',
            'billing_address_collection' => 'required',
            'customer_email' => $payment->user->email,
            'line_items' => $items->all(),
            'consent_collection' => ['terms_of_service' => 'required'],
            'success_url' => str_replace('%id%', '{CHECKOUT_SESSION_ID}', $successUrl),
            'cancel_url' => route('shop.cart.index'),
            'client_reference_id' => $payment->id,
            'invoice_creation' => ['enabled' => true],
            'discounts' => $coupon ? [['coupon' => $coupon->id]] : [],
        ]);

        return redirect()->away($session->url);
    }

    public function startSubscription(User $user, Package $package)
    {
        $this->setup();

        $successUrl = route('shop.payments.success', [$this->id, '%id%']);

        $session = Session::create([
            'mode' => 'subscription',
            'billing_address_collection' => 'required',
            'customer_email' => $user->email,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => currency(),
                        'unit_amount' => $this->convertAmount($package->price, currency()),
                        'product_data' => [
                            'name' => $package->name,
                            'description' => $package->short_description,
                        ],
                        'recurring' => [
                            // Convert durations to singular: e.g. months -> month
                            'interval' => rtrim($package->subscriptionPeriodUnit(), 's'),
                            'interval_count' => $package->subscriptionPeriodCount(),
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'consent_collection' => ['terms_of_service' => 'required'],
            'success_url' => str_replace('%id%', '{CHECKOUT_SESSION_ID}', $successUrl),
            'cancel_url' => route('shop.categories.show', $package->category),
            'metadata' => ['user' => $user->id, 'package' => $package->id],
        ]);

        return redirect()->away($session->url);
    }

    public function manage(Subscription $subscription)
    {
        $this->setup();

        $stripeSub = StripeSubscription::retrieve($subscription->subscription_id);

        $billingPortal = StripeBillingSession::create([
            'customer' => $stripeSub->customer,
            'return_url' => route('shop.profile'),
        ]);

        return redirect()->away($billingPortal->url);
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        $this->setup();

        $stripeSub = StripeSubscription::retrieve($subscription->subscription_id);

        $stripeSub->cancel();
    }

    public function receipt(Payment $payment)
    {
        $this->setup();

        /** @var StripePaymentIntent $paymentIntent */
        $paymentIntent = StripePaymentIntent::retrieve($payment->transaction_id);
        /** @var StripeCharge $charge */
        $charge = StripeCharge::retrieve($paymentIntent->latest_charge);

        return redirect()->away($charge->receipt_url);
    }

    public function notification(Request $request, ?string $paymentId)
    {
        $this->setup();

        $endpointSecret = $this->gateway->data['endpoint-secret'];
        $stripeSignature = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($request->getContent(), $stripeSignature, $endpointSecret);
        } catch (SignatureVerificationException $e) {
            return response()->json([
                'error' => 'Invalid signature: '.$e->getMessage(),
            ], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            return $this->processCompletedCheckout($event->data->object);
        }

        if ($event->type === 'invoice.paid') {
            /** @var Invoice $invoice */
            $invoice = $event->data->object;
            $subscriptionId = $invoice->subscription;

            if ($subscriptionId === null) {
                return response()->json(['status' => 'no_subscription'], 400);
            }

            $subscription = Subscription::where('subscription_id', $subscriptionId)->firstOrFail();

            if ($invoice->payment_intent !== null) {
                $this->handleSubscriptionDataUpdate($subscription, $invoice);
                $this->renewSubscription($subscription, $invoice->payment_intent);
            }

            return response()->noContent();
        }

        if ($event->type === 'customer.subscription.deleted') {
            /** @var StripeSubscription $stripeSub */
            $stripeSub = $event->data->object;

            $subscription = Subscription::where('subscription_id', $stripeSub->id)->first();

            if ($subscription === null) {
                Log::warning('Unknown Stripe subscription: '.$stripeSub->id);

                return response()->json(['status' => 'unknown_subscription'], 400);
            }

            $subscription->expire();

            return response()->noContent();
        }

        // ZenCraft START
        /**
         * Handle Stripe subscription updates
         * Allow us to update the local subscription record when the Stripe subscription is updated
         * (e.g. canceled, resumed)
         */
        if($event->type === 'customer.subscription.updated') {
            /** @var StripeSubscription $stripeSub */
            $stripeSub = $event->data->object;

            /** @var Subscription $subscription */
            $subscription = Subscription::where('subscription_id', $stripeSub->id)->first();

            if ($subscription === null) {
                Log::warning('Unknown Stripe subscription: '.$stripeSub->id);

                return response()->json(['status' => 'unknown_subscription'], 400);
            }

            $stripeCanceledAt = $stripeSub->canceled_at;

            if($stripeCanceledAt !== null && $subscription->status !== 'canceled') { // Subscription has been canceled on Stripe
                $subscription->update([
                    'ends_at' => $stripeSub->cancel_at,
                    'status' => 'canceled',
                    'updated_at' => Carbon::now()
                ]);
            }else if($stripeCanceledAt === null && ($stripeSub->status === 'active' || $stripeSub->status === 'trialing') && $subscription->status !== 'active') { // Subscription has been reactivated
                $subscription->update([
                    'ends_at' => $stripeSub->current_period_end,
                    'status' => 'active',
                    'updated_at' => Carbon::now()
                ]);
            }

            return response()->noContent();
        }
        // ZenCraft END

        return response()->json(['status' => 'unknown_event']);
    }

    // ZenCraft START
    protected function handleSubscriptionDataUpdate(Subscription $subscription, Invoice $invoice): void
    {
        // Update subscription data after renewal
        // This ensures our local subscription record aligns with Stripe's data
        $currency = $invoice->currency;
        $subscription->price = $this->retrieveDecimalAmount($invoice->total, $currency); // Make sure to update the price

        // Retrieve the Stripe subscription to check for trial period details
        $stripeSub = StripeSubscription::retrieve($subscription->subscription_id);
        $trialEnd = $stripeSub->trial_end;

        // Handle trial period if present
        if ($trialEnd !== null && $trialEnd > time()) { // Check if there is a trial
            // Update the local subscription end date to match the trial end date
            // This ensures the subscription won't expire before the trial ends
            $trialEndTimeStamp = Carbon::createFromTimestamp($trialEnd)->subMonth();
            $subscription->ends_at = $trialEndTimeStamp; // Update the end
        }
    }
    // ZenCraft END

    protected function processCompletedCheckout(Session $session)
    {
        if ($session->mode === 'subscription') {
            $user = User::find($session->metadata['user']);
            $package = Package::find($session->metadata['package']);
            $invoice = Invoice::retrieve($session->invoice);
            $currency = $session->currency;
            $total = $this->retrieveDecimalAmount($session->amount_total, $currency);

            $sub = $this->createSubscription($user, $package, $session->subscription, $total, $currency);

            //ZenCraft START
            $this->handleSubscriptionDataUpdate($sub, $invoice);
            $paymentIntentId = $invoice->payment_intent ?? "trial-" . $session->subscription; // If the invoice has no payment intent, it is a trial
            return $this->renewSubscription($sub, $paymentIntentId, true);
            //ZenCraft END
        }

        $payment = Payment::find($session->client_reference_id);

        $payment?->update(['transaction_id' => $session->payment_intent]);

        return $this->processPayment($payment);
    }

    protected function applyGiftcards(Payment $payment, string $currency): ?Coupon
    {
        $amount = $payment->giftcards()->sum('amount');

        if ($amount <= 0) {
            return null;
        }

        return Coupon::create([
            'amount_off' => $this->convertAmount($amount, $currency),
            'currency' => $currency,
            'duration' => 'once',
            'max_redemptions' => 1,
            'name' => trans('shop::messages.payment.giftcards'),
        ]);
    }

    /*
     * Adapt the currency to Stripe format. See https://stripe.com/docs/currencies
     */
    protected function convertAmount(float $amount, string $currency): int
    {
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return $amount;
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return $amount * 1000;
        }

        return $amount * 100;
    }

    /*
     * Retrieve decimal amount from Stripe format. See https://stripe.com/docs/currencies
     */
    protected function retrieveDecimalAmount(int $amount, string $currency): float
    {
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return $amount;
        }

        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return $amount / 1000;
        }

        return $amount / 100;
    }

    public function supportsSubscriptions()
    {
        return true;
    }

    public function view(): string
    {
        return 'shop::admin.gateways.methods.stripe';
    }

    public function rules(): array
    {
        return [
            'secret-key' => ['required', 'string'],
            'public-key' => ['required', 'string'],
            'endpoint-secret' => ['nullable', 'string'],
        ];
    }

    protected function setup(): void
    {
        Stripe::setAppInfo('Azuriom', Azuriom::version(), 'https://azuriom.com');
        Stripe::setLogger(logger()->driver());
        Stripe::setApiKey($this->gateway->data['secret-key']);
    }
}
