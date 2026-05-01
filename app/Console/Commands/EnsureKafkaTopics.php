<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RdKafka\Admin\Client;
use RdKafka\Admin\NewTopic;
use RdKafka\Conf;

class EnsureKafkaTopics extends Command
{
    protected $signature = 'kafka:ensure-topics';

    protected $description = 'Ensure required Kafka topics exist';

    private array $requiredTopics = [
        'marketplace.purchase.completed',
        'template.publish_to_marketplace',
    ];

    public function handle(): int
    {
        $brokers = env('KAFKA_BROKERS', 'localhost:9092');
        $this->info("Connecting to Kafka: {$brokers}");

        try {
            $conf = new Conf;
            $conf->set('metadata.broker.list', $brokers);
            $conf->set('api.version.request', 'true');

            $client = new Client($conf);

            // Check which topics exist
            $metadata = $client->getMetadata(true, null, 5000);
            $existingTopics = [];

            foreach ($metadata->getTopics() as $topic) {
                $existingTopics[] = $topic->getTopic();
            }

            $this->info('Existing topics: '.implode(', ', $existingTopics));

            // Create missing topics
            $topicsToCreate = [];
            foreach ($this->requiredTopics as $topic) {
                if (! in_array($topic, $existingTopics, true)) {
                    $topicsToCreate[] = $topic;
                }
            }

            if (empty($topicsToCreate)) {
                $this->info('All required topics exist.');

                return self::SUCCESS;
            }

            $this->warn('Creating missing topics: '.implode(', ', $topicsToCreate));

            foreach ($topicsToCreate as $topicName) {
                $newTopic = new NewTopic($topicName, 3, 1);
                $client->createTopics([$newTopic]);
                $this->info("Created topic: {$topicName}");
            }

            // Give Kafka a moment to propagate metadata
            sleep(1);

            $this->info('Topics created successfully.');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to ensure topics: {$e->getMessage()}");
            Log::error('Kafka ensure topics failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }
}
