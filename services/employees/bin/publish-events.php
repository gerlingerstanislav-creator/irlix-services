<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use App\Services\OutboxDeliveryService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$host = (string) env('RABBITMQ_HOST', 'rabbitmq');
$port = (int) env('RABBITMQ_PORT', 5672);
$user = (string) env('RABBITMQ_USER', 'irlix');
$password = (string) env('RABBITMQ_PASSWORD', 'irlix_local');
$vhost = (string) env('RABBITMQ_VHOST', '/');
$exchange = (string) env('RABBITMQ_EVENTS_EXCHANGE', 'irlix.events');
$maxAttempts = max(1, (int) env('OUTBOX_MAX_ATTEMPTS', 10));
$idleSleep = max(1, (int) env('OUTBOX_IDLE_SLEEP', 2));
$delivery = app(OutboxDeliveryService::class);

fwrite(STDOUT, "Employees outbox publisher started\n");

while (true) {
    $connection = null;
    $channel = null;

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost, false, 'AMQPLAIN', null, 'en_US', 3.0, 5.0);
        $channel = $connection->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);
        $channel->tx_select();

        while (true) {
            $events = $delivery->pending();

            if ($events->isEmpty()) {
                sleep($idleSleep);
                continue;
            }

            foreach ($events as $event) {
                try {
                    $payload = is_string($event->payload) ? json_decode($event->payload, true) : (array) $event->payload;
                    $envelope = [
                        'event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'event_version' => (int) $event->event_version,
                        'producer' => 'employees',
                        'aggregate' => [
                            'type' => $event->aggregate_type,
                            'id' => $event->aggregate_id,
                        ],
                        'occurred_at' => $event->occurred_at,
                        'data' => $payload,
                    ];

                    $message = new AMQPMessage(
                        json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        [
                            'content_type' => 'application/json',
                            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                            'message_id' => $event->id,
                            'type' => $event->event_type,
                            'app_id' => 'employees',
                        ]
                    );
                    $channel->basic_publish($message, $exchange, $event->event_type);
                    $channel->tx_commit();

                    $delivery->markPublished($event);                } catch (Throwable $e) {
                    $delivery->markFailed($event, $e, $maxAttempts);
                    fwrite(STDERR, "Outbox {$event->id} publish failed: {$e->getMessage()}\n");
                    throw $e;
                }
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Employees event publisher loop failed: {$e->getMessage()}\n");
        try { if ($channel) $channel->close(); } catch (Throwable) {}
        try { if ($connection) $connection->close(); } catch (Throwable) {}
        sleep(10);
    }
}
