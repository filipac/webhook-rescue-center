<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\EventIngestService;
use App\Service\EventScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class EventController extends AbstractController
{
    #[Route('/events/preview', methods: ['POST'])]
    public function preview(Request $request, ValidatorInterface $validator, EventScheduler $scheduler): JsonResponse
    {
        $events = $this->readEvents($request, $validator);

        return $events instanceof JsonResponse ? $events : $this->json(['events' => $scheduler->schedule($events)]);
    }

    #[Route('/events', methods: ['POST'])]
    public function ingest(Request $request, ValidatorInterface $validator, EventIngestService $service): JsonResponse
    {
        $events = $this->readEvents($request, $validator);

        return $events instanceof JsonResponse ? $events : $this->json(['accepted' => $service->ingest($events)]);
    }

    #[Route('/customers/{customerId}/events', methods: ['GET'])]
    public function customerEvents(string $customerId, EventRepository $repository): JsonResponse
    {
        $events = $repository->findBy(['customerId' => $customerId], ['createdAt' => 'ASC', 'eventId' => 'ASC']);

        return $this->json(['events' => array_map(static fn (Event $event): array => $event->toArray(), $events)]);
    }

    /** @return list<array<string, mixed>>|JsonResponse */
    private function readEvents(Request $request, ValidatorInterface $validator): array|JsonResponse
    {
        try {
            // Keep JSON objects intact inside arbitrary payloads (including {}).
            $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Request body must be valid JSON.'], 400);
        }

        if (!$body instanceof \stdClass || !isset($body->events) || !is_array($body->events)) {
            return $this->json(['error' => 'Request body must contain an events array.'], 400);
        }

        $constraints = new Assert\Collection(fields: [
            'event_id' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255)]),
            'customer_id' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255)]),
            'priority' => new Assert\Sequentially([new Assert\NotNull(), new Assert\Type('integer'), new Assert\Range(min: 1, max: 5)]),
            'created_at' => new Assert\Sequentially([
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Callback(static function (string $value, $context): void {
                    // Accept ISO-8601 date-times with an explicit timezone and optional microseconds.
                    $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D';
                    if (!preg_match($pattern, $value)) {
                        $context->buildViolation('Use an ISO-8601 timestamp with a timezone.')->addViolation();
                        return;
                    }
                    try {
                        new \DateTimeImmutable($value);
                        $errors = \DateTimeImmutable::getLastErrors();
                        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                            $context->buildViolation('Use a valid calendar date and time.')->addViolation();
                        }
                    } catch (\Exception) {
                        $context->buildViolation('Use a valid timestamp.')->addViolation();
                    }
                }),
            ]),
            'payload' => new Assert\Required(),
        ], allowExtraFields: true);

        $events = [];
        foreach ($body->events as $index => $event) {
            if (!$event instanceof \stdClass) {
                return $this->json(['error' => "events[$index] must be an object."], 400);
            }
            $data = get_object_vars($event);
            $violations = $validator->validate($data, $constraints);
            if (count($violations) > 0) {
                $errors = [];
                foreach ($violations as $violation) {
                    $errors[] = ['field' => "events[$index]".$violation->getPropertyPath(), 'message' => $violation->getMessage()];
                }
                return $this->json(['error' => 'Invalid event.', 'details' => $errors], 400);
            }
            $events[] = $data;
        }

        return $events;
    }
}
