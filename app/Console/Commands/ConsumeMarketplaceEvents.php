<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

class ConsumeMarketplaceEvents extends Command
{
    protected $signature = 'kafka:consume
        {--topic=template.publish_to_marketplace : Kafka topic to consume}
        {--group=csl-marketplace-consumer : Consumer group ID}
        {--timeout=30000 : Poll timeout in milliseconds}';

    protected $description = 'Consume Kafka events for marketplace product creation from Certification API';

    public function handle(): int
    {
        $topic = $this->option('topic');
        $groupId = $this->option('group');
        $timeout = (int) $this->option('timeout');

        $brokers = config('services.kafka.brokers', 'localhost:29092');

        $this->info("Connecting to Kafka brokers: {$brokers}");
        $this->info("Consuming topic: {$topic} (group: {$groupId})");

        if (extension_loaded('rdkafka')) {
            return $this->consumeWithRdKafka($brokers, $topic, $groupId, $timeout);
        }

        $this->warn('php-rdkafka not available. Falling back to outbox polling.');

        return $this->consumeFromOutbox($topic, $timeout);
    }

    private function consumeWithRdKafka(string $brokers, string $topic, string $groupId, int $timeout): int
    {
        $conf = new Conf;
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('group.id', $groupId);
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'true');

        $consumer = new KafkaConsumer($conf);
        $consumer->subscribe([$topic]);

        $this->info('Listening for messages... (Ctrl+C to stop)');

        while (true) {
            $message = $consumer->consume($timeout);

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processMessage($message->payload);
                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    break;
                default:
                    $this->error("Kafka error: {$message->errstr()}");
                    $level = $this->isRecoverableKafkaError($message->err) ? 'warning' : 'error';
                    Log::log($level, 'Marketplace Kafka consumer error', [
                        'error' => $message->errstr(),
                        'code' => $message->err,
                        'topic' => $topic,
                        'group' => $groupId,
                    ]);

                    if ($this->isRecoverableKafkaError($message->err)) {
                        $this->warn('Recoverable Kafka error; keeping consumer alive and retrying.');
                        sleep(5);
                    }
                    break;
            }
        }

        return self::SUCCESS;
    }

    private function isRecoverableKafkaError(int $errorCode): bool
    {
        return in_array($errorCode, [
            RD_KAFKA_RESP_ERR__TRANSPORT,
            RD_KAFKA_RESP_ERR__ALL_BROKERS_DOWN,
            RD_KAFKA_RESP_ERR__UNKNOWN_TOPIC,
            RD_KAFKA_RESP_ERR_UNKNOWN_TOPIC_OR_PART,
            RD_KAFKA_RESP_ERR_LEADER_NOT_AVAILABLE,
            RD_KAFKA_RESP_ERR_NOT_LEADER_FOR_PARTITION,
        ], true);
    }

    /**
     * Fallback: poll the Certification API's kafka_outbox table directly (dev only).
     */
    private function consumeFromOutbox(string $topic, int $timeout): int
    {
        $this->info('Polling outbox for messages...');

        while (true) {
            try {
                $events = DB::connection('certification')
                    ->table('kafka_outbox')
                    ->where('topic', $topic)
                    ->where('status', 'pending')
                    ->orderBy('created_at')
                    ->limit(10)
                    ->get();

                foreach ($events as $event) {
                    $this->processMessage($event->payload);

                    DB::connection('certification')
                        ->table('kafka_outbox')
                        ->where('id', $event->id)
                        ->update(['status' => 'consumed', 'consumed_at' => now()]);
                }
            } catch (\Exception $e) {
                Log::debug('Marketplace outbox polling: certification DB not reachable', ['error' => $e->getMessage()]);
            }

            sleep(max(1, $timeout / 1000));
        }

        return self::SUCCESS;
    }

    private function processMessage(string $payload): void
    {
        $this->info('Received: '.substr($payload, 0, 200));

        try {
            $data = json_decode($payload, true);

            if (! $data || ! isset($data['event'])) {
                $this->warn('Invalid message format, skipping.');

                return;
            }

            match ($data['event']) {
                'template.publish_to_marketplace' => $this->handleTemplatePublish($data['data'] ?? []),
                default => $this->info("Unhandled event: {$data['event']}"),
            };
        } catch (\Exception $e) {
            $this->error("Failed to process: {$e->getMessage()}");
            Log::error('Marketplace Kafka processing failed', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleTemplatePublish(array $data): void
    {
        $sellerUserId = $data['seller_user_id'] ?? null;
        $templateId = $data['template_id'] ?? null;

        if (! $sellerUserId || ! $templateId) {
            $this->warn('Missing seller_user_id or template_id.');

            return;
        }

        // 1. Create or find seller — auto-verified if they're a teacher
        $seller = Seller::firstOrCreate(
            ['user_id' => $sellerUserId],
            [
                'company_name' => $data['seller_company'] ?? $data['seller_name'] ?? 'Unknown',
                'is_verified' => ! empty($data['seller_is_teacher']),
                'verified_at' => ! empty($data['seller_is_teacher']) ? now() : null,
            ]
        );

        // If seller already exists but wasn't verified and is now a teacher, verify them
        if (! $seller->is_verified && ! empty($data['seller_is_teacher'])) {
            $seller->update([
                'is_verified' => true,
                'verified_at' => now(),
            ]);
            $this->info("Seller #{$seller->id} auto-verified (teacher)");
        }

        // 2. Check if product already exists for this template
        $existingProduct = Product::where('template_id', $templateId)
            ->where('seller_id', $seller->id)
            ->first();

        if ($existingProduct) {
            // Update existing listing
            $existingProduct->update([
                'name' => $data['marketplace_name'] ?? $data['template_title'],
                'description' => $data['marketplace_description'] ?? $data['template_description'],
                'price' => $data['price'] ?? $existingProduct->price,
                'thumbnail_url' => $data['thumbnail_url'] ?? $existingProduct->thumbnail_url,
                'is_published' => true,
            ]);
            $this->info("Updated product #{$existingProduct->id} for template #{$templateId}");

            return;
        }

        // 3. Create new product listing
        $product = Product::create([
            'seller_id' => $seller->id,
            'name' => $data['marketplace_name'] ?? $data['template_title'],
            'description' => $data['marketplace_description'] ?? $data['template_description'],
            'price' => $data['price'] ?? 0,
            'template_id' => $templateId,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'is_published' => true,
        ]);

        // 4. Attach category if provided
        $categorySlug = $data['category'] ?? 'general';
        $category = Category::firstOrCreate(
            ['slug' => Str::slug($categorySlug)],
            ['name' => ucfirst($categorySlug)]
        );
        $product->categories()->sync([$category->id]);

        Log::info('Marketplace product created from Kafka', [
            'product_id' => $product->id,
            'template_id' => $templateId,
            'seller_id' => $seller->id,
        ]);

        $this->info("Created product #{$product->id} for template #{$templateId} by seller #{$seller->id}");
    }
}
