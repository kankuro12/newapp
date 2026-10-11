<?php

namespace App\Service;

use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ReportController;
use App\Models\Tenant;
use App\Models\User;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * AI-agent (MCP) access: per-business tokens and a small set of daily-action tools.
 * Agents act as the token owner, with that owner's current role and the same services the app uses.
 */
class AgentService
{
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private AccountingService $a) {}

    // ---------------------------------------------------------------- tokens

    private function member(int $actor, int $tenantId): object
    {
        abort_unless(DB::table('users')->where('id', $actor)->whereNull('disabled_at')->whereNotNull('email_verified_at')->exists(), 403);

        return DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $actor)->where('active', true)->first() ?? abort(404);
    }

    public function tokens(int $actor): array
    {
        $names = DB::table('tenants')->pluck('name', 'id');

        return PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $actor)->orderByDesc('id')->get()
            ->filter(fn ($token) => $token->can('mcp'))
            ->map(fn ($token) => ['id' => (string) $token->id, 'name' => $token->name, 'business_id' => (string) $this->tenantOf($token),
                'business_name' => $names[$this->tenantOf($token)] ?? null, 'can_write' => $token->can('write'),
                'last_used_at' => $token->last_used_at?->toIso8601String(), 'expires_at' => $token->expires_at?->toIso8601String(), 'created_at' => $token->created_at?->toIso8601String()])
            ->values()->all();
    }

    /**
     * @return array{id: string, token: string} the plain token is shown once and never stored
     */
    public function createToken(int $actor, int $tenantId, string $name, bool $write, int $days): array
    {
        $this->member($actor, $tenantId);
        abort_if(PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $actor)->count() >= 20, 422, 'Maximum 20 agent tokens. Revoke unused ones first.');
        $user = User::findOrFail($actor);
        $token = $user->createToken($name, array_values(array_filter(['mcp', 'tenant:'.$tenantId, $write ? 'write' : null])), now()->addDays($days));
        DB::table('audit_logs')->insert(['tenant_id' => $tenantId, 'actor_id' => $actor, 'actor_guard' => 'tenant', 'action' => 'agent.token.created', 'subject_type' => 'personal_access_tokens', 'subject_id' => $token->accessToken->id, 'metadata' => json_encode(['write' => $write, 'days' => $days]), 'created_at' => now()]);

        return ['id' => (string) $token->accessToken->id, 'token' => $token->plainTextToken];
    }

    public function revokeToken(int $actor, int $id): void
    {
        $token = PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $actor)->whereKey($id)->first() ?? abort(404);
        $tenantId = $this->tenantOf($token);
        $token->delete();
        DB::table('audit_logs')->insert(['tenant_id' => $tenantId, 'actor_id' => $actor, 'actor_guard' => 'tenant', 'action' => 'agent.token.revoked', 'subject_type' => 'personal_access_tokens', 'subject_id' => $id, 'metadata' => '{}', 'created_at' => now()]);
    }

    private function tenantOf(PersonalAccessToken $token): int
    {
        foreach ($token->abilities ?? [] as $ability) {
            if (preg_match('/^tenant:(\d+)$/D', $ability, $match)) {
                return (int) $match[1];
            }
        }

        return 0;
    }

    // --------------------------------------------------------------- context

    /**
     * Resolves a bearer token to its owner and business, rechecking membership and access on every call.
     *
     * @return array{token: PersonalAccessToken, user: User, tenant: Tenant, role: string}
     */
    public function authenticate(?string $bearer): array
    {
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        abort_unless($token && $token->can('mcp') && $token->tokenable instanceof User && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Agent token invalid or expired.');
        $user = $token->tokenable;
        abort_if($user->disabled_at || ! $user->email_verified_at, 401, 'Agent token owner unavailable.');
        $tenant = Tenant::find($this->tenantOf($token)) ?? abort(401, 'Agent token business unavailable.');
        $membership = $this->member((int) $user->id, (int) $tenant->id);
        $token->forceFill(['last_used_at' => now()])->save();

        return ['token' => $token, 'user' => $user, 'tenant' => $tenant, 'role' => $membership->role];
    }

    /**
     * Same access rules as the tenant route middleware; writes additionally need the token's write ability.
     */
    private function enter(array $context, bool $write): void
    {
        abort_if($write && ! $context['token']->can('write'), 403, 'This agent token is read-only. Create a token with "allow recording sales".');
        $tenant = $context['tenant'];
        app(BillingService::class)->businessAccess($tenant, $write, $context['role']);
        abort_if($tenant->access_status === 'suspended', 403, 'Business suspended.');
        $expiry = $tenant->access_status === 'trial' ? $tenant->trial_ends_at : $tenant->access_until;
        $expired = ! in_array($tenant->access_status, ['active', 'trial'], true) || ! $expiry || ! $expiry->isFuture();
        abort_if($expired && ($write || ! in_array($context['role'], ['owner', 'accountant'], true)), 403, 'Business access expired.');
        app(CurrentTenant::class)->set($tenant);
    }

    // ----------------------------------------------------------------- tools

    public function tools(): array
    {
        $line = ['type' => 'object', 'required' => ['item_id', 'qty'], 'properties' => [
            'item_id' => ['type' => 'string', 'description' => 'Item id from search_items.'],
            'qty' => ['type' => 'string', 'description' => 'Quantity as a decimal string, e.g. "2" or "1.5".'],
            'unit_price' => ['type' => 'string', 'description' => 'NPR per unit as a decimal string. Omit to use the item sale price.'],
        ]];
        $sale = ['contact_id' => ['type' => 'string', 'description' => 'Customer id from search_customers. Omit for a walk-in cash customer.'],
            'business_date_bs' => ['type' => 'string', 'description' => 'Bikram Sambat date YYYYMMDD. Omit for today.'],
            'lines' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => $line]];

        return [
            ['name' => 'business_overview', 'description' => 'Business name, type, today in BS, your role and, for managers, today\'s money and sales overview.', 'inputSchema' => ['type' => 'object', 'properties' => (object) []], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'search_items', 'description' => 'Find active products or services by name, SKU or barcode.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'search_customers', 'description' => 'Find active customers by name or phone.', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'money_accounts', 'description' => 'Cash and bank accounts that can receive a payment.', 'inputSchema' => ['type' => 'object', 'properties' => (object) []], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'low_stock', 'description' => 'Items at or below their low-stock level (owner, manager or accountant).', 'inputSchema' => ['type' => 'object', 'properties' => (object) []], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'recent_sales', 'description' => 'Latest sale bills with totals and unpaid balance.', 'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'preview_sale', 'description' => 'Calculate a sale bill exactly as the app would, without saving. Use its total_paisa as expected_total_paisa in record_sale.', 'inputSchema' => ['type' => 'object', 'required' => ['lines'], 'properties' => $sale], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'record_sale', 'description' => 'Post a sale bill reviewed with preview_sale. Needs a write-enabled token. Retrying with the same request_id never records twice.', 'inputSchema' => ['type' => 'object', 'required' => ['lines', 'expected_total_paisa', 'paid_now', 'request_id'], 'properties' => [...$sale,
                'expected_total_paisa' => ['type' => 'string', 'description' => 'total_paisa returned by preview_sale; the sale is refused if the total changed.'],
                'paid_now' => ['type' => 'string', 'description' => 'NPR received now as a decimal string; "0" for a credit sale.'],
                'money_account_id' => ['type' => 'string', 'description' => 'Account from money_accounts that received paid_now. Required when paid_now is above zero.'],
                'request_id' => ['type' => 'string', 'description' => 'A new UUID per sale, reused only when retrying the same sale.'],
            ]], 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]],
        ];
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, structuredContent?: array, isError: bool}
     */
    public function call(array $context, string $name, array $arguments): array
    {
        try {
            $data = match ($name) {
                'business_overview' => $this->overview($context),
                'search_items' => $this->items($context, (string) ($arguments['query'] ?? '')),
                'search_customers' => $this->customers($context, (string) ($arguments['query'] ?? '')),
                'money_accounts' => $this->accounts($context),
                'low_stock' => $this->lowStock($context),
                'recent_sales' => $this->recentSales($context, (int) ($arguments['limit'] ?? 10)),
                'preview_sale' => $this->previewSale($context, $arguments),
                'record_sale' => $this->recordSale($context, $arguments),
                default => throw new \InvalidArgumentException('Unknown tool: '.$name),
            };

            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]], 'structuredContent' => $data, 'isError' => false];
        } catch (ValidationException $error) {
            return $this->failure(collect($error->errors())->flatten()->implode(' '));
        } catch (HttpExceptionInterface $error) {
            return $this->failure($error->getMessage() ?: 'Request refused ('.$error->getStatusCode().').');
        } catch (\InvalidArgumentException $error) {
            return $this->failure($error->getMessage());
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    private function failure(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    private function overview(array $context): array
    {
        $this->enter($context, false);
        $tenant = $context['tenant'];
        $today = NepaliDate::today();
        $data = ['business' => $tenant->name, 'business_type' => $tenant->pos_profile ?: 'general', 'today_bs' => $today, 'your_role' => $context['role'], 'currency' => 'NPR'];
        if ($context['role'] !== 'cashier') {
            $data['today'] = BusinessController::json(app(ReportController::class)->overview($today, $today));
        }

        return $data;
    }

    private function items(array $context, string $query): array
    {
        $this->enter($context, false);
        $rows = $this->a->rows('items')->whereNull('archived_at')
            ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('sku', $query)->orWhereIn('id', $this->a->rows('item_codes')->where('code', $query)->select('item_id'))))
            ->orderBy('name')->limit(25)->get(['id', 'name', 'sku', 'kind', 'unit_label', 'sale_price_paisa', 'default_tax_category', 'default_tax_bps']);

        return ['items' => $rows->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->name, 'sku' => $item->sku, 'kind' => $item->kind, 'unit' => $item->unit_label,
            'sale_price' => Money::format((int) $item->sale_price_paisa), 'tax' => $item->default_tax_category])->all()];
    }

    private function customers(array $context, string $query): array
    {
        $this->enter($context, false);
        $rows = $this->a->rows('contacts')->where('is_customer', true)->where('is_system', false)->whereNull('archived_at')
            ->when($query !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$query.'%')->orWhere('phone', 'like', '%'.$query.'%')))
            ->orderBy('name')->limit(25)->get(['id', 'name', 'phone']);

        return ['customers' => $rows->map(fn ($contact) => ['id' => (string) $contact->id, 'name' => $contact->name, 'phone' => $contact->phone])->all()];
    }

    private function accounts(array $context): array
    {
        $this->enter($context, false);

        return ['accounts' => $this->a->rows('accounts')->where('is_money', true)->whereNull('archived_at')->orderBy('code')->get(['id', 'name', 'money_kind'])
            ->map(fn ($account) => ['id' => (string) $account->id, 'name' => $account->name, 'kind' => $account->money_kind])->all()];
    }

    private function lowStock(array $context): array
    {
        $this->enter($context, false);
        $this->a->authorize((int) $context['user']->id, ['owner', 'manager', 'accountant']);
        $rows = array_values(array_filter(app(ReportController::class)->stockRows(NepaliDate::today()), fn ($row) => $row['qty_milli'] <= $row['low_stock_qty_milli']));

        return ['low_stock' => array_map(fn ($row) => ['item_id' => (string) ($row['item_id'] ?? $row['id'] ?? ''), 'name' => $row['name'] ?? null,
            'qty' => Money::format((int) $row['qty_milli'], 3), 'low_stock_level' => Money::format((int) $row['low_stock_qty_milli'], 3)], array_slice($rows, 0, 50))];
    }

    private function recentSales(array $context, int $limit): array
    {
        $this->enter($context, false);
        $query = $this->a->rows('documents')->where('type', 'sale')->where('status', 'posted')->orderByDesc('id')->limit(max(1, min(50, $limit)));
        if ($context['role'] === 'cashier') {
            $query->where('created_by', $context['user']->id);
        }
        $payments = app(PaymentService::class);

        return ['sales' => $query->get(['id', 'number', 'business_date_bs', 'total_paisa', 'party_snapshot', 'cancellation_date_bs'])->map(function ($doc) use ($payments) {
            $balance = $payments->invoiceBalance((int) $doc->id);

            return ['id' => (string) $doc->id, 'number' => $doc->number, 'date_bs' => (int) $doc->business_date_bs, 'customer' => json_decode($doc->party_snapshot, true)['name'] ?? null,
                'total' => Money::format((int) $doc->total_paisa), 'unpaid' => Money::format((int) $balance['due_paisa']), 'cancelled' => (bool) $doc->cancellation_date_bs];
        })->all()];
    }

    /**
     * Builds the same document input the sale form sends; prices default to the item's current sale price.
     */
    private function saleInput(array $arguments): array
    {
        $lines = $arguments['lines'] ?? null;
        if (! is_array($lines) || ! $lines || count($lines) > 100) {
            throw new \InvalidArgumentException('Provide 1 to 100 sale lines.');
        }
        $input = ['type' => 'sale', 'business_date_bs' => (int) ($arguments['business_date_bs'] ?? NepaliDate::today()), 'contact_id' => isset($arguments['contact_id']) ? (int) $arguments['contact_id'] : null, 'lines' => []];
        foreach ($lines as $line) {
            $item = $this->a->requireRow('items', (int) ($line['item_id'] ?? 0));
            abort_if($item->archived_at, 422, 'Item '.$item->name.' is archived.');
            $input['lines'][] = ['item_id' => (int) $item->id, 'qty' => (string) ($line['qty'] ?? ''), 'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : Money::format((int) $item->sale_price_paisa),
                'tax_category' => $item->default_tax_category, 'tax_bps' => (int) $item->default_tax_bps];
        }

        return $input;
    }

    private function previewSale(array $context, array $arguments): array
    {
        $this->enter($context, false);
        $preview = app(DocumentService::class)->preview((int) $context['user']->id, $this->saleInput($arguments));

        return ['total_paisa' => (string) $preview['total_paisa'], 'total' => Money::format((int) $preview['total_paisa']), 'tax' => Money::format((int) ($preview['tax_paisa'] ?? 0)),
            'lines' => array_map(fn ($line) => ['item_id' => (string) $line['item_id'], 'qty' => Money::format((int) $line['qty_milli'], 3), 'line_total' => Money::format((int) $line['total_paisa'])], $preview['lines'] ?? []),
            'next_step' => 'Call record_sale with these lines, expected_total_paisa = total_paisa, paid_now and a new request_id.'];
    }

    private function recordSale(array $context, array $arguments): array
    {
        $this->enter($context, true);
        $uuid = (string) ($arguments['request_id'] ?? '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $uuid)) {
            throw new \InvalidArgumentException('request_id must be a UUID.');
        }
        $expected = (string) ($arguments['expected_total_paisa'] ?? '');
        if (! preg_match('/^\d{1,11}$/D', $expected)) {
            throw new \InvalidArgumentException('expected_total_paisa must be the integer total_paisa from preview_sale.');
        }
        $input = [...$this->saleInput($arguments), 'expected_total_paisa' => $expected, 'paid_now' => (string) ($arguments['paid_now'] ?? '0'),
            'money_account_id' => isset($arguments['money_account_id']) ? (int) $arguments['money_account_id'] : null, 'notes' => 'Recorded by AI agent: '.$context['token']->name];
        $result = app(DocumentService::class)->save((int) $context['user']->id, $input, $uuid, true);
        $doc = $this->a->requireRow('documents', $result['id']);

        return ['recorded' => true, 'replayed' => (bool) ($result['replayed'] ?? false), 'document_id' => (string) $doc->id, 'number' => $doc->number, 'total' => Money::format((int) $doc->total_paisa)];
    }
}
