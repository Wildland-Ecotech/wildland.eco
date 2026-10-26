<?php

namespace Tests\Concerns;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Runs code that calls Stripe through the real Stripe SDK, with the SDK's
 * HTTP client replaced by one that records each request and returns canned
 * responses, so no test ever calls Stripe. Configures Stripe as local
 * development does: test mode, with placeholder keys.
 *
 * Queue responses in order with queueStripeResponse(), then inspect
 * $stripeRequests. A request with no response queued fails the test.
 */
trait FakesStripeApi
{
    /**
     * Requests the SDK made, in order.
     *
     * @var array<int, array{method: string, path: string, params: array, headers: array<string, string>}>
     */
    protected array $stripeRequests = [];

    /**
     * Responses to return, in order: [status, body, headers].
     *
     * @var array<int, array{0: int, 1: array, 2: array<string, string>}>
     */
    protected array $stripeResponses = [];

    /**
     * Called by Laravel's test case before each test.
     */
    protected function setUpFakesStripeApi(): void
    {
        config([
            'app.url' => 'http://localhost:8080',
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.webhook_secret' => 'whsec_test_example',
            'services.stripe.donation_product_id' => 'prod_donation',
        ]);

        $test = $this;

        ApiRequestor::setHttpClient(new class($test) implements ClientInterface
        {
            public function __construct(private object $test) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return $this->test->respondToStripe($method, $absUrl, $headers, $params);
            }
        });
    }

    /**
     * Called by Laravel's test case after each test. The SDK creates its
     * real HTTP client again when next needed.
     */
    protected function tearDownFakesStripeApi(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * Record a request and return the next queued response. Called by the
     * fake HTTP client.
     */
    public function respondToStripe(string $method, string $url, array $headers, array $params): array
    {
        $parsed = [];

        foreach ($headers as $header) {
            [$name, $value] = explode(':', $header, 2);
            $parsed[strtolower($name)] = trim($value);
        }

        $this->stripeRequests[] = [
            'method' => $method,
            'path' => parse_url($url, PHP_URL_PATH),
            'params' => $params,
            'headers' => $parsed,
        ];

        $this->assertNotEmpty($this->stripeResponses, "Unexpected Stripe request: {$method} {$url}");

        [$status, $body, $responseHeaders] = array_shift($this->stripeResponses);

        return [json_encode($body), $status, $responseHeaders];
    }

    /**
     * Queue the response to the next Stripe request.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function queueStripeResponse(int $status, array $body, array $headers = []): void
    {
        $this->stripeResponses[] = [$status, $body, $headers];
    }

    /**
     * Queue a Stripe error response.
     */
    protected function queueStripeError(int $status, string $message, ?string $param = null, ?string $code = null): void
    {
        $this->queueStripeResponse($status, ['error' => array_filter([
            'type' => $status >= 500 ? 'api_error' : 'invalid_request_error',
            'message' => $message,
            'param' => $param,
            'code' => $code,
        ])]);
    }
}
