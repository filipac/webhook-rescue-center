<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'webhook_event')]
#[ORM\Index(name: 'idx_customer_created', columns: ['customer_id', 'created_at'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 255, unique: true)]
        private string $eventId,
        #[ORM\Column(length: 255)]
        private string $customerId,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $priority,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::JSON_OBJECT, nullable: true)]
        private mixed $payload,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $receivedAt = new \DateTimeImmutable(),
    ) {
    }

    /** @param array{event_id: string, customer_id: string, priority: int, created_at: string, payload: mixed} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['event_id'],
            $data['customer_id'],
            $data['priority'],
            (new \DateTimeImmutable($data['created_at']))->setTimezone(new \DateTimeZone('UTC')),
            $data['payload'],
        );
    }

    public function getId(): ?int { return $this->id; }
    public function getEventId(): string { return $this->eventId; }
    public function getCustomerId(): string { return $this->customerId; }
    public function getPriority(): int { return $this->priority; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPayload(): mixed { return $this->payload; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'customer_id' => $this->customerId,
            'priority' => $this->priority,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
            'received_at' => $this->receivedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
