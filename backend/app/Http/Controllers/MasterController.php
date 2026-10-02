<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Service\AccountingService;
use App\Service\PosService;
use App\Service\TenantService;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterController extends Controller
{
    public function __construct(private AccountingService $a) {}

    private function fields(string $resource, bool $cashier): array
    {
        return match ($resource) {
            'contacts' => ['id', 'name', 'phone', 'email', 'address', 'pan', 'is_customer', 'is_supplier', 'is_employee', 'is_rent', 'is_system', 'archived_at'], 'items' => $cashier ? ['id', 'name', 'sku', 'kind', 'unit_label', 'sale_price_paisa', 'default_tax_category', 'default_tax_bps', 'low_stock_qty_milli', 'pos_unit', 'pos_methods', 'pos_custom_units', 'service_minutes', 'archived_at'] : ['*'], 'accounts' => ['id', 'name', 'system_key', 'is_money', 'archived_at'], 'expense-categories' => ['id', 'name', 'account_id', 'archived_at'], default => abort(404)
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
        $filter = $r->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'archived' => 'nullable|boolean']);
        $q = $this->a->rows($this->table($resource))->select($this->fields($resource, $cashier));
        if (! empty($filter['q'])) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$filter['q'].'%')->when($resource === 'items', fn ($q) => $q->orWhere('sku', $filter['q'])));
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
        $input = $r->validate(['q' => 'nullable|string|max:100', 'party_role' => 'nullable|in:customer,supplier,employee,rent,payable']);
        $cashier = $r->attributes->get('role') === 'cashier';
        $data = [];
        foreach (['contacts', 'items', 'accounts', 'expense-categories'] as $resource) {
            if ($cashier && $resource === 'expense-categories') {
                $data['categories'] = [];

                continue;
            } $q = $this->a->rows($this->table($resource))->select($this->fields($resource, $cashier))->whereNull('archived_at');
            if ($resource === 'accounts') {
                $q->where('is_money', true);
            } if (in_array($resource, ['contacts', 'items']) && ! empty($input['q'])) {
                $q->where(fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%')->when($resource === 'items', fn ($q) => $q->orWhere('sku', $input['q'])));
            }
            if ($resource === 'contacts' && ! empty($input['party_role'])) {
                $role = $input['party_role'];
                if ($role === 'payable') {
                    $q->where(fn ($q) => $q->where('is_supplier', true)->orWhere('is_employee', true)->orWhere('is_rent', true));
                } else {
                    $q->where('is_'.$role, true);
                }
            }
            $data[$resource === 'expense-categories' ? 'categories' : $resource] = $q->orderBy('id')->limit($resource === 'accounts' ? 100 : 20)->get()->map(fn ($row) => $resource === 'items' ? $this->enrich($resource, $row, $cashier) : (array) $row);
        }
        if (! $cashier) {
            $data['channels'] = ['receivables_id' => $this->a->account('receivables'), 'payables_id' => $this->a->account('payables')];
        }

        return response()->json(['data' => BusinessController::json($data)]);
    }

    public function save(Request $r, string $tenant, string $resource, ?int $id = null): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, $resource === 'contacts' ? ['owner', 'manager', 'cashier', 'accountant'] : ($resource === 'items' ? ['owner', 'manager', 'accountant'] : ['owner']));
        $rules = match ($resource) {
            'contacts' => ['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:500', 'pan' => 'nullable|string|max:30', 'is_customer' => 'required|boolean', 'is_supplier' => 'required|boolean', 'is_employee' => 'sometimes|boolean', 'is_rent' => 'sometimes|boolean'],
            'items' => ['name' => 'required|string|max:150', 'sku' => 'nullable|string|max:100', 'kind' => 'required|in:stock,service', 'unit_label' => 'required|string|max:30', 'sale_price' => 'required|string|max:20', 'pos_unit' => ['sometimes', Rule::in(array_keys(PosService::UNITS))], 'pos_methods' => 'sometimes|array|min:1|max:6', 'pos_methods.*' => ['required', Rule::in(PosService::METHODS)], 'pos_custom_units' => 'sometimes|array|max:50', 'pos_custom_units.*.label' => 'required|string|max:50', 'pos_custom_units.*.qty' => 'required|string|max:20', 'service_minutes' => 'sometimes|integer|min:1|max:720', 'low_stock_qty' => 'sometimes|string|max:20', 'default_tax_bps' => 'sometimes|integer|min:0|max:10000', 'default_tax_category' => 'sometimes|in:standard,zero,exempt,outside_scope'],
            'accounts' => ['name' => 'required|string|max:150', 'money_kind' => 'required|in:cash,bank'],
            'expense-categories' => ['name' => 'required|string|max:150'], default => abort(404),
        };
        $input = $r->validate($rules);
        if ($resource === 'contacts' && ! $input['is_customer'] && ! $input['is_supplier'] && ! ($input['is_employee'] ?? false) && ! ($input['is_rent'] ?? false)) {
            $this->a->fail('Choose at least one party role.');
        }
        if ($resource === 'items') {
            $input['sale_price_paisa'] = Money::parse($input['sale_price']);
            $input['low_stock_qty_milli'] = Money::quantity($input['low_stock_qty'] ?? '0');
            unset($input['sale_price'], $input['low_stock_qty']);
            foreach ($input['pos_custom_units'] ?? [] as $unit) {
                if (Money::quantity($unit['qty']) <= 0) {
                    $this->a->fail('Custom unit quantity must be positive.');
                }
            }
            foreach (['pos_methods', 'pos_custom_units'] as $key) {
                if (isset($input[$key])) {
                    $input[$key] = json_encode($input[$key]);
                }
            }
        }
        $rowId = DB::transaction(function () use ($actor, $resource, $input, $id) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $table = $this->table($resource);
            $values = $input;
            if ($id) {
                $old = $this->a->requireRow($table, $id);
                if ($resource === 'contacts' && $old->is_system) {
                    abort(403);
                }
                if ($resource === 'contacts') {
                    $next = (object) [...(array) $old, ...$input];
                    if ((! $next->is_customer && $this->a->balance($this->a->account('receivables'), (int) $id)) || (! $this->a->payableParty($next) && $this->a->balance($this->a->account('payables'), (int) $id))) {
                        $this->a->fail('Keep party role while its money channel has outstanding balance.');
                    }
                } if ($resource === 'items' && ($old->kind !== $input['kind'] || $old->unit_label !== $input['unit_label'] || (isset($input['pos_unit']) && $old->pos_unit !== $input['pos_unit'])) && $this->a->rows('stock_movements')->where('item_id', $id)->exists()) {
                    $this->a->fail('Unit/kind cannot change after stock activity.');
                } if (! in_array($resource, ['items', 'contacts'])) {
                    abort(403);
                } $this->a->rows($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);
                $rowId = $id;
            } else {
                if ($resource === 'accounts' || $resource === 'expense-categories') {
                    $account = DB::table('accounts')->insertGetId(['tenant_id' => $tenant->id, 'code' => (string) (100000 + (int) $this->a->rows('accounts')->max('id')), 'name' => $input['name'], 'category' => $resource === 'accounts' ? 'asset' : 'expense', 'normal_side' => 'dr', 'is_money' => $resource === 'accounts', 'money_kind' => $resource === 'accounts' ? $input['money_kind'] : null, 'created_at' => now(), 'updated_at' => now()]);
                    if ($resource === 'accounts') {
                        $rowId = $account;
                    } else {
                        $rowId = DB::table($table)->insertGetId(['tenant_id' => $tenant->id, 'name' => $input['name'], 'account_id' => $account, 'created_at' => now(), 'updated_at' => now()]);
                    }
                } else {
                    $rowId = DB::table($table)->insertGetId(['tenant_id' => $tenant->id, ...$values, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $tenant->increment('data_version');
            $this->a->audit($actor, $resource.'.saved', $table, $rowId);

            return $rowId;
        }, 3);

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
