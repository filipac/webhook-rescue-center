# Webhook Rescue Center

A small Symfony 8.1 / Doctrine application for a live PHP backend exercise.

Webhook Rescue Center receives events from third-party services. Deliveries can arrive more than once, out of order, and in bursts from individual customers. Each event has an ID, a customer, a priority from 1–5 (5 is highest), a creation timestamp, and an arbitrary JSON payload. The API can preview processing order, store events, and retrieve a customer's events.

## Setup

Requirements: PHP **8.4.1+**, Composer, and PHP's `pdo_sqlite` extension (plus standard PHPUnit extensions such as DOM, XML, and mbstring). No additional services are required.

```sh
composer setup
```

This installs Composer dependencies, runs the database migrations, and runs PHPUnit, in that order.

The migration command creates `var/app.db` automatically. There is no separate database-creation step for SQLite. Tests automatically migrate their own database at `var/test.db` and reset its events between tests; they do not change your development data.

**The scheduler is intentionally unfinished.** Some scheduler tests fail initially, so `composer setup` exits with a test failure after installation and migrations succeed. Ingestion and functional API tests should pass.

Run the API locally:

```sh
php -S localhost:8000 -t public public/index.php
```

## Practical exercise

You have two tasks. You may modify production code and tests. Please think aloud while working.

### Task 1 — Event scheduling

Implement `src/Service/EventScheduler.php`.

1. Duplicate `event_id`s must only be processed once. The **first occurrence** wins, including its original fields.
2. Higher priority comes first.
3. For equal priority, older events come first. Compare the actual timestamp, including its timezone.
4. If both are equal, sort lexicographically by `event_id`.
5. A customer cannot occupy more than **two consecutive positions** while events belonging to another customer are still waiting.

At every step, choose the highest-ranked event that is currently eligible. After a fairness switch, the previous customer is eligible again immediately. If only one customer remains, its events may all proceed. Return the original event records in their processing order.

For example, with `A1`, `A2`, `A3` at priority 5 for customer A, and `B1` at priority 4 for customer B, the order is `A1, A2, B1, A3` (assuming A1–A3 are already in timestamp order).

```sh
php bin/phpunit tests/Service/EventSchedulerTest.php
```

You are encouraged to explain your approach and complexity while working.

### Task 2 — Review event ingestion

Review `src/Service/EventIngestService.php`.

Originally webhook batches contained fewer than 10 events. Production now occasionally sends batches containing **10,000 events**. Identify potential performance issues and improve the implementation if appropriate.

```sh
php bin/phpunit tests/Service/EventIngestServiceTest.php
php bin/phpunit tests/Functional/EventControllerTest.php
```

## API

| Endpoint                                 | Behavior                                                                     |
| ---------------------------------------- | ---------------------------------------------------------------------------- |
| `POST /api/events/preview`               | Returns `{"events": [...]}` from the scheduler; persists nothing.            |
| `POST /api/events`                       | Stores events and returns the number newly accepted, e.g. `{"accepted": 4}`. |
| `GET /api/customers/{customerId}/events` | Returns `{"events": [...]}` ordered by creation time, oldest first.          |

Both POST endpoints accept `{"events": [...]}`. Empty batches are valid. Each event must contain a non-empty string `event_id` and `customer_id` (up to 255 characters), an integer `priority` from 1–5, an ISO-8601 `created_at` with an explicit timezone, and `payload` (any JSON value, including null). Invalid batches return JSON with HTTP 400.

```sh
curl -X POST http://localhost:8000/api/events/preview \
  -H 'Content-Type: application/json' \
  -d '{
    "events": [
      {
        "event_id": "evt_a1",
        "customer_id": "customer_a",
        "priority": 5,
        "created_at": "2026-09-15T10:00:00+00:00",
        "payload": {"type":"payment.created"}
      },
      {
        "event_id": "evt_a2",
        "customer_id": "customer_a",
        "priority": 5,
        "created_at": "2026-09-15T10:01:00+00:00",
        "payload": {"type":"payment.updated"}
      },
      {
        "event_id": "evt_a3",
        "customer_id": "customer_a",
        "priority": 5,
        "created_at": "2026-09-15T10:02:00+00:00",
        "payload": {"type":"payment.completed"}
      },
      {
        "event_id": "evt_b1",
        "customer_id": "customer_b",
        "priority": 4,
        "created_at": "2026-09-15T10:03:00+00:00",
        "payload": {"type":"refund.created"}
      }
    ]
  }' | jq
```

Once the scheduler is implemented, the returned IDs should be `evt_a1, evt_a2, evt_b1, evt_a3`.

To persist the same batch, change the URL to `/api/events`. Retrieve it with:

```sh
curl http://localhost:8000/api/customers/customer_a/events | jq
```
