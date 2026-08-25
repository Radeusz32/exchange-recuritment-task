<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\JsonAuthenticationEntryPoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class JsonAuthenticationEntryPointTest extends TestCase
{
    public function testItAnswersWithJsonInsteadOfAnHtmlErrorPage(): void
    {
        $response = new JsonAuthenticationEntryPoint()->start(new Request(), new AuthenticationException());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $body);
    }

    public function testItWorksWithoutAnAuthenticationException(): void
    {
        $response = new JsonAuthenticationEntryPoint()->start(new Request());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }
}
