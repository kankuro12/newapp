<?php

namespace App\Service;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingService
{
    /** Attempts that may still turn into a charge and therefore block another checkout. */
    public const OPEN = ['created', 'initiating', 'pending', 'unknown'];

    /** Closed by the owner or by age, but still verified for late provider completion. */
    public const WATCHED = ['abandoned', 'expired'];

    private const WATCH_DAYS = 7;

    public function account(int $actor, int $account, array $roles = ['owner']): object
    {
        abort_if(DB::table('users')->where('id', $actor)->whereNotNull('disabled_at')->exists(), 403);
        $membership = DB::table('billing_account_user')->where('billing_account_id', $account)->where('user_id', $actor)->where('active', true)->first();
        abort_unless($membership && in_array($membership->role, $roles, true), 404);

        return DB::table('billing_accounts')->where('id', $account)->first() ?? abort(404);
    }

    public function createAccount(int $actor, string $name): object
    {
        abort_unless(DB::table('users')->where('id', $actor)->whereNull('disabled_at')->exists(), 403);

        return DB::transaction(function () use ($actor, $name) {
            $id = DB::table('billing_accounts')->insertGetId(['name' => $name, 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('billing_account_user')->insert(['billing_account_id' => $id, 'user_id' => $actor, 'role' => 'owner', 'active' => true]);

            $trialPackage = DB::table('billing_packages')->where('active', true)->where('trial_days', '>', 0)->orderBy('id')->value('id');
            if ($trialPackage && ! $this->ownerTrialUsed($actor, $id)) {
                $this->startTrial($actor, $id, (int) $trialPackage);
            }

            return $this->account($actor, $id);
        }, 3);
    }

    public function defaultAccount(int $actor, string $name): object
    {
        return DB::transaction(function () use ($actor, $name) {
            $user = DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            abort_unless($user && ! $user->disabled_at, 403);
            $id = DB::table('billing_account_user')->where('user_id', $actor)->where('active', true)->where('role', 'owner')->orderBy('billing_account_id')->value('billing_account_id');

            return $id ? $this->account($actor, $id) : $this->createAccount($actor, $name);
        }, 3);
    }

    public function accounts(int $actor): array
    {
        abort_unless(DB::table('users')->where('id', $actor)->whereNull('disabled_at')->exists(), 403);

        return DB::table('billing_account_user')->join('billing_accounts', 'billing_accounts.id', '=', 'billing_account_user.billing_account_id')->where('user_id', $actor)->where('active', true)->orderBy('billing_accounts.id')->get(['billing_accounts.*', 'billing_account_user.role'])->all();
    }

    public function snapshot(int $package): array
    {
        $row = DB::table('billing_packages')->where('id', $package)->where('active', true)->first() ?? abort(404);

        return ['id' => (string) $row->id, 'name' => $row->name, 'version' => (int) $row->version, 'duration_days' => (int) $row->duration_days,
            'trial_days' => (int) $row->trial_days, 'max_businesses' => (int) $row->max_businesses, 'products' => json_decode($row->products, true, 512, JSON_THROW_ON_ERROR),
            'prices' => DB::table('billing_package_prices')->where('package_id', $package)->get()->map(fn ($p) => ['currency' => $p->currency, 'amount_minor' => (string) $p->amount_minor])->all()];
    }

    public function startTrial(int $actor, int $account, int $package): object
    {
        return DB::transaction(function () use ($actor, $account, $package) {
            DB::table('billing_accounts')->where('id', $account)->lockForUpdate()->first() ?? abort(404);
            $row = $this->account($actor, $account);
            abort_if($row->status === 'suspended', 403);
            abort_if($row->trial_used_at || $this->ownerTrialUsed($actor, $account), 409, 'Trial already used.');
            DB::table('billing_packages')->where('id', $package)->lockForUpdate()->first() ?? abort(404);
            $snapshot = $this->snapshot($package);
            abort_unless($snapshot['trial_days'] > 0, 422, 'Package has no trial.');
            $start = now();
            $end = $start->copy()->addDays($snapshot['trial_days']);
            $id = DB::table('billing_subscriptions')->insertGetId(['billing_account_id' => $account, 'package_id' => $package,
                'package_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'status' => 'trial', 'start_at' => $start, 'end_at' => $end, 'applied_at' => $start, 'created_at' => $start, 'updated_at' => $start]);
            DB::table('billing_accounts')->where('id', $account)->update(['trial_used_at' => $start, 'version' => DB::raw('version + 1'), 'updated_at' => $start]);
            DB::table('billing_audit_logs')->insert(['actor_id' => $actor, 'actor_guard' => 'tenant', 'action' => 'trial.started', 'subject_id' => $account, 'metadata' => json_encode(['subscription_id' => (string) $id]), 'created_at' => $start]);

            return DB::table('billing_subscriptions')->where('id', $id)->first();
        }, 3);
    }

    /**
     * One automatic or requested trial per owner, so opening extra billing accounts cannot chain trials.
     */
    private function ownerTrialUsed(int $actor, int $exceptAccount): bool
    {
        return DB::table('billing_account_user')->join('billing_accounts', 'billing_accounts.id', '=', 'billing_account_user.billing_account_id')
            ->where('billing_account_user.user_id', $actor)->where('billing_account_user.role', 'owner')
            ->where('billing_accounts.id', '!=', $exceptAccount)->whereNotNull('billing_accounts.trial_used_at')->exists();
    }

    /** Package product keys: one per business type, with general books sold as bookkeeping. */
    public static function products(): array
    {
        return ['bookkeeping', ...array_values(array_diff(PosService::PROFILES, ['general']))];
    }

    public function productFor(?string $businessType): string
    {
        return ! $businessType || $businessType === 'general' ? 'bookkeeping' : $businessType;
    }

    public function entitlements(int $account): array
    {
        $row = DB::table('billing_accounts')->where('id', $account)->first() ?? abort(404);
        $subscriptions = DB::table('billing_subscriptions')->where('billing_account_id', $account);
        // Terms run back to back, so the current one is the earliest-starting term not yet ended;
        // a renewal bought early is stacked after it and its terms apply only once it starts.
        $live = (clone $subscriptions)->whereIn('status', ['trial', 'active'])->whereNull('revoked_at')->where('end_at', '>', now());
        $subscription = (clone $live)->orderBy('start_at')->orderBy('id')->first()
            ?? (clone $subscriptions)->where('start_at', '<=', now())->orderByDesc('id')->first()
            ?? (clone $subscriptions)->orderByDesc('id')->first();
        if (! $subscription) {
            return ['status' => $row->status === 'suspended' ? 'suspended' : ($row->legacy_access ? 'legacy' : 'expired'), 'end_at' => null, 'products' => self::products(), 'max_businesses' => null];
        }
        $snapshot = json_decode($subscription->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $current = in_array($subscription->status, ['trial', 'active'], true) && ! $subscription->revoked_at && now()->lt($subscription->end_at);
        $status = $row->status === 'suspended' ? 'suspended' : ($current ? $subscription->status : 'expired');
        $next = $current ? (clone $live)->where('id', '!=', $subscription->id)->where('start_at', '>=', $subscription->start_at)->orderBy('start_at')->orderBy('id')->first() : null;

        return ['status' => $status, 'end_at' => $subscription->end_at, 'products' => $snapshot['products'], 'max_businesses' => $snapshot['max_businesses'], 'subscription_id' => (string) $subscription->id, 'package' => $snapshot,
            'next' => $next ? ['subscription_id' => (string) $next->id, 'start_at' => $next->start_at, 'end_at' => $next->end_at, 'package' => json_decode($next->package_snapshot, true, 512, JSON_THROW_ON_ERROR)] : null];
    }

    public function businessAccess(Tenant $tenant, bool $write, string $role): void
    {
        $readOnly = fn (string $message) => abort_unless(! $write && in_array($role, ['owner', 'accountant'], true), 403, $message);
        if (! $tenant->package_enabled) {
            $readOnly('Business outside package allowance.');
        }
        if (! $tenant->billing_account_id) {
            return;
        }
        $entitlements = $this->entitlements((int) $tenant->billing_account_id);
        abort_if($entitlements['status'] === 'suspended', 403, 'Account suspended.');
        if ($entitlements['status'] === 'expired') {
            $readOnly('Package expired. Renew from account billing.');
        }
        if (! in_array($this->productFor($tenant->pos_profile), $entitlements['products'], true)) {
            // Existing records stay readable when a package no longer includes this business type.
            $readOnly('This business type is not included in the current package.');
        }
    }

    public function requireProduct(int $account, string $product): void
    {
        abort_unless(in_array($product, $this->entitlements($account)['products'], true), 403, 'Product unavailable in this package.');
    }

    public function prepareBusiness(int $actor, int $account): array
    {
        DB::table('billing_accounts')->where('id', $account)->lockForUpdate()->first() ?? abort(404);
        $this->account($actor, $account);
        $entitlements = $this->entitlements($account);
        abort_unless(in_array($entitlements['status'], ['active', 'trial'], true), 403, 'Renew package before adding a business.');
        if ($entitlements['max_businesses'] !== null) {
            abort_if(DB::table('tenants')->where('billing_account_id', $account)->where('package_enabled', true)->count() >= $entitlements['max_businesses'], 422, 'Package business limit reached.');
        }

        $count = DB::table('tenants')->where('billing_account_id', $account)->count();
        foreach (DB::table('subscription_payments')->where('billing_account_id', $account)->whereIn('status', self::OPEN)->get(['package_snapshot']) as $pending) {
            $terms = json_decode($pending->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_if($count >= $terms['max_businesses'], 409, 'Resolve pending package checkout before adding businesses.');
        }

        return $entitlements;
    }

    public function packages(): array
    {
        return DB::table('billing_packages')->where('active', true)->orderBy('id')->get()->map(fn ($p) => $this->snapshot((int) $p->id))->all();
    }

    public function paymentHistory(int $actor, int $account): array
    {
        $this->account($actor, $account);

        return DB::table('subscription_payments')->where('billing_account_id', $account)->orderByDesc('id')->limit(25)->get()->map(fn ($attempt) => $this->paymentData($attempt))->all();
    }

    public function accountBusinesses(int $actor, int $account): array
    {
        $this->account($actor, $account);

        return DB::table('tenants')->where('billing_account_id', $account)->orderBy('id')->get(['id', 'name', 'pos_profile', 'package_enabled'])->map(fn ($business) => ['id' => (string) $business->id, 'name' => $business->name, 'business_type' => $business->pos_profile, 'package_enabled' => (bool) $business->package_enabled])->all();
    }

    public function payment(int $actor, int $account, string $reference): object
    {
        $this->account($actor, $account);

        return DB::table('subscription_payments')->where('billing_account_id', $account)->where('reference', $reference)->first() ?? abort(404);
    }

    public function checkout(int $actor, int $account, array $input): array
    {
        $retained = array_map('intval', $input['retained_business_ids'] ?? []);
        sort($retained);
        $hash = hash('sha256', json_encode([(int) $input['package_id'], $input['gateway'], $input['currency'], $retained, array_key_exists('retained_business_ids', $input)], JSON_THROW_ON_ERROR));
        $result = DB::transaction(function () use ($actor, $account, $input, $hash, $retained) {
            DB::table('billing_accounts')->where('id', $account)->lockForUpdate()->first() ?? abort(404);
            $this->account($actor, $account);
            $old = DB::table('subscription_payments')->where('billing_account_id', $account)->where('mutation_uuid', $input['mutation_uuid'])->first();
            if ($old) {
                abort_unless($old->actor_id == $actor && hash_equals($old->request_hash, $hash), 409, 'Checkout retry conflicts with original.');

                return ['attempt' => $old, 'replayed' => true];
            }
            abort_if(DB::table('subscription_payments')->where('billing_account_id', $account)->whereIn('status', self::OPEN)->exists(), 409, 'Resolve previous payment before creating another checkout.');
            abort_unless(app(GatewayService::class)->ready($input['gateway']), 503, 'Payment gateway is not configured.');
            abort_unless(app(GatewayService::class)->supported($input['gateway'], $input['currency']), 422, 'Gateway unavailable for this currency.');
            DB::table('billing_packages')->where('id', $input['package_id'])->lockForUpdate()->first() ?? abort(404);
            $snapshot = $this->snapshot((int) $input['package_id']);
            if (isset($input['expected_version'])) {
                abort_unless($snapshot['version'] === (int) $input['expected_version'], 409, 'Package changed. Review terms.');
            }
            $price = collect($snapshot['prices'])->firstWhere('currency', $input['currency']);
            abort_unless($price && preg_match('/^[1-9][0-9]{0,11}$/D', $price['amount_minor']), 422, 'Package price unavailable.');
            abort_if($input['gateway'] === 'khalti' && (int) $price['amount_minor'] < 1000, 422, 'Khalti minimum payment is NPR 10.');
            $businesses = DB::table('tenants')->where('billing_account_id', $account)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $selected = array_key_exists('retained_business_ids', $input) ? $retained : $businesses;
            abort_unless(count($selected) <= $snapshot['max_businesses'] && count($selected) === count(array_unique($selected)) && ! array_diff($selected, $businesses), 422, 'Select retained businesses within package limit.');
            $id = DB::table('subscription_payments')->insertGetId(['billing_account_id' => $account, 'actor_id' => $actor, 'mutation_uuid' => $input['mutation_uuid'], 'request_hash' => $hash,
                'reference' => (string) Str::uuid(), 'gateway' => $input['gateway'], 'currency' => $input['currency'], 'amount_minor' => $price['amount_minor'],
                'package_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'retained_business_ids' => json_encode($selected), 'retained_selection' => array_key_exists('retained_business_ids', $input), 'status' => 'created', 'created_at' => now(), 'updated_at' => now()]);

            return ['attempt' => DB::table('subscription_payments')->where('id', $id)->first(), 'replayed' => false];
        }, 3);
        $attempt = $result['attempt'];
        if ($attempt->status === 'created' && DB::table('subscription_payments')->where('id', $attempt->id)->where('status', 'created')->update(['status' => 'initiating', 'updated_at' => now()])) {
            try {
                $provider = app(GatewayService::class)->initiate($attempt);
                DB::table('subscription_payments')->where('id', $attempt->id)->where('status', 'initiating')->update(['status' => 'pending', 'provider_id' => $provider['provider_id'], 'checkout_data' => json_encode($provider['checkout'], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            } catch (\Throwable $error) {
                DB::table('subscription_payments')->where('id', $attempt->id)->where('status', 'initiating')->update(['status' => 'unknown', 'updated_at' => now()]);
                report($error);
            }
        }

        return ['attempt' => $this->payment($actor, $account, $attempt->reference), 'replayed' => $result['replayed']];
    }

    private function duePayments(): Builder
    {
        return DB::table('subscription_payments')->where(fn ($query) => $query->whereIn('status', ['pending', 'unknown'])
            ->orWhere(fn ($stale) => $stale->whereIn('status', ['created', 'initiating'])->where('updated_at', '<=', now()->subMinutes(2)))
            ->orWhere(fn ($closed) => $closed->whereIn('status', self::WATCHED)->whereNotNull('provider_id')->where('created_at', '>=', now()->subDays(self::WATCH_DAYS))))
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('reconcile_until')->orWhere('reconcile_until', '<=', now()));
    }

    public function reconciliationCandidates(int $limit): array
    {
        return $this->duePayments()->orderBy('last_checked_at')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id')->all();
    }

    /**
     * Saves a provider outcome without reopening a closed attempt: a closed attempt stays closed while pending.
     */
    private function recordOutcome(object $attempt, string $status): void
    {
        $closed = in_array($attempt->status, self::WATCHED, true);
        if ($status === 'pending' && ! $closed && $attempt->created_at && Carbon::parse($attempt->created_at)->lte(now()->subDay())) {
            // Abandoned provider pages (eSewa NOT_FOUND, unapproved PayPal orders) never resolve themselves.
            $status = 'expired';
        }
        if ($status === 'pending' && $closed) {
            return;
        }
        DB::table('subscription_payments')->where('id', $attempt->id)->whereNull('subscription_id')->whereIn('status', [...self::OPEN, ...self::WATCHED])
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    public function reconcile(int $id): string
    {
        $token = (string) Str::uuid();
        if (! $this->duePayments()->where('id', $id)->update(['reconcile_token' => $token, 'reconcile_until' => now()->addMinutes(2)])) {
            return 'skipped';
        }
        $code = null;
        $result = 'checked';
        $delay = 5;
        try {
            $attempt = DB::table('subscription_payments')->where('id', $id)->first();
            if (! in_array($attempt->status, [...self::OPEN, ...self::WATCHED], true)) {
                return 'skipped';
            }
            $closed = in_array($attempt->status, self::WATCHED, true);
            $delay = $closed ? 60 : 5;
            if (! $attempt->provider_id) {
                // A missing Khalti pidx cannot be recovered by safely initiating another charge.
                $code = 'provider_identifier_missing';
                $delay = 1440;
                if (Carbon::parse($attempt->created_at)->lte(now()->subDay())) {
                    // Release the account after a day; support can still match a provider receipt by reference.
                    DB::table('subscription_payments')->where('id', $id)->whereIn('status', self::OPEN)->update(['status' => 'expired', 'updated_at' => now()]);
                }
            } elseif (! app(GatewayService::class)->ready($attempt->gateway)) {
                $code = 'provider_not_configured';
                $delay = 15;
            } else {
                $verified = app(GatewayService::class)->verify($attempt, []);
                if (($verified['status'] ?? '') === 'verified') {
                    $this->settle($id, $verified);
                } elseif (in_array($verified['status'] ?? '', ['pending', 'failed', 'refunded'], true)) {
                    $this->recordOutcome($attempt, $verified['status']);
                } else {
                    throw new \RuntimeException('Provider status unavailable.');
                }
            }
        } catch (\Throwable $error) {
            $code = $error instanceof HttpException && in_array($error->getStatusCode(), [409, 422], true) ? 'verification_rejected' : 'provider_unavailable';
            $result = 'failed';
            $delay = 15;
        } finally {
            // Token ownership prevents a late worker from releasing a newer worker's lease.
            DB::table('subscription_payments')->where('id', $id)->where('reconcile_token', $token)->update([
                'last_checked_at' => now(), 'next_check_at' => now()->addMinutes($delay), 'last_error_code' => $code,
                'reconcile_token' => null, 'reconcile_until' => null,
            ]);
        }

        return $result;
    }

    public function confirm(int $actor, int $account, string $reference, array $callback): object
    {
        $attempt = $this->payment($actor, $account, $reference);
        if (! in_array($attempt->status, [...self::OPEN, ...self::WATCHED], true)) {
            return $attempt;
        }
        $verified = app(GatewayService::class)->verify($attempt, $callback);
        if (($verified['status'] ?? '') !== 'verified') {
            if (in_array($verified['status'] ?? '', ['pending', 'failed', 'refunded'], true)) {
                $this->recordOutcome($attempt, $verified['status']);
            }

            return $this->payment($actor, $account, $reference);
        }

        return $this->settle((int) $attempt->id, $verified);
    }

    /**
     * Owner closes an unfinished checkout so a new one can start. A late provider completion still settles.
     */
    public function abandon(int $actor, int $account, string $reference): object
    {
        $attempt = $this->payment($actor, $account, $reference);
        abort_unless(in_array($attempt->status, self::OPEN, true), 409, 'Payment already resolved.');
        abort_if(in_array($attempt->status, ['created', 'initiating'], true) && Carbon::parse($attempt->updated_at)->gt(now()->subMinutes(2)), 409, 'Payment is still starting. Try again in two minutes.');
        if ($attempt->provider_id && app(GatewayService::class)->ready($attempt->gateway)) {
            try {
                $verified = app(GatewayService::class)->verify($attempt, []);
                if (($verified['status'] ?? '') === 'verified') {
                    return $this->settle((int) $attempt->id, $verified);
                }
                if (in_array($verified['status'] ?? '', ['failed', 'refunded'], true)) {
                    $this->recordOutcome($attempt, $verified['status']);
                }
            } catch (\Throwable $error) {
                // Provider unavailable: close anyway; reconciliation keeps checking for a late completion.
                report($error);
            }
        }
        DB::transaction(function () use ($actor, $attempt) {
            if (DB::table('subscription_payments')->where('id', $attempt->id)->whereIn('status', self::OPEN)->update(['status' => 'abandoned', 'next_check_at' => now()->addMinutes(15), 'updated_at' => now()])) {
                DB::table('billing_audit_logs')->insert(['actor_id' => $actor, 'actor_guard' => 'tenant', 'action' => 'payment.abandoned', 'subject_id' => $attempt->id, 'metadata' => json_encode(['gateway' => $attempt->gateway]), 'created_at' => now()]);
            }
        }, 3);

        return $this->payment($actor, $account, $reference);
    }

    public function settle(int $attemptId, array $verified): object
    {
        $candidate = DB::table('subscription_payments')->where('id', $attemptId)->first() ?? abort(404);

        return DB::transaction(function () use ($attemptId, $candidate, $verified) {
            DB::table('billing_accounts')->where('id', $candidate->billing_account_id)->lockForUpdate()->first() ?? abort(404);
            $attempt = DB::table('subscription_payments')->where('id', $attemptId)->lockForUpdate()->first() ?? abort(404);
            abort_unless(($verified['status'] ?? null) === 'verified' && ($verified['reference'] ?? null) === $attempt->reference
                && is_string($verified['amount_minor'] ?? null) && $verified['amount_minor'] === (string) $attempt->amount_minor
                && ($verified['currency'] ?? null) === $attempt->currency && is_string($verified['provider_transaction_id'] ?? null)
                && strlen($verified['provider_transaction_id']) > 0 && strlen($verified['provider_transaction_id']) <= 255, 422, 'Provider payment does not match checkout.');
            if ($attempt->status === 'verified') {
                return $attempt;
            }
            abort_if(DB::table('subscription_payments')->where('gateway', $attempt->gateway)->where('provider_transaction_id', $verified['provider_transaction_id'])->where('id', '!=', $attemptId)->exists(), 409, 'Provider transaction already used.');
            $snapshot = json_decode($attempt->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $businesses = DB::table('tenants')->where('billing_account_id', $attempt->billing_account_id)->orderByDesc('package_enabled')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $retained = $attempt->retained_selection ? array_values(array_intersect($businesses, json_decode($attempt->retained_business_ids, true, 512, JSON_THROW_ON_ERROR))) : $businesses;
            $trimmed = count($retained) > $snapshot['max_businesses'];
            // Money is already captured, so never refuse settlement: keep enabled businesses first, oldest first.
            $retained = array_slice($retained, 0, $snapshot['max_businesses']);
            $existing = DB::table('billing_subscriptions')->where('billing_account_id', $attempt->billing_account_id)->whereIn('status', ['trial', 'active'])->whereNull('revoked_at')->orderByDesc('end_at')->first();
            $start = now();
            if ($existing && $start->lt($existing->end_at)) {
                $start = Carbon::parse($existing->end_at);
            }
            $end = $start->copy()->addDays($snapshot['duration_days']);
            $subscription = DB::table('billing_subscriptions')->insertGetId(['billing_account_id' => $attempt->billing_account_id, 'package_id' => $snapshot['id'], 'package_snapshot' => $attempt->package_snapshot,
                'retained_business_ids' => $attempt->retained_selection ? json_encode($retained) : null, 'status' => 'active', 'start_at' => $start, 'end_at' => $end, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('subscription_payments')->where('id', $attemptId)->update(['status' => 'verified', 'provider_transaction_id' => $verified['provider_transaction_id'], 'subscription_id' => $subscription, 'updated_at' => now()]);
            DB::table('billing_accounts')->where('id', $attempt->billing_account_id)->update(['legacy_access' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            // Retained businesses keep working through the whole paid span; the allowance switches when the term starts.
            DB::table('tenants')->where('billing_account_id', $attempt->billing_account_id)->whereIn('id', $retained)->where('access_status', '!=', 'suspended')->update(['access_status' => 'active', 'access_until' => $end]);
            if ($start->lte(now())) {
                $this->applyTerms($subscription);
            }
            app(NotificationService::class)->enqueue('payment_receipt', (int) $attempt->actor_id, ['currency' => $attempt->currency, 'amount_minor' => (string) $attempt->amount_minor, 'reference' => $attempt->reference], ['email'], 'payment:'.$attempt->reference, (int) $attempt->billing_account_id);
            DB::table('billing_audit_logs')->insert(['actor_id' => $attempt->actor_id, 'actor_guard' => 'tenant', 'action' => 'payment.verified', 'subject_id' => $attempt->id, 'metadata' => json_encode(['subscription_id' => (string) $subscription, 'gateway' => $attempt->gateway, 'retained_trimmed' => $trimmed]), 'created_at' => now()]);

            return DB::table('subscription_payments')->where('id', $attemptId)->first();
        }, 3);
    }

    /**
     * Applies a subscription's business allowance once its term has started. Safe to repeat.
     */
    public function applyTerms(int $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId) {
            $candidate = DB::table('billing_subscriptions')->where('id', $subscriptionId)->first();
            if (! $candidate) {
                return false;
            }
            DB::table('billing_accounts')->where('id', $candidate->billing_account_id)->lockForUpdate()->first();
            $subscription = DB::table('billing_subscriptions')->where('id', $subscriptionId)->lockForUpdate()->first();
            if ($subscription->applied_at || $subscription->revoked_at || now()->lt($subscription->start_at)) {
                return false;
            }
            $tenants = DB::table('tenants')->where('billing_account_id', $subscription->billing_account_id);
            $snapshot = json_decode($subscription->package_snapshot, true, 512, JSON_THROW_ON_ERROR);
            // No explicit selection keeps every business, including ones added after payment, up to the package limit.
            $retained = $subscription->retained_business_ids !== null ? json_decode($subscription->retained_business_ids, true, 512, JSON_THROW_ON_ERROR)
                : (clone $tenants)->orderByDesc('package_enabled')->orderBy('id')->limit($snapshot['max_businesses'])->pluck('id')->all();
            (clone $tenants)->update(['package_enabled' => false, 'data_version' => DB::raw('data_version + 1')]);
            (clone $tenants)->whereIn('id', $retained)->update(['package_enabled' => true]);
            DB::table('billing_subscriptions')->where('id', $subscriptionId)->update(['applied_at' => now(), 'updated_at' => now()]);

            return true;
        }, 3);
    }

    /**
     * Scheduler entry: start stacked renewals whose term began.
     */
    public function applyDueTerms(int $limit = 100): int
    {
        $applied = 0;
        foreach (DB::table('billing_subscriptions')->whereNull('applied_at')->whereNull('revoked_at')->where('start_at', '<=', now())->orderBy('start_at')->limit($limit)->pluck('id') as $id) {
            $applied += $this->applyTerms((int) $id) ? 1 : 0;
        }

        return $applied;
    }

    /**
     * Platform refund or dispute: revoke the purchased term with an audit record. Business journals are untouched.
     */
    public function revokePayment(int $admin, int $paymentId, string $status, string $reason): object
    {
        abort_unless(in_array($status, ['refunded', 'disputed'], true), 422, 'Choose refunded or disputed.');

        return DB::transaction(function () use ($admin, $paymentId, $status, $reason) {
            $candidate = DB::table('subscription_payments')->where('id', $paymentId)->first() ?? abort(404);
            DB::table('billing_accounts')->where('id', $candidate->billing_account_id)->lockForUpdate()->first();
            $attempt = DB::table('subscription_payments')->where('id', $paymentId)->lockForUpdate()->first();
            abort_unless($attempt->status === 'verified' && $attempt->subscription_id, 409, 'Only a settled payment can be refunded or disputed.');
            DB::table('subscription_payments')->where('id', $paymentId)->update(['status' => $status, 'updated_at' => now()]);
            DB::table('billing_subscriptions')->where('id', $attempt->subscription_id)->update(['status' => $status, 'revoked_at' => now(), 'updated_at' => now()]);
            DB::table('billing_accounts')->where('id', $attempt->billing_account_id)->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('billing_audit_logs')->insert(['actor_id' => $admin, 'actor_guard' => 'superadmin', 'action' => 'payment.'.$status, 'subject_id' => $paymentId, 'metadata' => json_encode(['reason' => $reason, 'subscription_id' => (string) $attempt->subscription_id]), 'created_at' => now()]);

            return DB::table('subscription_payments')->where('id', $paymentId)->first();
        }, 3);
    }

    public function paymentData(object $attempt): array
    {
        return ['id' => (string) $attempt->id, 'reference' => $attempt->reference, 'gateway' => $attempt->gateway, 'currency' => $attempt->currency,
            'amount_minor' => (string) $attempt->amount_minor, 'status' => $attempt->status, 'checkout' => $attempt->checkout_data ? json_decode($attempt->checkout_data, true) : null,
            'subscription_id' => $attempt->subscription_id ? (string) $attempt->subscription_id : null, 'created_at' => $attempt->created_at,
            'can_abandon' => in_array($attempt->status, self::OPEN, true), 'issue' => $attempt->last_error_code ?? null];
    }
}
