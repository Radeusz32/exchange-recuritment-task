<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Keeps the API answering in JSON. Symfony renders an HTML error page for anything the
 * controllers do not catch themselves - an unknown path, a wrong method - which an API
 * client cannot do anything with.
 */
#[AsEventListener(event: 'kernel.exception')]
final readonly class ApiExceptionListener
{
    public function __construct(private bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();

            // The status text rather than the exception message: the latter spells out
            // internals such as the routes that were tried.
            $event->setResponse(new JsonResponse(
                ['error' => Response::$statusTexts[$status] ?? 'Request failed.'],
                $status,
                $throwable->getHeaders(),
            ));

            return;
        }

        // Real failures keep Symfony's debug page while developing.
        if ($this->debug) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Internal server error.'],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
