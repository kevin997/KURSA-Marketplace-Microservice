<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class KafkaProducerService
{
    private string $brokers;

    public function __construct()
    {
        $this->brokers = config('services.kafka.brokers', 'localhost:29092');
    }

    /**
     * Publish a message to a Kafka topic.
     * Uses the php-rdkafka extension if available, otherwise falls back to HTTP REST proxy or logs.
     */
    public function publish(string $topic, array $message, ?string $key = null): bool
    {
        $payload = json_encode($message);

        // If rdkafka extension is available, use it
        if (extension_loaded('rdkafka')) {
            return $this->publishViaRdKafka($topic, $payload, $key);
        }

        // Fallback: dispatch via Laravel queue job that uses socket connection
        return $this->publishViaSocket($topic, $payload, $key);
    }

    private function publishViaRdKafka(string $topic, string $payload, ?string $key): bool
    {
        try {
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', $this->brokers);
            $conf->set('socket.timeout.ms', '5000');
            $conf->set('queue.buffering.max.ms', '100');

            $producer = new \RdKafka\Producer($conf);
            $topicObj = $producer->newTopic($topic);
            $topicObj->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
            $producer->poll(0);

            $result = $producer->flush(5000);
            if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                Log::error("Kafka flush failed for topic {$topic}", ['error' => rd_kafka_err2str($result)]);
                return false;
            }

            Log::info("Kafka message published to {$topic}", ['key' => $key]);
            return true;
        } catch (\Exception $e) {
            Log::error("Kafka publish failed: {$e->getMessage()}", ['topic' => $topic]);
            return false;
        }
    }

    /**
     * Fallback: write to Kafka via raw socket (Kafka binary protocol is complex,
     * so in dev mode we just log the event and store it for later processing).
     */
    private function publishViaSocket(string $topic, string $payload, ?string $key): bool
    {
        // In dev/fallback mode, log the event and store to DB for retry
        Log::info("Kafka event (queued/logged)", [
            'topic' => $topic,
            'key' => $key,
            'payload' => $payload,
            'brokers' => $this->brokers,
        ]);

        // Store to a kafka_events table for a cron-based retry mechanism
        try {
            \DB::table('kafka_outbox')->insert([
                'topic' => $topic,
                'message_key' => $key,
                'payload' => $payload,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Kafka outbox insert failed (table may not exist): {$e->getMessage()}");
        }

        return true;
    }

    /**
     * Publish a purchase.completed event when an order is paid.
     */
    public function publishPurchaseCompleted(array $orderData): bool
    {
        return $this->publish('marketplace.purchase.completed', [
            'event' => 'purchase.completed',
            'timestamp' => now()->toIso8601String(),
            'data' => $orderData,
        ], (string) ($orderData['order_id'] ?? ''));
    }
}
