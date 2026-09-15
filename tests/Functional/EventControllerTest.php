<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\EventRepository;
use App\Service\EventScheduler;
use App\Tests\Support\DatabaseReset;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EventControllerTest extends WebTestCase
{
    use DatabaseReset;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->resetDatabase();
    }

    public function testPreviewUsesSchedulerResultWithoutPersistingAnything(): void
    {
        $events = [$this->event('a'), $this->event('b')];
        $expected = array_reverse($events);
        $scheduler = $this->createMock(EventScheduler::class);
        $scheduler->expects(self::once())->method('schedule')->with($events)->willReturn($expected);
        self::getContainer()->set(EventScheduler::class, $scheduler);
        $this->post('/api/events/preview', ['events' => $events]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertJsonStringEqualsJsonString(json_encode(['events' => $expected], JSON_THROW_ON_ERROR), $this->client->getResponse()->getContent());
        self::assertSame(0, self::getContainer()->get(EventRepository::class)->count([]));
    }

    public function testRealPreviewAcceptsAnEmptyBatch(): void
    {
        $this->post('/api/events/preview', ['events' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame(['events' => []], $this->response());
    }

    public function testIngestReturnsAcceptedCountAndSkipsRepeatedDeliveries(): void
    {
        $batch = ['events' => [$this->event('a'), $this->event('a'), $this->event('b')]];
        $this->post('/api/events', $batch);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['accepted' => 2], $this->response());
        $this->post('/api/events', $batch);
        self::assertResponseIsSuccessful();
        self::assertSame(['accepted' => 0], $this->response());
    }

    public function testCustomerEventsAreFilteredAndSortedByActualCreatedTime(): void
    {
        $later = $this->event('later');
        $later['created_at'] = '2026-09-15T09:00:00Z';
        $earlier = $this->event('earlier');
        $earlier['created_at'] = '2026-09-15T10:00:00+02:00';
        $other = $this->event('other');
        $other['customer_id'] = 'customer_b';
        $this->post('/api/events', ['events' => [$later, $other, $earlier]]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/customers/customer_a/events');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $events = $this->response()['events'];
        self::assertSame(['earlier', 'later'], array_column($events, 'event_id'));
        self::assertSame('2026-09-15T08:00:00+00:00', $events[0]['created_at']);
        self::assertArrayHasKey('received_at', $events[0]);
    }

    public function testUnknownCustomerReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/customers/unknown/events');
        self::assertResponseIsSuccessful();
        self::assertSame(['events' => []], $this->response());
    }

    #[DataProvider('jsonPayloads')]
    public function testArbitraryJsonPayloadRoundTrips(string $payload): void
    {
        $event = $this->event('payload');
        $event['payload'] = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        $this->post('/api/events', ['events' => [$event]]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/customers/customer_a/events');
        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame($payload, json_encode($body->events[0]->payload, JSON_THROW_ON_ERROR));
    }

    public static function jsonPayloads(): iterable
    {
        foreach (['{}', '[]', '{"nested":{},"list":[1,true,null]}', '"text"', '42', 'false', 'null'] as $payload) {
            yield $payload => [$payload];
        }
    }

    #[DataProvider('invalidBodies')]
    public function testMalformedInputReturnsJson400WithoutPersisting(string $path, string $body): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertArrayHasKey('error', $this->response());
        self::assertSame(0, self::getContainer()->get(EventRepository::class)->count([]));
    }

    public static function invalidBodies(): iterable
    {
        $valid = ['event_id' => 'valid', 'customer_id' => 'customer_a', 'priority' => 3,
            'created_at' => '2026-09-15T10:00:00Z', 'payload' => null];
        $bodies = ['broken JSON' => '{', 'no envelope' => '[]', 'no events' => '{}',
            'object events' => '{"events":{}}', 'non-object event' => '{"events":[null]}'];
        foreach (['event_id', 'customer_id', 'priority', 'created_at', 'payload'] as $field) {
            $event = $valid;
            unset($event[$field]);
            $bodies['missing '.$field] = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);
        }
        foreach ([['event_id', 123], ['event_id', ''], ['customer_id', null], ['customer_id', []],
            ['priority', 0], ['priority', 6], ['priority', '3'], ['priority', 3.5], ['priority', null],
            ['created_at', 'yesterday'], ['created_at', '2026-02-30T10:00:00Z'],
            ['created_at', '2026-09-15T25:00:00Z'], ['created_at', '2026-09-15T10:00:00'],
            ['created_at', '2026-09-15T10:00:00+99:99'], ['created_at', []]] as $index => [$field, $value]) {
            $event = $valid;
            $event[$field] = $value;
            // A valid event before the invalid one must not be partially persisted.
            $bodies['invalid '.$field.' '.$index] = json_encode(['events' => [$valid, $event]], JSON_THROW_ON_ERROR);
        }
        foreach (['/api/events', '/api/events/preview'] as $path) {
            foreach ($bodies as $name => $body) {
                yield $path.' '.$name => [$path, $body];
            }
        }
    }

    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function response(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function event(string $id): array
    {
        return ['event_id' => $id, 'customer_id' => 'customer_a', 'priority' => 4,
            'created_at' => '2026-09-15T10:00:00Z', 'payload' => (object) ['type' => 'payment.completed']];
    }
}
