<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Event> */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @param list<string> $eventIds
     * @return list<string>
     */
    public function findExistingEventIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return array_column($this->createQueryBuilder('event')
            ->select('event.eventId')
            ->where('event.eventId IN (:ids)')
            ->setParameter('ids', $eventIds)
            ->getQuery()
            ->getScalarResult(), 'eventId');
    }
}
