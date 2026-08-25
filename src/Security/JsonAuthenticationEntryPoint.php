<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers requests that carry no credentials at all. Without it Symfony renders its
 * HTML error page, so an API client asking for JSON got a web page instead - while a
 * request with a bad token already received JSON from the authenticator.
 */
final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['error' => 'Authentication required. Send a bearer token in the Authorization header.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
