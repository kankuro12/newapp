<?php

namespace App\Service;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TenantService
{
    public function branch(int $actor, array $input, string $uuid): array
    {
        $a = app(AccountingService::class);
        $a->authorize($actor, ['owner']);

        return $a->mutate($actor, $uuid, 'pos.branch', $input, function (Tenant $parent) use ($actor, $input, $a) {
            if ($parent->parent_tenant_id) {
                $a->fail('Create branches from the main business.');
            }
            try {
                $branch = $this->create($actor, ['name' => $input['name'], 'parent_tenant_id' => $parent->id, 'pos_profile' => $input['pos_profile'], ...$parent->only(['address', 'phone', 'pan', 'default_locale', 'tax_recording_enabled', 'default_tax_bps'])]);
            } finally {
                app(CurrentTenant::class)->set($parent);
            }

            return ['table' => 'tenants', 'id' => $branch->id];
        });
    }

    public function create(int $actor, array $input): Tenant
    {
        abort_if(DB::table('users')->where('id', $actor)->whereNotNull('disabled_at')->exists(), 403);

        return DB::transaction(function () use ($actor, $input) {
            $tenant = Tenant::create([...$input, 'slug' => (Str::slug($input['name']) ?: 'business').'-'.Str::lower(Str::random(6)), 'trial_ends_at' => now()->addDays(14), 'access_status' => 'trial']);
            DB::table('tenant_user')->insert(['tenant_id' => $tenant->id, 'user_id' => $actor, 'role' => 'owner', 'active' => true]);
            app(CurrentTenant::class)->set($tenant);
            try {
                app(AccountingService::class)->seedChart($tenant->id);
                app(AccountingService::class)->audit($actor, 'business.created', 'tenants', $tenant->id);
            } finally {
                app(CurrentTenant::class)->clear();
            }

            return $tenant;
        }, 3);
    }

    public function updateMembership(int $actor, int $user, array $input): void
    {
        DB::transaction(function () use ($actor, $user, $input) {
            $a = app(AccountingService::class);
            $tenant = $a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $a->authorize($actor, ['owner']);
            $old = $a->rows('tenant_user')->where('user_id', $user)->first() ?? abort(404);
            if ($old->role === 'owner' && (! $input['active'] || $input['role'] !== 'owner') && $a->rows('tenant_user')->where('role', 'owner')->where('active', true)->count() <= 1) {
                $a->fail('Keep at least one active owner.');
            }
            $a->rows('tenant_user')->where('user_id', $user)->update($input);
            $tenant->increment('data_version');
            $a->audit($actor, 'staff.updated', 'users', $user, $input);
        }, 3);
    }

    public function invite(int $actor, array $input): int
    {
        $token = Str::random(64);
        $id = DB::transaction(function () use ($actor, $input, $token) {
            $a = app(AccountingService::class);
            $a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $a->authorize($actor, ['owner']);
            $a->rows('invitations')->where('email', $input['email'])->whereNull('accepted_at')->update(['revoked_at' => now()]);
            $id = DB::table('invitations')->insertGetId(['tenant_id' => app(CurrentTenant::class)->id(), ...$input, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'invited_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $a->audit($actor, 'staff.invited', 'invitations', $id);

            return $id;
        });
        $url = rtrim(config('app.frontend_url'), '/').'/invite/'.$token;
        Mail::raw('Join your business in Business Book: '.$url, fn ($m) => $m->to($input['email'])->subject('Business Book invitation'));

        return $id;
    }

    public function accept(int $actor, string $token): Tenant
    {
        $invitation = DB::table('invitations')->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        $tenant = Tenant::findOrFail($invitation->tenant_id);
        app(CurrentTenant::class)->set($tenant);
        try {
            return DB::transaction(function () use ($actor, $invitation, $tenant) {
                $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->first();
                $invitation = DB::table('invitations')->where('id', $invitation->id)->lockForUpdate()->first();
                $user = DB::table('users')->where('id', $actor)->first();
                $expiry = $tenant->access_status === 'trial' ? $tenant->trial_ends_at : $tenant->access_until;
                abort_unless(! $invitation->accepted_at && ! $invitation->revoked_at && now()->lt($invitation->expires_at) && strtolower($user->email) === strtolower($invitation->email) && $user->email_verified_at && ! $user->disabled_at && in_array($tenant->access_status, ['trial', 'active']) && $expiry?->isFuture(), 403, 'Invitation expired or email does not match.');
                $existing = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $actor)->first();
                if ($existing?->active && $existing->role === 'owner' && $invitation->role !== 'owner' && DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('active', true)->where('role', 'owner')->count() <= 1) {
                    app(AccountingService::class)->fail('Keep at least one active owner.');
                }
                DB::table('tenant_user')->updateOrInsert(['tenant_id' => $tenant->id, 'user_id' => $actor], ['role' => $invitation->role, 'active' => true]);
                DB::table('invitations')->where('id', $invitation->id)->update(['accepted_at' => now()]);
                app(AccountingService::class)->audit($actor, 'invitation.accepted', 'invitations', $invitation->id);

                return $tenant;
            });
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }
}
