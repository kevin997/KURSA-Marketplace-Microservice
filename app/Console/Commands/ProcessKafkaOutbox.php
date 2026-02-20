<?php

namespace App\Console\Commands;

use App\Services\KafkaProducerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessKafkaOutbox extends Command
{
    protected $signature = 'kafka:process-outbox
        {--limit=100 : Max messages to process per run}
        {--topic= : Only process a specific topic (optional)}';

    protected $description = 'Flush pending kafka_outbox messages to Kafka broker';

    public function handle(KafkaProducerService $producer): int
    {
        $limit = (int) $this->option('limit');
        $topicFilter = $this->option('topic');

        if (!extension_loaded('rdkafka')) {
            $this->error('php-rdkafka extension is not loaded. Cannot publish to Kafka.');
            return self::FAILURE;
        }

        $query = DB::table('kafka_outbox')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($limit);

        if ($topicFilter) {
            $query->where('topic', $topicFilter);
        }

        try {
            $messages = $query->get();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->warn('Database unavailable, skipping outbox flush: ' . $e->getMessage());
            Log::warning('kafka:process-outbox: DB connection failed, will retry', ['error' => $e->getMessage()]);
            return self::SUCCESS;
        }

        if ($messages->isEmpty()) {
            $this->info('No pending messages in outbox.');
            return self::SUCCESS;
        }

        $this->info("Processing {$messages->count()} pending message(s)...");
        $success = 0;
        $failed = 0;

        foreach ($messages as $msg) {
            $payload = is_string($msg->payload) ? json_decode($msg->payload, true) : (array) $msg->payload;
            $published = $producer->publish($msg->topic, $payload, $msg->message_key);

            if ($published) {
                DB::table('kafka_outbox')->where('id', $msg->id)->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->line(" ✓ [{$msg->topic}] id={$msg->id}");
                $success++;
            } else {
                DB::table('kafka_outbox')->where('id', $msg->id)->update([
                    'status' => 'failed',
                    'attempts' => $msg->attempts + 1,
                    'updated_at' => now(),
                ]);
                $attempt = $msg->attempts + 1;
                $this->warn(" ✗ [{$msg->topic}] id={$msg->id} — publish failed (attempt #{$attempt})");
                $failed++;
                Log::warning('kafka:process-outbox publish failed', ['id' => $msg->id, 'topic' => $msg->topic]);
            }
        }

        $this->info("Done: {$success} sent, {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
