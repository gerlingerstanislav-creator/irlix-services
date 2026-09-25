<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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

fwrite(STDOUT, "Employees outbox publisher started\n");

while (true) {
    $connection = null;
    $channel = null;

    try {
        $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost, false, 'AMQPLAIN', null, 'en_US', 3.0, 5.0);
        $channel = $connection->channel();
        $channel->exchange_declare($exchange, 'topic', false, true, false);

        while (true) {
            $events = DB::table('outbox_events')
                ->whereIn('status', ['pending', 'retry'])
                ->where('available_at', '<=', now())
                ->orderBy('occurred_at')
                ->limit(50)
                ->get();

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

                    DB::table('outbox_events')->where('id', $event->id)->update([
                        'status' => 'published',
                        'attempts' => (int) $event->attempts + 1,
                        'published_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]);
                } catch (Throwable $e) {
                    $attempts = (int) $event->attempts + 1;
                    $deadLettered = $attempts >= $maxAttempts;
                    $delaySeconds = min(3600, 5 * (2 ** min($attempts, 9)));
                    DB::table('outbox_events')->where('id', $event->id)->update([
                        'status' => $deadLettered ? 'dead_lettered' : 'retry',
                        'attempts' => $attempts,
                        'available_at' => now()->addSeconds($delaySeconds),
                        'last_error' => mb_substr($e->getMessage(), 0, 4000),
                        'updated_at' => now(),
                    ]);
                    fwrite(STDERR, "Outbox {$event->id} publish failed: {$e->getMessage()}\n");
                    if (!$channel || !$channel->is_open()) throw $e;
                }
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "RabbitMQ publisher connection failed: {$e->getMessage()}\n");
        try { if ($channel && $channel->is_open()) $channel->close(); } catch (Throwable) {}
        try { if ($connection && $connection->isConnected()) $connection->close(); } catch (Throwable) {}
        sleep(10);
    }
}
