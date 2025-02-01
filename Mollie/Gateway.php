<?php

namespace App\Gateways\Mollie;

use LaraPay\Framework\Interfaces\GatewayFoundation;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Payment;
use Illuminate\Http\Request;
use Exception;

class Gateway extends GatewayFoundation
{
    /**
     * Unique identifier for this gateway.
     *
     * @var string
     */
    protected string $identifier = 'mollie';

    /**
     * Gateway version.
     *
     * @var string
     */
    protected string $version = '1.0.0';

    /**
     * Specify supported currencies. Mollie supports many, but we list a couple here.
     *
     * @var array
     */
    protected array $currencies = [];

    /**
     * Define the gateway configuration fields
     * that are required for Mollie's API.
     *
     * @return array
     */
    public function config(): array
    {
        return [
            'api_key' => [
                'label'       => 'Mollie API Key',
                'description' => 'Enter your Mollie API Key here.',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
        ];
    }

    /**
     * Create a payment on Mollie and redirect the user to the Mollie checkout page.
     *
     * @param  \LaraPay\Framework\Payment  $payment
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Exception
     */
    public function pay($payment)
    {
        // Prepare Mollie payment creation parameters
        $requestData = [
            'amount' => [
                'currency' => $payment->currency,
                // Mollie expects a string in the format "12.99", so format the float:
                'value' => number_format($payment->total(), 2, '.', ''),
            ],
            'description' => $payment->description,
            'cancelUrl' => $payment->cancelUrl(),
            // The URL the customer will be redirected to after the payment process
            'redirectUrl' => $payment->webhookUrl(),
            // Your webhook to receive asynchronous payment status updates
//            'webhookUrl'  => $payment->webhookUrl(),
            // Some metadata to help you identify the payment in your system
            'metadata' => [
                'payment_id' => $payment->id,
            ],
        ];

        // Make the API call to Mollie
        $response = Http::withToken($payment->gateway->config('api_key'))
            ->post('https://api.mollie.com/v2/payments', $requestData);

        if ($response->failed()) {
            throw new Exception('Failed to create the payment using Mollie API');
        }

        // Store Mollie's payment ID in your local Payment record
        $molliePayment = $response->json();
        $payment->update([
            // "id" is Mollie's identifier for this payment
            'transaction_id' => $molliePayment['id'],
        ]);

        // Redirect user to the Mollie checkout page
        if (isset($molliePayment['_links']['checkout']['href'])) {
            return redirect()->away($molliePayment['_links']['checkout']['href']);
        }

        // In a rare case Mollie didn't provide the checkout URL:
        throw new Exception('Mollie checkout URL not found in the response');
    }

    /**
     * Handle asynchronous callbacks (webhooks) from Mollie.
     * Mollie calls this endpoint whenever the payment status changes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Exception
     */
    public function callback(Request $request)
    {
        // The 'payment_id' is set in your metadata above, which
        // is automatically appended to the webhook URL by LaraPay.
        // We retrieve our Payment record:
        $payment = Payment::find($request->get('payment_id'));

        if (! $payment) {
            throw new Exception('Payment record not found');
        }

        if($payment->isPaid()) {
            return redirect($payment->successUrl());
        }

        // Use the transaction ID we stored earlier to fetch the latest
        // payment details from Mollie and confirm status, amount, etc.
        $transactionId = $payment->transaction_id;

        if (! $transactionId) {
            throw new Exception('Missing Mollie transaction ID on Payment record');
        }

        // Fetch payment status from Mollie
        $response = Http::withToken($payment->gateway->config('api_key'))
            ->get("https://api.mollie.com/v2/payments/{$transactionId}");

        if ($response->failed()) {
            throw new Exception('Failed to retrieve the payment from Mollie');
        }

        $molliePayment = $response->json();

        // Check if Mollie says the payment is "paid"
        if (isset($molliePayment['status']) && $molliePayment['status'] === 'paid') {
            $payment->completed($molliePayment['id'], $molliePayment);
            return redirect($payment->successUrl());
        } else {
            // You could optionally handle other statuses such as "canceled" or "expired"
            throw new Exception('Unexpected or incomplete payment status');
        }
    }
}
