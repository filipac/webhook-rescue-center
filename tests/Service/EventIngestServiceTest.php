<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\EventIngestService;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EventIngestServiceTest extends KernelTestCase
{
    use DatabaseReset;

    private EntityManagerInterface $manager;
    private EventIngestService $service;
    private EventRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->manager = $this->resetDatabase();
        $this->service = self::getContainer()->get(EventIngestService::class);
        $this->repository = self::getContainer()->get(EventRepository::class);
    }

    public function testNewEventIsPersistedWithAllFields(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        self::assertSame(1, $this->service->ingest([$this->event('new')]));
        $this->manager->clear();
        $saved = $this->repository->findOneBy(['eventId' => 'new']);
        self::assertInstanceOf(Event::class, $saved);
        self::assertNotNull($saved->getId());
        self::assertSame('customer_a', $saved->getCustomerId());
        self::assertSame(4, $saved->getPriority());
        self::assertSame('2026-09-15T10:00:00+00:00', $saved->getCreatedAt()->format(DATE_ATOM));
        self::assertEquals((object) ['type' => 'payment.completed'], $saved->getPayload());
        self::assertGreaterThanOrEqual($before, $saved->getReceivedAt());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $saved->getReceivedAt());
    }

    public function testExistingEventsAreSkippedWithoutBeingOverwritten(): void
    {
        $this->service->ingest([$this->event('existing')]);
        $changed = $this->event('existing');
        $changed['priority'] = 1;
        self::assertSame(0, $this->service->ingest([$changed]));
        $this->manager->clear();
        self::assertSame(1, $this->repository->count([]));
        self::assertSame(4, $this->repository->findOneBy(['eventId' => 'existing'])->getPriority());
    }

    public function testDuplicatesWithinOneBatchPersistOnlyTheFirstOccurrence(): void
    {
        $first = $this->event('duplicate');
        $later = $first;
        $later['customer_id'] = 'different';
        $later['priority'] = 5;
        self::assertSame(1, $this->service->ingest([$first, $later, $later]));
        $this->manager->clear();
        self::assertSame(1, $this->repository->count([]));
        self::assertSame('customer_a', $this->repository->findOneBy(['eventId' => 'duplicate'])->getCustomerId());
    }

    public function testMultipleDifferentEventsPersistCorrectly(): void
    {
        $this->service->ingest([$this->event('existing')]);
        self::assertSame(3, $this->service->ingest([
            $this->event('a'), $this->event('existing'), $this->event('b'), $this->event('c'), $this->event('a'),
        ]));
        self::assertSame(4, $this->repository->count([]));
    }

    public function testEmptyBatchAcceptsNothing(): void
    {
        self::assertSame(0, $this->service->ingest([]));
        self::assertSame(0, $this->repository->count([]));
    }

    public function testRepositoryCanRetrieveExistingIdsFromABatch(): void
    {
        $this->service->ingest([$this->event('a'), $this->event('b'), $this->event('c')]);
        $ids = $this->repository->findExistingEventIds(['b', 'absent', 'a', 'a']);
        sort($ids);
        self::assertSame(['a', 'b'], $ids);
        self::assertSame([], $this->repository->findExistingEventIds([]));
    }

    public function testDatabaseHasUniqueEventIdIndex(): void
    {
        $indexes = $this->manager->getConnection()->createSchemaManager()->listTableIndexes('webhook_event');
        $matching = array_filter($indexes, static fn ($index): bool => $index->isUnique() && $index->getColumns() === ['event_id']);
        self::assertCount(1, $matching);
    }

    public function testDatabaseRejectsDuplicateEventIds(): void
    {
        $this->manager->persist(Event::fromArray($this->event('same')));
        $this->manager->flush();
        $this->manager->clear();
        $this->manager->persist(Event::fromArray($this->event('same')));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->manager->flush();
    }

    private function event(string $id): array
    {
        return ['event_id' => $id, 'customer_id' => 'customer_a', 'priority' => 4,
            'created_at' => '2026-09-15T12:00:00+02:00', 'payload' => (object) ['type' => 'payment.completed']];
    }
}
