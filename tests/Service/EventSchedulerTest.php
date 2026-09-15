<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EventScheduler;
use PHPUnit\Framework\TestCase;

class EventSchedulerTest extends TestCase
{
    public function testEmptyBatchReturnsEmptyArray(): void
    {
        self::assertSame([], (new EventScheduler())->schedule([]));
    }

    public function testSingleEventIsReturnedUnchanged(): void
    {
        $event = $this->event('single');
        self::assertSame([$event], (new EventScheduler())->schedule([$event]));
    }

    public function testHigherPriorityComesFirst(): void
    {
        $this->assertOrder(['high', 'medium', 'low'], [
            $this->event('low', 'A', 1), $this->event('medium', 'B', 3), $this->event('high', 'C', 5),
        ]);
    }

    public function testOlderEventWinsWhenPriorityMatches(): void
    {
        $this->assertOrder(['old', 'new'], [
            $this->event('new', time: '2026-09-15T12:00:00+00:00'),
            $this->event('old', time: '2026-09-15T10:00:00+00:00'),
        ]);
    }

    public function testTimestampsAreComparedAsInstantsAcrossTimezones(): void
    {
        $this->assertOrder(['earlier', 'later'], [
            $this->event('later', time: '2026-09-15T09:00:00Z'),
            $this->event('earlier', time: '2026-09-15T10:00:00+02:00'),
        ]);
    }

    public function testEventIdBreaksCompleteTie(): void
    {
        $this->assertOrder(['evt_a', 'evt_b', 'evt_c'], [
            $this->event('evt_c'), $this->event('evt_b'), $this->event('evt_a'),
        ]);
    }

    public function testEventIdsUseLexicographicOrderEvenWhenNumeric(): void
    {
        $this->assertOrder(['10', '2'], [$this->event('2'), $this->event('10')]);
    }

    public function testEquivalentInstantsUseEventIdTieBreaker(): void
    {
        $this->assertOrder(['a', 'z'], [
            $this->event('z', time: '2026-09-15T10:00:00+02:00'),
            $this->event('a', time: '2026-09-15T08:00:00Z'),
        ]);
    }

    public function testFractionalSecondsParticipateInTimestampOrdering(): void
    {
        $this->assertOrder(['z', 'a'], [
            $this->event('a', time: '2026-09-15T10:00:00.900Z'),
            $this->event('z', time: '2026-09-15T10:00:00.100Z'),
        ]);
    }

    public function testDuplicateEventIdsAreRemoved(): void
    {
        $this->assertOrder(['a', 'b'], [$this->event('a'), $this->event('a'), $this->event('b')]);
    }

    public function testFirstDuplicateOccurrenceWins(): void
    {
        $first = $this->event('duplicate', 'A', 1);
        $first['payload'] = ['version' => 'first'];
        $duplicate = $this->event('duplicate', 'B', 5, '2020-01-01T00:00:00Z');
        $middle = $this->event('middle', 'C', 3);
        self::assertSame([$middle, $first], (new EventScheduler())->schedule([$first, $duplicate, $middle]));
    }

    public function testCustomerCannotOccupyThreeConsecutiveSlotsWhileOthersWait(): void
    {
        $this->assertOrder(['A1', 'A2', 'B1', 'A3'], [
            $this->event('A1', 'A', 5), $this->event('A2', 'A', 5),
            $this->event('A3', 'A', 5), $this->event('B1', 'B', 4),
        ]);
    }

    public function testSingleCustomerCanProcessAllEvents(): void
    {
        $this->assertOrder(['A1', 'A2', 'A3', 'A4'], [
            $this->event('A4'), $this->event('A2'), $this->event('A3'), $this->event('A1'),
        ]);
    }

    public function testSeveralCustomersAreScheduledByCurrentEligibility(): void
    {
        $this->assertOrder(['A1', 'A2', 'B1', 'A3', 'B2', 'C1'], [
            $this->event('A1', 'A', 5), $this->event('A2', 'A', 5), $this->event('A3', 'A', 5),
            $this->event('B1', 'B', 4), $this->event('B2', 'B', 4), $this->event('C1', 'C', 3),
        ]);
    }

    public function testFairnessChoosesHighestRankedAlternativeCustomer(): void
    {
        $this->assertOrder(['A1', 'A2', 'C1', 'A3', 'B1'], [
            $this->event('A1', 'A', 5), $this->event('A2', 'A', 5), $this->event('A3', 'A', 5),
            $this->event('B1', 'B', 2), $this->event('C1', 'C', 4),
        ]);
    }

    public function testFairnessAlternativeUsesTimestampThenEventId(): void
    {
        $this->assertOrder(['A1', 'A2', 'B1', 'A3', 'C1', 'D1'], [
            $this->event('A1', 'A', 5), $this->event('A2', 'A', 5), $this->event('A3', 'A', 5),
            $this->event('D1', 'D', 4, '2026-09-15T11:00:00Z'),
            $this->event('C1', 'C', 4), $this->event('B1', 'B', 4),
        ]);
    }

    public function testOriginalCustomerBecomesEligibleAgainAfterFairnessSwitch(): void
    {
        $this->assertOrder(['A1', 'A2', 'B1', 'A3', 'A4', 'B2'], [
            $this->event('A1', 'A', 5), $this->event('A2', 'A', 5),
            $this->event('A3', 'A', 5), $this->event('A4', 'A', 5),
            $this->event('B1', 'B', 4), $this->event('B2', 'B', 4),
        ]);
    }

    public function testCustomerCanContinueAfterOtherCustomersAreExhausted(): void
    {
        $this->assertOrder(['A1', 'A2', 'B1', 'A3', 'A4', 'A5', 'A6'], [
            $this->event('A6', 'A', 5), $this->event('A5', 'A', 5),
            $this->event('A4', 'A', 5), $this->event('A3', 'A', 5),
            $this->event('A2', 'A', 5), $this->event('A1', 'A', 5), $this->event('B1', 'B', 1),
        ]);
    }

    public function testComplexMixedBatchHasDeterministicCompleteOrder(): void
    {
        $this->assertOrder(['A1', 'A2', 'C1', 'A3', 'A4', 'B1', 'A5', 'A6', 'B2', 'D1'], [
            $this->event('A3', 'A', 5, '2026-09-15T10:03:00Z'),
            $this->event('B2', 'B', 3),
            $this->event('A1', 'A', 5, '2026-09-15T10:01:00Z'),
            $this->event('D1', 'D', 1),
            $this->event('A6', 'A', 5, '2026-09-15T10:06:00Z'),
            $this->event('C1', 'C', 4, '2026-09-15T09:00:00Z'),
            $this->event('A2', 'A', 5, '2026-09-15T10:02:00Z'),
            $this->event('B1', 'B', 4),
            $this->event('A4', 'A', 5, '2026-09-15T10:04:00Z'),
            $this->event('A5', 'A', 5, '2026-09-15T10:05:00Z'),
            $this->event('B2', 'X', 5, '2020-01-01T00:00:00Z'),
            $this->event('A1', 'Y', 1),
        ]);
    }

    private function assertOrder(array $expectedIds, array $events): void
    {
        self::assertSame($expectedIds, array_column((new EventScheduler())->schedule($events), 'event_id'));
    }

    private function event(string $id, string $customer = 'A', int $priority = 3, string $time = '2026-09-15T10:00:00Z'): array
    {
        return ['event_id' => $id, 'customer_id' => $customer, 'priority' => $priority,
            'created_at' => $time, 'payload' => ['type' => 'payment.completed']];
    }
}
