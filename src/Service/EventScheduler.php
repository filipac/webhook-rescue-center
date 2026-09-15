<?php

declare(strict_types=1);

namespace App\Service;

class EventScheduler
{
    /**
     * @param list<array{event_id: string, customer_id: string, priority: int, created_at: string, payload: mixed}> $events
     * @return list<array{event_id: string, customer_id: string, priority: int, created_at: string, payload: mixed}>
     */
    public function schedule(array $events): array
    {
        // TODO: candidate implementation
        return $events;
    }
}
