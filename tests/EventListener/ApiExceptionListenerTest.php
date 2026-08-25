<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class ApiExceptionListenerTest extends TestCase
{
    public function testUnknownApiPathAnswersWithJson(): void
    {
        $event = $this->dispatch('/api/nope', new NotFoundHttpException('No route found for "GET /api/nope"'));

        $response = $event->getResponse();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // The exception message spells out internals, so the status text is used instead.
        self::assertSame('Not Found', $body['error']);
    }

    public function testMethodNotAllowedKeepsItsAllowHeader(): void
    {
        $event = $this->dispatch('/api/wallets', new MethodNotAllowedHttpException(['GET', 'POST']));

        $response = $event->getResponse();

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET, POST', $response->headers->get('Allow'));
    }

    public function testRequestsOutsideTheApiAreLeftAlone(): void
    {
        $event = $this->dispatch('/some-page', new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    public function testUnexpectedFailuresKeepTheDebugPageWhileDeveloping(): void
    {
        $event = $this->dispatch('/api/wallets', new RuntimeException('database is gone'), debug: true);

        self::assertNull($event->getResponse());
    }

    public function testUnexpectedFailuresAnswerWithJsonInProduction(): void
    {
        $event = $this->dispatch('/api/wallets', new RuntimeException('database is gone'), debug: false);

        $response = $event->getResponse();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Internal server error.', $body['error']);
        self::assertStringNotContainsString('database is gone', $response->getContent());
    }

    private function dispatch(string $path, Throwable $throwable, bool $debug = false): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        new ApiExceptionListener($debug)($event);

        return $event;
    }
}
