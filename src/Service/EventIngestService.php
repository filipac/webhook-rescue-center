<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;

class EventIngestService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param list<array{event_id: string, customer_id: string, priority: int, created_at: string, payload: mixed}> $events */
    public function ingest(array $events): int
    {
        $seen = [];
        $accepted = 0;

        // This implementation was sufficient when webhook batches were small.
        foreach ($events as $eventData) {
            $eventId = $eventData['event_id'];
            if (isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;

            $existing = $this->eventRepository->findOneBy(['eventId' => $eventId]);
            if ($existing !== null) {
                continue;
            }

            $this->entityManager->persist(Event::fromArray($eventData));
            ++$accepted;
        }

        $this->entityManager->flush();

        return $accepted;
    }
}
