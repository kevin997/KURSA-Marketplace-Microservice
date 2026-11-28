<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;

class MarketplacePilotConfigurationTest extends TestCase
{
    public function test_explicit_deployment_seller_and_whole_xaf_prices_are_loaded(): void
    {
        $config = $this->configuration('42', '{"17":600,"24":1250}');

        $this->assertSame(42, $config['bootcamps_seller_user_id']);
        $this->assertSame([17 => 600, 24 => 1250], $config['xaf_prices']);
    }

    public function test_bad_price_mapping_fails_closed_instead_of_coercing(): void
    {
        foreach (['invalid json', '[600]', '{"17":"600"}', '{"017":600}', '{"0":600}', '{"17":600.5}', '{"17":0}', '{"17":10000001}', '{"17":600,"99":false}'] as $bad) {
            $this->assertSame([], $this->configuration('42', $bad)['xaf_prices'], $bad);
        }
        $this->assertNull($this->configuration('0', '{}')['bootcamps_seller_user_id']);
        $this->assertNull($this->configuration(null, null)['bootcamps_seller_user_id']);
        $this->assertSame([], $this->configuration(null, null)['xaf_prices']);
    }

    private function configuration(?string $seller, ?string $prices): array
    {
        $repo = Env::getRepository();
        $sellerKey = 'MARKETPLACE_STAGING_BOOTCAMPS_SELLER_USER_ID';
        $pricesKey = 'MARKETPLACE_STAGING_XAF_PRICES';
        $original = [$repo->get($sellerKey), $repo->get($pricesKey)];
        try {
            $seller === null ? $repo->clear($sellerKey) : $repo->set($sellerKey, $seller);
            $prices === null ? $repo->clear($pricesKey) : $repo->set($pricesKey, $prices);

            return require dirname(__DIR__, 2).'/config/marketplace-checkout.php';
        } finally {
            foreach ([$sellerKey, $pricesKey] as $index => $key) {
                $original[$index] === null ? $repo->clear($key) : $repo->set($key, $original[$index]);
            }
        }
    }
}
