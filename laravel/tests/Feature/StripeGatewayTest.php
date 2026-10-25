<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Designation;
use App\Models\GatewayCustomer;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\Stripe\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Exercises StripeGateway through the real Stripe SDK, with its HTTP client
 * replaced by one that records each request and returns canned responses,
 * so no test ever calls Stripe.
 */
class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SUCCESS_URL = 'http://localhost/donate/thank-you';

    private const CANCEL_URL = 'http://localhost/donate';

    /**
     * Requests the SDK made, in order.
     *
     * @var array<int, array{method: string, path: string, params: array, headers: array<string, string>}>
     */
    private array $requests = [];

    /**
     * Responses to return, in order: [status, body, headers].
     *
     * @var array<int, array{0: int, 1: array, 2?: array<string, string>}>
     */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://localhost:8080',
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.donation_product_id' => 'prod_donation',
        ]);

        $test = $this;

        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private StripeGatewayTest $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->test->respond($method, $absUrl, $headers, $params);
            }
        });
    }

    protected function tearDown(): void
    {
        // The SDK creates its real HTTP client again when next needed
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /**
     * Record a request and return the next canned response. Called by the
     * fake HTTP client.
     */
    public function respond(string $method, string $url, array $headers, array $params): array
    {
        $parsed = [];

        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $parsed[strtolower($name)] = trim($value);
        }

        $this->requests[] = [
            'method' => $method,
            'path' => parse_url($url, PHP_URL_PATH),
            'params' => $params,
            'headers' => $parsed,
        ];

        $this->assertNotEmpty($this->responses, "Unexpected Stripe request: {$method} {$url}");

        [$status, $body, $responseHeaders] = array_shift($this->responses) + [2 => []];

        return [json_encode($body), $status, $responseHeaders];
    }

    private function queue(int $status, array $body, array $headers = []): void
    {
        $this->responses[] = [$status, $body, $headers];
    }

    private function queueCustomerSearch(?string $existingId = null): void
    {
        $this->queue(200, [
            'object' => 'list',
            'url' => '/v1/customers',
            'has_more' => false,
            'data' => $existingId ? [['id' => $existingId, 'object' => 'customer']] : [],
        ]);
    }

    private function queueSession(string $id = 'cs_test_example', array $headers = []): void
    {
        $this->queue(200, [
            'id' => $id,
            'object' => 'checkout.session',
            'url' => "https://checkout.stripe.com/c/pay/{$id}",
            'expires_at' => 1793000000,
            'livemode' => false,
        ], $headers);
    }

    private function start(Checkout $checkout)
    {
        return app(PaymentGateway::class)->startCheckout($checkout, self::SUCCESS_URL, self::CANCEL_URL);
    }

    public function test_the_gateway_is_stripe(): void
    {
        $this->assertInstanceOf(StripeGateway::class, app(PaymentGateway::class));
        $this->assertSame('stripe', app(PaymentGateway::class)->name());
    }

    public function test_the_gateway_is_available_only_with_a_secret_key(): void
    {
        $this->assertTrue(app(PaymentGateway::class)->isAvailable());

        config(['services.stripe.secret' => null]);

        // Created and asked without a key, which would fail to create a client
        $this->assertFalse(app(PaymentGateway::class)->isAvailable());
        $this->assertEmpty($this->requests);
    }

    public function test_a_one_time_gift_from_a_new_donor_creates_a_customer_and_a_donate_session(): void
    {
        $user = User::factory()->create(['name' => 'Jamie Rivera', 'email' => 'jamie@example.com']);
        $checkout = Checkout::factory()->oneTime()->for($user)->create(['amount_cents' => 3750, 'gateway_session_id' => null]);

        $this->queueCustomerSearch();
        $this->queue(200, ['id' => 'cus_new', 'object' => 'customer']);
        $this->queueSession('cs_test_one_time');

        $session = $this->start($checkout);

        $this->assertSame('cs_test_one_time', $session->id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_one_time', $session->url);
        $this->assertSame(1793000000, $session->expiresAt->getTimestamp());

        [$search, $create, $sessionRequest] = $this->requests;

        $this->assertSame(['get', '/v1/customers'], [$search['method'], $search['path']]);
        $this->assertSame(['email' => 'jamie@example.com', 'limit' => 1], $search['params']);

        $this->assertSame(['post', '/v1/customers'], [$create['method'], $create['path']]);
        $this->assertSame(['email' => 'jamie@example.com', 'name' => 'Jamie Rivera'], $create['params']);

        $params = $sessionRequest['params'];
        $metadata = ['checkout_reference' => $checkout->reference, 'designation' => Designation::GENERAL_FUND_SLUG];

        $this->assertSame(['post', '/v1/checkout/sessions'], [$sessionRequest['method'], $sessionRequest['path']]);
        $this->assertSame('payment', $params['mode']);
        $this->assertSame('cus_new', $params['customer']);
        $this->assertSame('donate', $params['submit_type']);
        $this->assertSame($checkout->reference, $params['client_reference_id']);
        $this->assertSame(
            [['price_data' => ['currency' => 'usd', 'product' => 'prod_donation', 'unit_amount' => 3750], 'quantity' => 1]],
            $params['line_items'],
        );
        $this->assertSame(self::SUCCESS_URL, $params['success_url']);
        $this->assertSame(self::CANCEL_URL, $params['cancel_url']);
        $this->assertSame($metadata, $params['metadata']);
        $this->assertSame(['metadata' => $metadata], $params['payment_intent_data']);
        $this->assertArrayNotHasKey('subscription_data', $params);
        $this->assertArrayNotHasKey('payment_method_types', $params);

        $this->assertSame("checkout-{$checkout->reference}-cus_new", $sessionRequest['headers']['idempotency-key']);
        $this->assertSame(config('services.stripe.api_version'), $sessionRequest['headers']['stripe-version']);

        $this->assertSame(['cus_new'], $user->gatewayCustomers()->pluck('gateway_customer_id')->all());
    }

    public function test_recurring_gifts_start_subscriptions_with_their_interval(): void
    {
        // Checkouts are monthly by default
        foreach (['monthly' => 'month', 'annual' => 'year'] as $state => $interval) {
            $this->requests = [];
            $checkout = ($state === 'annual' ? Checkout::factory()->annual() : Checkout::factory())->create();
            GatewayCustomer::factory()->for($checkout->user)->create(['gateway_customer_id' => "cus_{$state}"]);

            $this->queueSession("cs_test_{$state}");

            $this->start($checkout);

            $params = $this->requests[0]['params'];

            $this->assertSame('subscription', $params['mode'], $state);
            $this->assertSame(['interval' => $interval], $params['line_items'][0]['price_data']['recurring']);
            $this->assertSame($checkout->reference, $params['subscription_data']['metadata']['checkout_reference']);
            $this->assertArrayNotHasKey('submit_type', $params);
            $this->assertArrayNotHasKey('payment_intent_data', $params);
        }
    }

    public function test_a_donors_recorded_customer_is_reused_without_searching_stripe(): void
    {
        $customer = GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_recorded']);
        $checkout = Checkout::factory()->for($customer->user)->create();

        $this->queueSession();

        $this->start($checkout);

        $this->assertCount(1, $this->requests);
        $this->assertSame('cus_recorded', $this->requests[0]['params']['customer']);
    }

    public function test_an_existing_stripe_customer_with_the_donors_email_is_reused_and_recorded(): void
    {
        // Such as a donor who gave through a Payment Link before
        $checkout = Checkout::factory()->create();

        $this->queueCustomerSearch('cus_payment_link');
        $this->queueSession();

        $this->start($checkout);

        $this->assertCount(2, $this->requests);
        $this->assertSame('cus_payment_link', $this->requests[1]['params']['customer']);
        $this->assertTrue(GatewayCustomer::findByGatewayId('stripe', 'cus_payment_link')->user->is($checkout->user));
    }

    public function test_a_soft_deleted_customer_record_found_again_is_restored_instead_of_duplicated(): void
    {
        $record = GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_found_again']);
        $record->delete();
        $checkout = Checkout::factory()->for($record->user)->create();

        $this->queueCustomerSearch('cus_found_again');
        $this->queueSession();

        $this->start($checkout);

        $this->assertNotSoftDeleted($record);
        $this->assertSame(1, GatewayCustomer::withTrashed()->count());
    }

    public function test_a_customer_deleted_in_stripe_is_replaced(): void
    {
        $stale = GatewayCustomer::factory()->create(['gateway_customer_id' => 'cus_deleted']);
        $checkout = Checkout::factory()->for($stale->user)->create();

        $this->queue(400, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'param' => 'customer',
            'message' => "No such customer: 'cus_deleted'",
        ]]);
        $this->queueCustomerSearch();
        $this->queue(200, ['id' => 'cus_replacement', 'object' => 'customer']);
        $this->queueSession();

        $session = $this->start($checkout);

        $this->assertSame('cs_test_example', $session->id);
        $this->assertSoftDeleted($stale);
        $this->assertSame('cus_replacement', end($this->requests)['params']['customer']);
        $this->assertSame("checkout-{$checkout->reference}-cus_replacement", end($this->requests)['headers']['idempotency-key']);
    }

    public function test_other_stripe_errors_are_not_retried(): void
    {
        $customer = GatewayCustomer::factory()->create();
        $checkout = Checkout::factory()->for($customer->user)->create();

        $this->queue(400, ['error' => [
            'type' => 'invalid_request_error',
            'param' => 'line_items[0][price_data][product]',
            'message' => 'No such product',
        ]]);

        $this->assertThrows(fn () => $this->start($checkout), \Stripe\Exception\InvalidRequestException::class);

        $this->assertCount(1, $this->requests);
        $this->assertNotSoftDeleted($customer);
    }

    public function test_a_stripe_notice_is_logged_instead_of_failing_the_call(): void
    {
        $customer = GatewayCustomer::factory()->create();
        $checkout = Checkout::factory()->for($customer->user)->create();

        // Turn off the containers' suppression, so the SDK raises the notice
        $suppress = getenv('STRIPE_SUPPRESS_NOTICES');
        putenv('STRIPE_SUPPRESS_NOTICES=false');

        Log::spy();

        $this->queueSession('cs_test_notice', ['stripe-notice' => 'This API version is deprecated.']);

        try {
            $session = $this->start($checkout);
        } finally {
            putenv($suppress === false ? 'STRIPE_SUPPRESS_NOTICES' : "STRIPE_SUPPRESS_NOTICES={$suppress}");
        }

        $this->assertSame('cs_test_notice', $session->id);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'This API version is deprecated.'));
    }
}
