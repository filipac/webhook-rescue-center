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
        // 1. Deduplicate by event_id: keep the first occurrence and its original fields.
        // 2. Sort by priority DESC, created_at ASC (actual instant), then event_id ASC (lexicographically).
        // 3. Process waiting events by choosing the highest-ranked currently eligible event.
        //    After two consecutive events for one customer_id, choose the best event
        //    belonging to another customer, if any are waiting.
        //    The previous customer becomes eligible again immediately after that switch;
        //    fairness must not permanently lower its priority.
        //    If only one customer remains, process its remaining events in ranked order.
        return $events;
    }
}
