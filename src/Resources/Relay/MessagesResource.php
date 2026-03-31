<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Relay;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Relay\Types\ListMessagesResponse;
use Vernesoft\Resources\Relay\Types\Message;

class MessagesResource
{
    public function __construct(private HttpClient $httpClient) {}

    public function send(
        string $eventType,
        array $payload,
        ?string $idempotencyKey = null,
        ?array $channels = null,
    ): Message {
        $body = [
            'event_type' => $eventType,
            'payload' => $payload,
        ];

        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }

        if ($channels !== null) {
            $body['channels'] = $channels;
        }

        $data = $this->httpClient->post('/v1/relay/messages', $body);

        return Message::fromArray($data);
    }

    public function list(
        int $limit = 20,
        ?string $cursor = null,
        ?string $eventType = null,
    ): ListMessagesResponse {
        $query = ['limit' => $limit];

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        if ($eventType !== null) {
            $query['event_type'] = $eventType;
        }

        $data = $this->httpClient->get('/v1/relay/messages', $query);

        return ListMessagesResponse::fromArray($data);
    }
}
