<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\BarcodeService;
use App\Service\CatalogService;
use App\Service\PartyService;
use App\Service\TenantService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterController extends Controller
{
    public function __construct(private AccountingService $a) {}

    private function fields(string $resource, bool $cashier): array
    {
        return match ($resource) {
            'contacts' => ['id', 'name', 'phone', 'email', 'address', 'pan', 'is_customer', 'is_supplier', 'is_employee', 'is_rent', 'is_system', 'sales_terms_days', 'purchase_terms_days', 'archived_at'], 'items' => $cashier ? ['id', 'name', 'sku', 'kind', 'category_id', 'unit_label', 'sale_price_paisa', 'default_tax_category', 'default_tax_bps', 'low_stock_qty_milli', 'pos_unit', 'pos_methods', 'pos_custom_units', 'service_minutes', 'archived_at'] : ['*'], 'accounts' => ['id', 'name', 'system_key', 'is_money', 'archived_at'], 'expense-categories' => ['id', 'name', 'account_id', 'archived_at'], default => abort(404)
        };
    }

    private function table(string $resource): string
    {
        return match ($resource) {
            'contacts' => 'contacts', 'items' => 'items', 'accounts' => 'accounts', 'expense-categories' => 'expense_categories', default => abort(404)
        };
    }

    public function listing(Request $r, string $tenant, string $resource): JsonResponse
    {
        $cashier = $r->attributes->get('role') === 'cashier';
        if ($cashier && ! in_array($resource, ['contacts', 'items'])) {
            abort(403);
        }
        $filter = $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'archived' => 'nullable|boolean', 'category_id' => 'nullable|integer|min:0']);
        $q = $this->a->rows($this->table($resource))->select($this->fields($resource, $cashier));
        if ($resource === 'items') {
            app(CatalogService::class)->filterCategory($q, $filter['category_id'] ?? null);
        }
        if (isset($filter['q']) && $filter['q'] !== '') {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$filter['q'].'%')->when($resource === 'items', fn ($q) => $q->orWhere('sku', $filter['q'])->orWhereIn('id', $this->a->rows('item_codes')->where('code', $filter['q'])->select('item_id'))));
        } if (empty($filter['archived'])) {
            $q->whereNull('archived_at');
        } if ($resource === 'contacts') {
            $q->where('is_system', false);
        } if ($resource === 'accounts') {
            $q->where('is_money', true);
        }
        $page = $q->orderBy('name')->paginate(25);
        $page->getCollection()->transform(fn ($row) => $this->enrich($resource, $row, $cashier));

        return response()->json(BusinessController::json($page->toArray()));
    }

    private function enrich(string $resource, object $row, bool $cashier): array
    {
        $data = (array) $row;
        if ($resource === 'items') {
            $data['aliases'] = app(BarcodeService::class)->aliases((int) $row->id);
        }
        if ($cashier && $resource === 'contacts') {
            unset($data['credit_limit_paisa'], $data['trading_version'], $data['sales_price_list_id'], $data['purchase_price_list_id']);
        }
        if ($resource === 'items') {
            $data['category_name'] = $row->category_id ? $this->a->rows('item_categories')->where('id', $row->category_id)->value('name') : null;
            if (! $cashier) {
                $data['preferred_supplier_name'] = $row->preferred_supplier_id ? $this->a->rows('contacts')->where('id', $row->preferred_supplier_id)->value('name') : null;
            }
            $pool = $this->a->rows('inventory_balances')->where('item_id', $row->id)->first();
            $data['qty_milli'] = (int) ($pool?->qty_milli ?? 0);
            if (! $cashier) {
                $data['value_paisa'] = (int) ($pool?->value_paisa ?? 0);
            }
        }
        if (! $cashier && $resource === 'contacts') {
            $ar = $this->a->balance($this->a->account('receivables'), (int) $row->id);
            $ap = -$this->a->balance($this->a->account('payables'), (int) $row->id);
            $data += ['receivable_paisa' => $ar, 'payable_paisa' => $ap];
        }
        if (! $cashier && $resource === 'accounts') {
            $data['balance_paisa'] = $this->a->balance((int) $row->id);
        }

        return $data;
    }

    public function show(Request $r, string $tenant, string $resource, int $id): JsonResponse
    {
        $cashier = $r->attributes->get('role') === 'cashier';
        if ($cashier && ! in_array($resource, ['contacts', 'items'])) {
            abort(403);
        } $row = $this->a->rows($this->table($resource))->select($this->fields($resource, $cashier))->where('id', $id)->first() ?? abort(404);

        return response()->json(['data' => BusinessController::json($this->enrich($resource, $row, $cashier))]);
    }

    public function lookup(Request $r): JsonResponse
    {
        $input = $r->validate(['q' => 'nullable|string|max:100', 'party_role' => 'nullable|in:customer,supplier,employee,rent,payable', 'contact_id' => 'nullable|integer|min:1', 'price_channel' => 'sometimes|in:sale,purchase', 'category_id' => 'nullable|integer|min:0', 'item_categories' => 'sometimes|boolean', 'price_list_id' => 'nullable|integer|min:1', 'business_date_bs' => ['sometimes', ...app(BusinessController::class)->dateRule()]]);
        $cashier = $r->attributes->get('role') === 'cashier';
        $contact = ! empty($input['contact_id']) ? $this->a->requireRow('contacts', $input['contact_id']) : null;
        $channel = $input['price_channel'] ?? 'sale';
        abort_if($cashier && $channel === 'purchase', 403);
        $data = [];
        foreach (['contacts', 'items', 'accounts', 'expense-categories'] as $resource) {
            if ($cashier && $resource === 'expense-categories') {
                $data['categories'] = [];

                continue;
            } $q = $this->a->rows($this->table($resource))->select($this->fields($resource, $cashier))->whereNull('archived_at');
            if ($resource === 'items') {
                app(CatalogService::class)->filterCategory($q, $input['category_id'] ?? null);
            }
            if ($resource === 'accounts') {
                $q->where('is_money', true);
            } if (in_array($resource, ['contacts', 'items']) && isset($input['q']) && $input['q'] !== '') {
                $q->where(fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%')->when($resource === 'items', fn ($q) => $q->orWhere('sku', $input['q'])->orWhereIn('id', $this->a->rows('item_codes')->where('code', $input['q'])->select('item_id'))));
            }
            if ($resource === 'contacts' && ! empty($input['party_role'])) {
                $role = $input['party_role'];
                if ($role === 'payable') {
                    $q->where(fn ($q) => $q->where('is_supplier', true)->orWhere('is_employee', true)->orWhere('is_rent', true));
                } else {
                    $q->where('is_'.$role, true);
                }
            }
            $data[$resource === 'expense-categories' ? 'categories' : $resource] = $q->orderBy('id')->limit($resource === 'accounts' ? 100 : 20)->get()->map(function ($row) use ($resource, $cashier, $contact, $channel, $input) {
                $data = $resource === 'items' ? $this->enrich($resource, $row, $cashier) : (array) $row;
                if ($resource === 'items' && ($contact || ! empty($input['price_list_id']))) {
                    $data['suggested_price_paisa'] = app(PartyService::class)->rate((int) ($contact?->id ?? 0), $row, $channel, 0, isset($input['price_list_id']) ? (int) $input['price_list_id'] : null, isset($input['business_date_bs']) ? NepaliDate::normalize($input['business_date_bs']) : null);
                }

                return $data;
            });
        }
        if (! $cashier) {
            $data['channels'] = ['receivables_id' => $this->a->account('receivables'), 'payables_id' => $this->a->account('payables')];
        }
        if (! empty($input['item_categories'])) {
            $data['item_categories'] = $this->a->rows('item_categories')->select('id', 'name', 'version', 'archived_at')->whereNull('archived_at')->when(! empty($input['q']), fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%'))->orderBy('name')->limit(20)->get();
        }

        return response()->json(['data' => BusinessController::json($data)]);
    }

    public function save(Request $r, string $tenant, string $resource, ?int $id = null): JsonResponse
    {
        $actor = auth('tenant')->id();
        $catalog = app(CatalogService::class);
        $this->a->authorize($actor, $resource === 'contacts' ? ['owner', 'manager', 'cashier', 'accountant'] : ($resource === 'items' ? ['owner', 'manager', 'accountant'] : ['owner']));
        $input = $r->validate(CatalogService::masterRules($resource));
        $rowId = $catalog->saveMaster($actor, $resource, $input, $id);

        return response()->json(['data' => BusinessController::json($this->enrich($resource, $this->a->requireRow($this->table($resource), $rowId), $this->a->role($actor) === 'cashier'))], $id ? 200 : 201);
    }

    public function archive(Request $r, string $tenant, string $resource, int $id, string $action): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, in_array($resource, ['accounts', 'expense-categories']) ? ['owner'] : ['owner', 'manager']);
        DB::transaction(function () use ($r, $actor, $resource, $id, $action) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $old = $this->a->requireRow($this->table($resource), $id);
            if (! empty($old->is_system) || ! empty($old->system_key)) {
                abort(403, 'System records protected.');
            } if ($resource === 'contacts' && $action === 'archive' && ! $r->boolean('dues_confirmed') && ($this->a->balance($this->a->account('receivables'), $id) || $this->a->balance($this->a->account('payables'), $id))) {
                $this->a->fail('Party has outstanding money; confirm archival.');
            } $this->a->rows($this->table($resource))->where('id', $id)->update(['archived_at' => $action === 'archive' ? now() : null]);
            $tenant->increment('data_version');
            $this->a->audit($actor, $resource.'.'.$action, $this->table($resource), $id);
        });

        return response()->json(['data' => ['id' => (string) $id]]);
    }

    public function settings(Request $r): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner']);
        if ($r->isMethod('GET')) {
            return response()->json(['data' => BusinessController::json($r->attributes->get('tenant'))]);
        }
        $input = $r->validate(['name' => 'required|string|max:150', 'address' => 'nullable|string|max:500', 'phone' => 'nullable|string|max:30', 'pan' => 'nullable|string|max:30', 'default_locale' => 'required|in:en,ne', 'tax_recording_enabled' => 'required|boolean', 'default_tax_bps' => 'required|integer|min:0|max:10000']);
        DB::transaction(function () use ($input) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), auth('tenant')->id());
            $tenant->update($input);
            $tenant->increment('data_version');
            $this->a->audit(auth('tenant')->id(), 'business.settings', 'tenants', $tenant->id);
        });

        return response()->json(['data' => BusinessController::json(Tenant::find(app(CurrentTenant::class)->id()))]);
    }

    public function staff(Request $r, TenantService $service): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, ['owner']);
        if ($r->isMethod('POST')) {
            $input = $r->validate(['email' => 'required|email|max:255', 'role' => 'required|in:owner,manager,cashier,accountant']);
            $input['email'] = strtolower($input['email']);
            $id = $service->invite($actor, $input);

            return response()->json(['data' => ['id' => (string) $id]], 201);
        }
        $staff = $this->a->rows('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')->select('users.id', 'users.name', 'users.email', 'tenant_user.role', 'tenant_user.active')->get();
        $invitations = $this->a->rows('invitations')->select('id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => ['staff' => BusinessController::json($staff), 'invitations' => BusinessController::json($invitations)]]);
    }

    public function membership(Request $r, string $tenant, int $user, TenantService $service): Response
    {
        $input = $r->validate(['role' => 'required|in:owner,manager,cashier,accountant', 'active' => 'required|boolean', 'password' => 'required|string']);
        abort_unless(Hash::check($input['password'], auth('tenant')->user()->password), 403, 'Password incorrect.');
        unset($input['password']);
        $service->updateMembership(auth('tenant')->id(), $user, $input);

        return response()->noContent();
    }

    public function revokeInvitation(string $tenant, int $id): Response
    {
        DB::transaction(function () use ($id) {
            $actor = auth('tenant')->id();
            $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $this->a->authorize($actor, ['owner']);
            $this->a->requireRow('invitations', $id);
            $this->a->rows('invitations')->where('id', $id)->update(['revoked_at' => now()]);
            $this->a->audit($actor, 'invitation.revoked', 'invitations', $id);
        });

        return response()->noContent();
    }

    public function acceptInvitation(Request $r, string $token, TenantService $service): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($service->accept(auth('tenant')->id(), $token))]);
    }

    public function audit(): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'accountant']);

        return response()->json(BusinessController::json($this->a->rows('audit_logs')->orderByDesc('id')->paginate(30)->toArray()));
    }

    private function attachmentParent(?int $document, ?int $payment): void
    {
        if (($document === null) === ($payment === null)) {
            $this->a->fail('Select exactly one parent.');
        }
        $parent = $this->a->requireRow($document ? 'documents' : 'payments', $document ?? $payment);
        if ($this->a->role(auth('tenant')->id()) === 'cashier' && (! $document || $parent->type !== 'sale' || $parent->created_by != auth('tenant')->id())) {
            abort(403);
        }
    }

    public function upload(Request $r): JsonResponse
    {
        $input = $r->validate(['document_id' => 'nullable|integer|min:1', 'payment_id' => 'nullable|integer|min:1', 'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $document = isset($input['document_id']) ? (int) $input['document_id'] : null;
        $payment = isset($input['payment_id']) ? (int) $input['payment_id'] : null;
        $this->attachmentParent($document, $payment);
        $path = null;
        try {
            $id = DB::transaction(function () use ($r, $document, $payment, &$path) {
                $this->a->lockTenant(app(CurrentTenant::class)->id(), auth('tenant')->id());
                $this->attachmentParent($document, $payment);
                $q = $this->a->rows('attachments')->where($document ? 'document_id' : 'payment_id', $document ?? $payment);
                if ($q->count() >= 5) {
                    $this->a->fail('Maximum five attachments per entry.');
                } $file = $r->file('file');
                $path = $file->store('tenants/'.app(CurrentTenant::class)->id().'/attachments', 'local');
                if (! $path) {
                    throw new \RuntimeException('File storage failed.');
                } $id = DB::table('attachments')->insertGetId(['tenant_id' => app(CurrentTenant::class)->id(), 'document_id' => $document, 'payment_id' => $payment, 'storage_path' => $path, 'original_name' => basename($file->getClientOriginalName()), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'uploaded_by' => auth('tenant')->id(), 'created_at' => now(), 'updated_at' => now()]);
                $this->a->audit(auth('tenant')->id(), 'attachment.uploaded', 'attachments', $id);

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return response()->json(['data' => ['id' => (string) $id]], 201);
    }

    public function download(string $tenant, int $id): StreamedResponse
    {
        $file = $this->a->requireRow('attachments', $id);
        $this->attachmentParent($file->document_id ? (int) $file->document_id : null, $file->payment_id ? (int) $file->payment_id : null);

        return Storage::disk('local')->download($file->storage_path, $file->original_name, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
