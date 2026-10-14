<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\BrokerTokenListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The gate's edges, without a kernel: the empty-token refusal, the `/v1`
 * boundary, and which requests are not the gate's business at all.
 *
 * @internal
 */
final class BrokerTokenListenerTest extends TestCase
{
    public function test_an_unset_token_refuses_v1_with_503_rather_than_allowing_through(): void
    {
        $event = $this->event(Request::create('/v1/sessions', 'POST'));

        new BrokerTokenListener('')->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()->getStatusCode());
    }

    public function test_an_unset_token_does_not_affect_health(): void
    {
        $event = $this->event(Request::create('/health'));

        new BrokerTokenListener('')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_the_prefix_gates_v1_and_v1_slash_but_not_v10(): void
    {
        $listener = new BrokerTokenListener('secret');

        $under = $this->event(Request::create('/v1/nope'));
        $listener->onKernelRequest($under);
        self::assertNotNull($under->getResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $under->getResponse()->getStatusCode());

        $bare = $this->event(Request::create('/v1'));
        $listener->onKernelRequest($bare);
        self::assertNotNull($bare->getResponse());

        $lookalike = $this->event(Request::create('/v10/nope'));
        $listener->onKernelRequest($lookalike);
        self::assertNull($lookalike->getResponse(), '/v10 is a different surface and must not be gated by accident');
    }

    /**
     * `getPathInfo()` is not decoded, and the router decodes: a gate that reads
     * the encoded form waves `/v1%2fsessions` through while the router hands it
     * to the sessions controller. The listener must decode the way the router
     * does — once, with `rawurldecode` — so the two agree on what a `/v1`
     * request is.
     */
    #[DataProvider('encodedV1Paths')]
    public function test_a_percent_encoded_v1_path_is_gated(string $path): void
    {
        $event = $this->event(Request::create($path, 'POST'));

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedV1Paths(): iterable
    {
        yield 'encoded slash' => ['/v1%2fsessions'];
        yield 'uppercase encoded slash' => ['/v1%2Fsessions'];
        yield 'encoded v' => ['/%76%31/sessions'];
        yield 'encoded prefix and slash' => ['/%76%31%2Fsessions'];
        yield 'bare encoded' => ['/%76%31'];
    }

    /**
     * One decode, not a loop: the router decodes once, so `/v1%252fsessions`
     * is not a `/v1` request to either of them.
     */
    public function test_a_double_encoded_path_is_not_gated_because_routing_would_not_match_it(): void
    {
        $event = $this->event(Request::create('/v1%252fsessions'));

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Decoding can only widen the gate, never move it off a path the router
     * would not have matched anyway: an encoding of an open path stays open.
     */
    public function test_an_encoding_of_a_public_path_is_not_gated(): void
    {
        $event = $this->event(Request::create('/%68ealth'));

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_a_sub_request_is_not_gated(): void
    {
        $event = $this->event(Request::create('/v1/sessions'), HttpKernelInterface::SUB_REQUEST);

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function test_a_correct_token_passes(): void
    {
        $request = Request::create('/v1/sessions');
        $request->headers->set('Authorization', 'Bearer secret');
        $event = $this->event($request);

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    #[DataProvider('credentialsThatMustNotPass')]
    public function test_a_credential_that_is_not_the_token_is_refused(string $header): void
    {
        $request = Request::create('/v1/sessions');
        $request->headers->set('Authorization', $header);
        $event = $this->event($request);

        new BrokerTokenListener('secret')->onKernelRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
        self::assertSame('Bearer', $event->getResponse()->headers->get('WWW-Authenticate'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialsThatMustNotPass(): iterable
    {
        yield 'scheme only' => ['Bearer'];
        yield 'wrong token' => ['Bearer other'];
        yield 'different scheme' => ['Token secret'];
        yield 'trailing junk' => ['Bearer secret and more'];
        yield 'empty' => [''];
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
