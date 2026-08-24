<?php

declare(strict_types=1);

namespace Vernesoft\Resources\Relay;

use Vernesoft\Core\HttpClient;
use Vernesoft\Resources\Relay\Types\ListMessagesResponse;
use Vernesoft\Resources\Relay\Types\Message;

class MessagesResource
{
    public function __construct(private HttpClient $httpClient) {}

    /**
     * Publish an event to every endpoint subscribed to its event type.
     *
     * Retried once automatically on 429, respecting `Retry-After`.
     *
     * @param string        $eventType      Dot-notated event name, e.g. `user.created`.
     * @param array         $payload        Arbitrary JSON payload delivered to subscribers.
     * @param string|null   $idempotencyKey Deduplicates within a 24-hour window. Sending
     *                                      the same key twice does not create a second
     *                                      event and does not fail — the second call
     *                                      returns the *originally* accepted message,
     *                                      same id and same timestamp. So retrying a
     *                                      request whose response you never saw needs no
     *                                      special handling.
     * @param string[]|null $channels       Restrict delivery to endpoints listening on
     *                                      these channels.
     *
     * @throws \Vernesoft\Core\Errors\VerneApiException on 4xx/5xx.
     */
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

    /**
     * List events sent by this tenant, newest first.
     *
     * @param int         $limit     Items per page. Values above 100 are clamped to 100
     *                               rather than rejected.
     * @param string|null $cursor    A previous response's `nextCursor`. That is `null` on
     *                               the last page, so paginate until `hasMore` is false
     *                               rather than until `data` comes back empty.
     * @param string|null $eventType Filter by event type.
     *
     * @throws \Vernesoft\Core\Errors\VerneApiException on 4xx/5xx.
     */
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
