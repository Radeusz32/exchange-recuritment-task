# Exchange

## About

Exchange is a currency exchange platform where clients can hold accounts with **multi-currency wallets** and convert
funds between supported currencies.

The company earns revenue on every exchange through a **spread** — the difference between the market rate and the rate
offered to the client. The spread is calculated dynamically based on the liquidity of the traded currency pair:

- A **base spread of 0.5%** is applied to every exchange.
- Each currency has a **liquidity score** (USD = 1.00 being the most liquid, HUF = 0.40 the least). Less liquid pairs
  receive a higher spread, because they carry more risk and are harder to hedge.
- The formula: `spread = price × (0.5% ÷ average pair liquidity)`

**Example:** exchanging PLN (liquidity 0.55) to HUF (liquidity 0.40) gives an average pair liquidity of 0.475, so the
spread applied is `0.5% ÷ 0.475 ≈ 1.05%` of the transaction value — compared to only `0.5% ÷ 0.975 ≈ 0.51%` for a
USD/EUR pair.

The spread is charged in the currency the client is paid in and booked on the company wallet when the transaction is
settled. Earnings across all wallets are tracked via the `app:company-wallet` console command.

---

## How a transfer is settled

A transfer is recorded first and settled later — **no balance changes when the request is made**:

1. `POST /api/wallets/transfer` validates the request (both wallets belong to the caller, differ, are not blocked, and
   the source wallet holds the amount) and stores a transaction:
    - `pending` — a normal transfer,
    - `fraud_review` — the transfer is worth **more than 15 000 EUR** (the amount is converted to EUR before it is
      compared against the threshold, whatever currencies are involved) and needs the owner's approval.
2. `app:process-transactions` settles them. `pending` ones are completed automatically; `fraud_review` ones are shown to
   the owner, who approves or rejects each of them.
3. Completing a transaction moves the funds and books the spread on the company wallet. Rejecting one moves nothing —
   the client keeps the money in the source wallet.

The funds are re-checked at settlement time, so a transaction is rejected instead of overdrawing the wallet if the money
was spent by another pending transfer in the meantime, or if a wallet has been blocked or deleted.

> **Note:** approving fraud reviews needs a terminal — run the command with `docker exec -it`. Without one, flagged
> transactions are skipped with a warning and wait for the next interactive run, rather than being decided by nobody.

---

## Prerequisites

Make sure you have [Docker](https://www.docker.com/get-started) and [Docker Compose](https://docs.docker.com/compose/)
installed on your machine.

> **Warning:** Check that you don't have any other services running on port **80** before starting the containers.

---

## Getting started

### 1. Start the containers

Run this command from the project root directory:

```bash
docker compose up -d
```

### 2. Install dependencies

Once the containers are up, install PHP dependencies inside the container:

```bash
docker exec -it php-fpm composer install
```

### 3. Run database migrations

Apply the database schema:

```bash
docker exec -it php-fpm php bin/console doctrine:migrations:migrate
```

### 4. Open the application

The application is available at:

```
http://localhost
```

---

## Running commands inside the PHP container

All PHP/Symfony commands must be run inside the `php-fpm` container. The general pattern is:

```bash
docker exec -it php-fpm <command>
```

**Example** — running tests:

```bash
docker exec -it php-fpm composer tests
```

---

## API Endpoints

All endpoints require Bearer token authentication. Obtain a token with `app:create-user`.

| Method   | Path                        | Description                                                                                                                                                                            |
|----------|-----------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GET`    | `/api/wallets`              | List all wallets belonging to the authenticated user.                                                                                                                                  |
| `POST`   | `/api/wallets`              | Create a new wallet. Body: `{ "currency": "PLN" }`. Supported currencies: `PLN`, `EUR`, `USD`, `GBP`, `JPY`, `CHF`, `HUF`. Returns `409` if a wallet for that currency already exists. A wallet deleted earlier is restored. |
| `POST`   | `/api/wallets/{id}/deposit` | Deposit funds into a wallet. Body: `{ "amount": "500.00" }`. Maximum single deposit: `10000`. Returns `422` if the wallet is blocked.                                                  |
| `POST`   | `/api/wallets/transfer`     | Record a transfer between two wallets of the authenticated user (currency exchange supported). Body: `{ "fromWalletId": 1, "toWalletId": 2, "amount": "100.00" }`. Returns `422` if the wallets are the same, one of them is blocked, or the balance is too low. |
| `DELETE` | `/api/wallets/{id}`         | Delete a wallet of the authenticated user. Returns `204` on success, `409` if the wallet still holds funds or has transactions awaiting processing.                                     |

A wallet is deleted softly — transactions keep referencing it, so the history stays intact — and stops showing up
everywhere in the API. Since a user may hold only one wallet per currency, creating a wallet in a currency that was
deleted before restores that wallet instead of failing with `409`.

Requests on a wallet that does not exist, belongs to somebody else, or has been deleted all return `404`. A malformed
JSON body returns `400`.

A ready-to-use Postman collection is available at [`exchange-api.postman_collection.json`](./exchange-api.postman_collection.json).
Set the `authToken` variable to the token returned by `app:create-user`.

---

## Available console commands

| Command                    | Description                                                                                       |
|----------------------------|---------------------------------------------------------------------------------------------------|
| `app:create-user`          | Creates a user and returns an API token. Use this token when testing endpoints (e.g. in Postman). |
| `app:process-transactions` | Settles recorded transfers: completes pending ones and asks the owner about those flagged by the anti-fraud check. Run it with `docker exec -it` so the questions can be answered. |
| `app:company-wallet`       | Displays the company wallets and shows how much the company has earned.                           |

**How to run a console command:**

```bash
docker exec -it php-fpm php bin/console <command-name>
```

**Example:**

```bash
docker exec -it php-fpm php bin/console app:create-user
```
