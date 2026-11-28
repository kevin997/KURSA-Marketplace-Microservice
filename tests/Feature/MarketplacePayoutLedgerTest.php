<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MarketplacePayoutLedger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MarketplacePayoutLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_28_100000_create_marketplace_payout_ledger.php'))->up();
    }

    private function ledger(): MarketplacePayoutLedger
    {
        return app(MarketplacePayoutLedger::class);
    }

    private function funded(int $seller = 7, string $currency = 'XAF', string $item = 'item-1', string $receipt = 'recon-1'): void
    {
        $this->ledger()->recordVerifiedItem($seller, $currency, $item, 'capture-1', 1200, false);
        $this->ledger()->releaseReconciledItem($seller, $currency, $item, 'capture-1', $receipt, 1200);
    }

    public function test_only_reconciled_third_party_credits_are_withdrawable_once(): void
    {
        $ledger = $this->ledger();
        $ledger->recordVerifiedItem(7, 'XAF', 'item-1', 'capture-1', 1200, false);
        $ledger->recordVerifiedItem(7, 'XAF', 'item-1', 'capture-1', 1200, false);
        $ledger->recordVerifiedItem(15, 'XAF', 'item-2', 'capture-1', 450, true);
        $this->assertSame(0, $ledger->balance(7, 'XAF')['available']);
        $this->assertSame(1200, $ledger->balance(7, 'XAF')['held']);
        $ledger->releaseReconciledItem(15, 'XAF', 'item-2', 'capture-1', 'recon-1', 450);
        $ledger->releaseReconciledItem(7, 'XAF', 'item-1', 'capture-1', 'recon-1', 1200);
        $ledger->releaseReconciledItem(7, 'XAF', 'item-1', 'capture-1', 'recon-1', 1200);
        $this->assertSame(450, $ledger->balance(15, 'XAF')['first_party']);
        $this->assertSame(0, $ledger->balance(15, 'XAF')['available']);
        $this->assertSame(1200, $ledger->balance(7, 'XAF')['available']);
        $this->assertDatabaseCount('marketplace_payout_ledger_entries', 4);
    }

    public function test_approve_never_settles_and_confirmation_must_match_the_reservation(): void
    {
        $this->funded();
        $ledger = $this->ledger();
        $id = $ledger->request(7, 'XAF', 'request-1', 1000);
        $this->assertSame($id, $ledger->request(7, 'XAF', 'request-1', 1000));
        $this->declines(fn () => $ledger->request(7, 'XAF', 'request-1', 1001));
        $this->declines(fn () => $ledger->recordExternalSettlement(7, 'XAF', $id, 21, 'transfer-1', 1000));
        $ledger->approve(7, 'XAF', $id, 20);
        $this->assertSame(0, $ledger->balance(7, 'XAF')['settled']);
        $this->assertSame(1000, $ledger->balance(7, 'XAF')['reserved']);
        $this->declines(fn () => $ledger->recordExternalSettlement(7, 'XAF', $id, 21, 'transfer-1', 999));
        $ledger->recordExternalSettlement(7, 'XAF', $id, 21, 'transfer-1', 1000);
        $ledger->recordExternalSettlement(7, 'XAF', $id, 21, 'transfer-1', 1000);
        $this->assertSame(1000, $ledger->balance(7, 'XAF')['settled']);
        $this->assertSame(0, $ledger->balance(7, 'XAF')['reserved']);
        $this->assertSame(200, $ledger->balance(7, 'XAF')['available']);
        $this->assertDatabaseHas('marketplace_payout_ledger_entries', ['kind' => 'approved', 'payout_request_id' => $id, 'actor_id' => 20]);
        $this->assertDatabaseHas('marketplace_payout_ledger_entries', ['kind' => 'settled', 'payout_request_id' => $id, 'actor_id' => 21]);
        $id2 = $ledger->request(7, 'XAF', 'request-2', 200);
        $ledger->approve(7, 'XAF', $id2, 20);
        $this->declines(fn () => $ledger->recordExternalSettlement(7, 'XAF', $id2, 21, 'transfer-1', 200));
        $this->assertSame(200, $ledger->balance(7, 'XAF')['reserved']);
    }

    public function test_mismatches_do_not_create_balances_and_rejection_releases_only_once(): void
    {
        $ledger = $this->ledger();
        $this->declines(fn () => $ledger->releaseReconciledItem(7, 'XAF', 'missing', 'capture-1', 'recon-1', 1200));
        $this->funded();
        $this->declines(fn () => $ledger->recordVerifiedItem(8, 'XAF', 'item-1', 'capture-1', 1200, false));
        $this->declines(fn () => $ledger->releaseReconciledItem(7, 'XAF', 'item-1', 'capture-1', 'recon-2', 1200));
        $this->declines(fn () => $ledger->request(8, 'XAF', 'request-2', 1));
        $this->declines(fn () => $ledger->request(7, 'USD', 'request-2', 1));
        $id = $ledger->request(7, 'XAF', 'request-2', 1200);
        $this->declines(fn () => $ledger->request(7, 'XAF', 'request-3', 1));
        $this->declines(fn () => $ledger->approve(8, 'XAF', $id, 20));
        $ledger->reject(7, 'XAF', $id, 20, 'Missing reconciliation notes');
        $ledger->reject(7, 'XAF', $id, 20, 'Missing reconciliation notes');
        $this->assertSame(1200, $ledger->balance(7, 'XAF')['available']);
        $this->assertSame(0, $ledger->balance(7, 'XAF')['reserved']);
        $this->assertSame(1, DB::table('marketplace_payout_ledger_entries')->where('kind', 'rejected')->count());
    }

    public function test_dispute_preserves_existing_reservations_and_prevents_further_payouts(): void
    {
        $this->funded();
        $ledger = $this->ledger();
        $approved = $ledger->request(7, 'XAF', 'request-1', 600);
        $pending = $ledger->request(7, 'XAF', 'request-2', 400);
        $ledger->approve(7, 'XAF', $approved, 20);
        $ledger->freezeForDispute(7, 'XAF', 'case-1', 21);
        $ledger->freezeForDispute(7, 'XAF', 'case-1', 21);
        $this->declines(fn () => $ledger->request(7, 'XAF', 'request-3', 1));
        $this->declines(fn () => $ledger->approve(7, 'XAF', $pending, 20));
        $this->declines(fn () => $ledger->reject(7, 'XAF', $pending, 20, 'Check transfer status'));
        $this->declines(fn () => $ledger->recordExternalSettlement(7, 'XAF', $approved, 21, 'transfer-1', 600));
        $this->assertSame(1000, $ledger->balance(7, 'XAF')['reserved']);
        $this->assertSame(1, DB::table('marketplace_payout_ledger_entries')->where('kind', 'frozen')->count());
        $this->assertTrue($ledger->balance(7, 'XAF')['frozen']);
        $this->assertFalse($ledger->balance(7, 'USD')['frozen']);
    }

    public function test_overflow_rolls_back_credit_and_account_balances(): void
    {
        $ledger = $this->ledger();
        $ledger->recordVerifiedItem(7, 'XAF', 'item-1', 'capture-1', PHP_INT_MAX, false);
        $this->declines(fn () => $ledger->recordVerifiedItem(7, 'XAF', 'item-2', 'capture-2', 1, false));
        $this->declines(fn () => $ledger->recordVerifiedItem(7, 'XAF', 'item-2', 'capture-2', 1.9, false));
        $this->declines(fn () => $ledger->recordVerifiedItem(7, 'XAF', 'item-2', 'capture-2', '1200', false));
        $this->assertSame(PHP_INT_MAX, $ledger->balance(7, 'XAF')['held']);
        $this->assertDatabaseCount('marketplace_payout_ledger_entries', 1);
    }

    private function declines(callable $action): void
    {
        try {
            $action();
            $this->fail('The ledger unexpectedly accepted an invalid operation.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
