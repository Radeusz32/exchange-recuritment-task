<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Request\CreateWalletRequest;
use App\Dto\Request\DepositRequest;
use App\Dto\Request\TransferRequest;
use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidAmountException;
use App\Exception\InvalidRequestException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/wallets')]
final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
        private readonly TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $payload = CreateWalletRequest::fromArray($this->decodeBody($request));
            $wallet = $this->walletService->createWallet($user->getIdNotNull(), $payload->currency);
        } catch (InvalidRequestException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletAlreadyExistsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    #[Route('/transfer', methods: ['POST'])]
    public function transfer(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $payload = TransferRequest::fromArray($this->decodeBody($request));

            $transaction = $this->transferService->transfer(
                $user->getIdNotNull(),
                $payload->fromWalletId,
                $payload->toWalletId,
                $payload->amount,
            );
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (InvalidRequestException|InvalidAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (SameWalletTransferException|WalletBlockedException|InsufficientFundsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    #[Route('/{id}/deposit', methods: ['POST'])]
    public function deposit(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        try {
            $payload = DepositRequest::fromArray($this->decodeBody($request));

            $wallet = $this->depositService->deposit(
                $user->getIdNotNull(),
                $id,
                $payload->amount,
            );
        } catch (InvalidRequestException|InvalidAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(new WalletResponse($wallet));
    }

    /**
     * Without this the client has no way of learning what happened to a transfer:
     * the response to POST /transfer only says it was recorded, while completion
     * or rejection happens later, during settlement.
     */
    #[Route('/transactions', methods: ['GET'])]
    public function transactions(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $walletId = $request->query->get('walletId');

        if (null !== $walletId && !ctype_digit($walletId)) {
            return new JsonResponse(['error' => 'walletId must be a positive integer.'], Response::HTTP_BAD_REQUEST);
        }

        $transactions = $this->transactionRepository->findByUserId(
            $user->getIdNotNull(),
            null !== $walletId ? (int) $walletId : null,
        );

        return new JsonResponse(array_map(static fn ($t) => new TransactionResponse($t), $transactions));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): Response
    {
        try {
            $this->walletService->deleteWallet($user->getIdNotNull(), $id);
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletNotEmptyException|WalletHasPendingTransactionsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * An empty body is treated as an empty payload, so the caller gets a message about
     * the missing field rather than a complaint about the syntax.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $content = trim($request->getContent());

        if ('' === $content) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidRequestException::malformedBody();
        }

        if (!is_array($data)) {
            throw InvalidRequestException::malformedBody();
        }

        return $data;
    }
}
