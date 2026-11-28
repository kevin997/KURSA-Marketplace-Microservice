<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Internal accounting only: no provider calls and no HTTP bindings.
 *
 * Callers must authenticate the seller/platform admin and supply authoritative
 * immutable allocation, beneficiary and evidence from independent provider
 * verification/reconciliation. Never use request data or a legacy paid flag.
 * Evidence IDs are globally canonical within their own evidence kind.
 */
final class MarketplacePayoutLedger
{
    private const ACCOUNTS = 'marketplace_payout_accounts';

    private const REQUESTS = 'marketplace_payout_requests';

    private const ENTRIES = 'marketplace_payout_ledger_entries';

    private const BUCKETS = ['held', 'available', 'reserved', 'settled', 'first_party'];

    public function balance(int $seller, string $currency): array
    {
        $this->scope($seller, $currency);
        $row = DB::table(self::ACCOUNTS)->where(['seller_id' => $seller, 'currency' => $currency])->first();
        $out = [];
        foreach (self::BUCKETS as $bucket) {
            $out[$bucket] = (int) ($row?->{$bucket} ?? 0);
        }

        return $out + ['frozen' => (bool) ($row?->frozen ?? false)];
    }

    public function recordVerifiedItem(int $seller, string $currency, string $item, string $capture, mixed $minor, bool $sameBeneficiary): void
    {
        $this->positive($minor);
        $this->reference($item);
        $this->reference($capture);
        $this->locked($seller, $currency, function (stdClass $account) use ($item, $capture, $minor, $sameBeneficiary): void {
            $this->post($account, 'captured', $item, [$capture, $sameBeneficiary], $minor, null, 'held', reason: $sameBeneficiary ? 'same_beneficiary' : 'third_party');
        });
    }

    public function releaseReconciledItem(int $seller, string $currency, string $item, string $capture, string $reconciliation, mixed $minor): void
    {
        $this->positive($minor);
        foreach ([$item, $capture, $reconciliation] as $ref) {
            $this->reference($ref);
        }
        $this->locked($seller, $currency, function (stdClass $account) use ($item, $capture, $reconciliation, $minor): void {
            $posted = $this->entry('captured', $item);
            $firstParty = $posted?->reason === 'same_beneficiary';
            if (! $posted || (int) $posted->account_id !== (int) $account->id
                || (int) $posted->amount !== $minor
                || ! hash_equals($posted->evidence_hash, $this->hash([$capture, $firstParty]))) {
                throw new DomainException('No matching verified sale allocation.');
            }
            $this->assertOpen($account);
            $this->post($account, 'released', $item, [$capture, $reconciliation], $minor, 'held', $firstParty ? 'first_party' : 'available');
        });
    }

    public function request(int $seller, string $currency, string $idempotency, mixed $minor): int
    {
        $this->positive($minor);
        $this->reference($idempotency);

        return $this->locked($seller, $currency, function (stdClass $account) use ($idempotency, $minor): int {
            $key = $this->hash([$idempotency]);
            $existing = DB::table(self::REQUESTS)->where(['account_id' => $account->id, 'request_key' => $key])->first();
            if ($existing) {
                if ((int) $existing->amount !== $minor) {
                    throw new DomainException('Payout request key was reused with differing terms.');
                }

                return (int) $existing->id;
            }
            $this->assertOpen($account);
            $id = (int) DB::table(self::REQUESTS)->insertGetId([
                'account_id' => $account->id,
                'request_key' => $key,
                'amount' => $minor,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->post($account, 'requested', (string) $id, [$key], $minor, 'available', 'reserved', payout: $id);

            return $id;
        });
    }

    public function approve(int $seller, string $currency, int $id, int $admin): void
    {
        $this->review($seller, $currency, $id, $admin, 'approved', null);
    }

    public function reject(int $seller, string $currency, int $id, int $admin, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new DomainException('A bounded rejection reason is required.');
        }
        $this->review($seller, $currency, $id, $admin, 'rejected', $reason);
    }

    public function freezeForDispute(int $seller, string $currency, string $case, int $admin): void
    {
        $this->positive($admin);
        $this->reference($case);
        $this->locked($seller, $currency, function (stdClass $account) use ($case, $admin): void {
            $this->post($account, 'frozen', $case, [], 0, null, null, $admin);
            DB::table(self::ACCOUNTS)->where('id', $account->id)->update(['frozen' => true, 'updated_at' => now()]);
        });
    }

    public function recordExternalSettlement(int $seller, string $currency, int $id, int $admin, string $externalReference, mixed $minor): void
    {
        $this->positive($admin);
        $this->positive($minor);
        $this->reference($externalReference);
        $this->locked($seller, $currency, function (stdClass $account) use ($id, $admin, $externalReference, $minor): void {
            $request = $this->payout($account, $id);
            if (! in_array($request->status, ['approved', 'settled'], true) || (int) $request->amount !== $minor) {
                throw new DomainException('External settlement does not match an approved reservation.');
            }
            if ($request->status === 'settled' && ! $this->entry('settled', $externalReference)) {
                throw new DomainException('Payout was already settled with a different reference.');
            }
            if ($request->status !== 'settled') {
                $this->assertOpen($account);
            }
            $this->post($account, 'settled', $externalReference, [$id], $minor, 'reserved', 'settled', $admin, payout: $id);
            $this->status($id, 'settled');
        });
    }

    private function review(int $seller, string $currency, int $id, int $admin, string $decision, ?string $reason): void
    {
        $this->positive($admin);
        $this->locked($seller, $currency, function (stdClass $account) use ($id, $admin, $decision, $reason): void {
            $request = $this->payout($account, $id);
            if (! in_array($request->status, ['pending', $decision], true)) {
                throw new DomainException('Payout is not pending review.');
            }
            if ($request->status === 'pending') {
                $this->assertOpen($account);
            }
            $reject = $decision === 'rejected';
            $this->post($account, $decision, (string) $id, [$reason], (int) $request->amount, $reject ? 'reserved' : null, $reject ? 'available' : null, $admin, $reason, $id);
            $this->status($id, $decision);
        });
    }

    private function payout(stdClass $account, int $id): stdClass
    {
        return DB::table(self::REQUESTS)->where(['id' => $id, 'account_id' => $account->id])->first()
            ?? throw new DomainException('Payout does not exist in this seller and currency account.');
    }

    private function status(int $id, string $status): void
    {
        DB::table(self::REQUESTS)->where('id', $id)->where('status', '!=', $status)->update(['status' => $status, 'updated_at' => now()]);
    }

    private function locked(int $seller, string $currency, Closure $action): mixed
    {
        $this->scope($seller, $currency);

        return DB::transaction(function () use ($seller, $currency, $action): mixed {
            $key = ['seller_id' => $seller, 'currency' => $currency];
            // No mutable fields overwritten by concurrent account creation/retry.
            DB::table(self::ACCOUNTS)->upsert([$key + ['created_at' => now(), 'updated_at' => now()]], ['seller_id', 'currency'], ['seller_id']);
            $account = DB::table(self::ACCOUNTS)->where($key)->lockForUpdate()->firstOrFail();

            return $action($account);
        }, 3);
    }

    private function entry(string $kind, string $reference): ?stdClass
    {
        // Current locking read also sees an event committed by a concurrent transaction.
        return DB::table(self::ENTRIES)->where('event_key', $this->hash([$kind, $reference]))->lockForUpdate()->first();
    }

    private function post(stdClass $account, string $kind, string $ref, array $evidence, int $amount, ?string $from, ?string $to, ?int $actor = null, ?string $reason = null, ?int $payout = null): void
    {
        $hash = $this->hash($evidence);
        $old = $this->entry($kind, $ref);
        if ($old) {
            if ((int) $old->account_id !== (int) $account->id || (int) $old->amount !== $amount || ! hash_equals($old->evidence_hash, $hash)) {
                throw new DomainException('Ledger event was reused with differing evidence.');
            }

            return;
        }
        $changes = [];
        if ($from !== null) {
            if ((int) $account->{$from} < $amount) {
                throw new DomainException('Insufficient balance for payout.');
            }
            $changes[$from] = (int) $account->{$from} - $amount;
        }
        if ($to !== null) {
            if ((int) $account->{$to} > PHP_INT_MAX - $amount) {
                throw new DomainException('Ledger balance out of supported range.');
            }
            $changes[$to] = (int) $account->{$to} + $amount;
        }
        DB::table(self::ENTRIES)->insert([
            'account_id' => $account->id, 'event_key' => $this->hash([$kind, $ref]), 'evidence_hash' => $hash,
            'kind' => $kind, 'amount' => $amount, 'from_bucket' => $from, 'to_bucket' => $to,
            'payout_request_id' => $payout, 'actor_id' => $actor, 'reason' => $reason, 'created_at' => now(),
        ]);
        if ($changes !== []) {
            DB::table(self::ACCOUNTS)->where('id', $account->id)->update($changes + ['updated_at' => now()]);
        }
    }

    private function assertOpen(stdClass $account): void
    {
        if ($account->frozen) {
            throw new DomainException('Seller currency account is held for reconciliation.');
        }
    }

    private function scope(int $seller, string $currency): void
    {
        $this->positive($seller);
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new DomainException('A canonical three-letter currency is required.');
        }
    }

    private function positive(mixed $amount): void
    {
        if (! is_int($amount) || $amount < 1) {
            throw new DomainException('A positive integer is required.');
        }
    }

    private function reference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,159}$/D', $reference)) {
            throw new DomainException('A canonical evidence reference is required.');
        }
    }

    private function hash(array $fields): string
    {
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }
}
