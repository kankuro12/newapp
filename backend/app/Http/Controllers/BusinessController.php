<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\DocumentService;
use App\Service\FulfilmentService;
use App\Service\InventoryService;
use App\Service\PaymentService;
use App\Service\TenantService;
use App\Support\Money;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BusinessController extends Controller
{
    public function __construct(private AccountingService $a) {}

    public static function json(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => &$item) {
            if ($key === 'basket_offer_snapshot' && $item !== null) {
                $item = self::json(is_string($item) ? json_decode($item, true) : $item);
            } elseif ($key === 'party_snapshot' && $item !== null) {
                $item = (array) (is_string($item) ? json_decode($item, true) : $item);
                unset($item['credit_limit_paisa'], $item['trading_version'], $item['sales_price_list_id'], $item['purchase_price_list_id']);
                $item = self::json($item);
            } elseif (in_array($key, ['item_ids', 'category_ids'], true) && is_array($item)) {
                $item = array_map(fn ($id) => (string) $id, $item);
            } elseif ($item !== null && (preg_match('/(?:_paisa|_milli|_bps|_id)$/', (string) $key) || $key === 'id' || $key === 'next_number' || $key === 'discount_value')) {
                $item = (string) $item;
            } elseif (in_array($key, ['is_customer', 'is_supplier', 'is_employee', 'is_rent', 'is_system', 'is_money', 'active', 'enabled', 'package_enabled', 'cashier_allowed', 'auto_generate', 'tax_recording_enabled', 'vat_recoverable'], true) && $item !== null) {
                $item = (bool) $item;
            } elseif (is_array($item) || is_object($item)) {
                $item = self::json($item);
            } elseif (in_array($key, ['party_snapshot', 'business_snapshot', 'source_order_snapshot', 'draft_input', 'metadata', 'measurement_snapshot', 'pos_methods', 'pos_custom_units']) && is_string($item)) {
                $item = self::json(json_decode($item, true));
            }
        } unset($item);

        return $value;
    }

    public function me(): JsonResponse
    {
        $user = auth('tenant')->user();
        abort_if(! $user || $user->disabled_at, 401);

        return response()->json(['data' => self::json($user->only(['id', 'name', 'email', 'email_verified_at', 'locale', 'phone', 'country_code'])), 'today_bs' => NepaliDate::today()])->header('Cache-Control', 'no-store');
    }

    public function businesses(): JsonResponse
    {
        abort_if(auth('tenant')->user()?->disabled_at, 401);
        $rows = DB::table('tenant_user')->where('user_id', auth('tenant')->id())->where('active', true)->join('tenants', 'tenants.id', '=', 'tenant_user.tenant_id')->select('tenants.*', 'tenant_user.role')->orderBy('tenants.name')->get();

        return response()->json(['data' => self::json($rows)])->header('Cache-Control', 'no-store');
    }

    public function createBusiness(Request $r, TenantService $service): JsonResponse
    {
        $input = $r->validate(['billing_account_id' => 'sometimes|integer|min:1', 'name' => 'required|string|max:150', 'business_type' => 'sometimes|in:general,meat,restaurant,barber,salon,milk,glass,wood,gym', 'address' => 'nullable|string|max:500', 'phone' => 'nullable|string|max:30', 'pan' => 'nullable|string|max:30', 'default_locale' => 'sometimes|in:en,ne']);

        return response()->json(['data' => self::json($service->create(auth('tenant')->id(), $input))], 201);
    }

    public function dateRule(): array
    {
        return ['required', function ($attribute, $value, $fail) {
            try {
                if (! is_int($value) && ! is_string($value)) {
                    throw new \InvalidArgumentException;
                } NepaliDate::normalize($value);
            } catch (\InvalidArgumentException) {
                $fail('Enter valid BS date YYYY-MM-DD.');
            }
        }];
    }

    public function documentRules(): array
    {
        return ['mutation_uuid' => 'required|uuid', 'contact_id' => 'nullable|integer|min:1', 'business_date_bs' => $this->dateRule(), 'due_date_bs' => ['nullable', ...array_slice($this->dateRule(), 1)], 'supplier_bill_number' => 'nullable|string|max:100', 'supplier_bill_date_bs' => ['nullable', ...array_slice($this->dateRule(), 1)], 'notes' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'nullable|integer|min:1', 'lines.*.expense_category_id' => 'nullable|integer|min:1', 'lines.*.qty' => 'required|string|max:20', 'lines.*.unit_price' => 'required|string|max:20', 'lines.*.discount' => 'sometimes|string|max:20', 'lines.*.discount_bps' => 'sometimes|integer|min:0|max:10000', 'lines.*.tax_category' => 'sometimes|in:standard,zero,exempt,outside_scope', 'lines.*.tax_bps' => 'sometimes|integer|min:0|max:10000', 'basket_offer_id' => 'nullable|integer|min:1', 'expected_fingerprint' => 'sometimes|required|string|regex:/^[a-f0-9]{64}$/', 'invoice_discount' => 'sometimes|string|max:20', 'invoice_discount_bps' => 'sometimes|integer|min:0|max:10000', 'vat_recoverable' => 'sometimes|boolean', 'promotional_confirmed' => 'sometimes|boolean', 'paid_now' => 'sometimes|string|max:20', 'money_account_id' => 'nullable|integer|min:1', 'expected_total_paisa' => 'sometimes|regex:/^\d{1,11}$/', 'version' => 'sometimes|integer|min:1', 'overdraft_confirmed' => 'sometimes|boolean'];
    }

    public function cloneDraft(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid']);

        return $this->result($service->cloneDraft(auth('tenant')->id(), $document, $input['mutation_uuid']));
    }

    public function documentData(int $id): array
    {
        $doc = $this->a->requireRow('documents', $id);
        $cashier = $this->a->role(auth('tenant')->id()) === 'cashier';
        if ($cashier && ($doc->type !== 'sale' || $doc->created_by != auth('tenant')->id())) {
            abort(403);
        }
        $lines = $this->a->rows('document_lines')->where('document_id', $id)->orderBy('position')->get()->map(function ($line) use ($cashier, $doc) {
            $progress = ($doc->fulfilment_policy ?? null) === 'bill_first' && $doc->workflow_id ? app(FulfilmentService::class)->billLineProgress(auth('tenant')->id(), $doc, $line) : [];
            $line = [...(array) $line, ...$progress];
            if ($cashier) {
                unset($line['inventory_cost_paisa']);
            } $returned = (int) $this->a->rows('document_lines')->where('source_line_id', $line['id'])->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->sum('qty_milli');
            $line['returnable_qty_milli'] = (int) $line['qty_milli'] - $returned;

            return $line;
        });
        $data = [...(array) $doc, 'lines' => $lines, ...app(PaymentService::class)->invoiceBalance($id)];
        unset($data['journal_id'], $data['reversal_journal_id']);
        $data['payments'] = $this->a->rows('payments')->whereIn('id', $this->a->rows('payment_allocations')->where('document_id', $id)->select('payment_id'))->orderBy('id')->get();
        $data['returns'] = $this->a->rows('documents')->where('source_document_id', $id)->select('id', 'number', 'status', 'total_paisa', 'business_date_bs')->get();
        $data['attachments'] = $this->a->rows('attachments')->where('document_id', $id)->select('id', 'original_name', 'mime_type', 'size_bytes')->get();

        return self::json($data);
    }

    public function result(array $result): JsonResponse
    {
        $data = $result['table'] === 'documents' ? $this->documentData((int) $result['id']) : ($result['table'] === 'tenants' ? Tenant::findOrFail($result['id'])->toArray() : (array) $this->a->requireRow($result['table'], $result['id']));

        return response()->json(['data' => self::json($data)], $result['replayed'] ? 200 : 201);
    }

    public function documents(Request $r, string $tenant, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['sale', 'purchase', 'expense', 'sale_return', 'purchase_return']), 404);
        $q = $this->a->rows('documents')->where('type', $type);
        if ($r->attributes->get('role') === 'cashier') {
            abort_unless($type === 'sale', 403);
            $q->where('created_by', auth('tenant')->id());
        }
        $filter = $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:draft,posted,cancelled', 'page' => 'nullable|integer|min:1']);
        if (! empty($filter['q'])) {
            $q->where('number', 'like', '%'.$filter['q'].'%');
        } if (! empty($filter['status'])) {
            $q->where('status', $filter['status']);
        }
        $page = $q->orderByDesc('id')->paginate(25);
        $page->getCollection()->transform(function ($doc) {
            $data = (array) $doc;
            unset($data['journal_id'], $data['draft_input'], $data['reversal_journal_id']);

            return self::json([...$data, ...app(PaymentService::class)->invoiceBalance((int) $doc->id)]);
        });

        return response()->json($page);
    }

    public function show(string $tenant, int $document): JsonResponse
    {
        return response()->json(['data' => $this->documentData($document)]);
    }

    public function preview(Request $r, string $tenant, string $type, DocumentService $service): JsonResponse
    {
        abort_unless(in_array($type, ['sale', 'purchase', 'expense']), 404);
        $input = $r->validate([...$this->documentRules(), 'mutation_uuid' => 'sometimes|uuid']);

        return response()->json(['data' => $this->json($service->preview(auth('tenant')->id(), [...$input, 'type' => $type]))]);
    }

    public function draftPreview(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate([...$this->documentRules(), 'mutation_uuid' => 'sometimes|uuid', 'version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->json($service->preview(auth('tenant')->id(), $input, $document))]);
    }

    public function postPreview(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate(['version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->json($service->postPreview(auth('tenant')->id(), $document, $input))]);
    }

    public function save(Request $r, string $tenant, string $type, DocumentService $service): JsonResponse
    {
        abort_unless(in_array($type, ['sale', 'purchase', 'expense']), 404);
        $input = $r->validate($this->documentRules());
        $input['type'] = $type;
        foreach ($input['lines'] as $line) {
            if ($type === 'expense' ? empty($line['expense_category_id']) : empty($line['item_id'])) {
                $this->a->fail('Choose item/category.', 'lines');
            }
        }

        return $this->result($service->save(auth('tenant')->id(), $input, $input['mutation_uuid'], ! str_ends_with($r->path(), '/drafts')));
    }

    public function postDraft(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'expected_fingerprint' => 'sometimes|required|string|regex:/^[a-f0-9]{64}$/', 'paid_now' => 'required|string|max:20', 'money_account_id' => 'nullable|integer|min:1', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'overdraft_confirmed' => 'sometimes|boolean']);

        return $this->result($service->post(auth('tenant')->id(), $document, $input, $input['mutation_uuid']));
    }

    public function updateDraft(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate([...$this->documentRules(), 'version' => 'required|integer|min:1']);

        return $this->result($service->updateDraft(auth('tenant')->id(), $document, $input, $input['mutation_uuid']));
    }

    public function deleteDraft(string $tenant, int $document, DocumentService $service): Response
    {
        $service->deleteDraft(auth('tenant')->id(), $document);

        return response()->noContent();
    }

    public function returns(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', ...$this->returnRules()]);

        return $this->result($service->createReturn(auth('tenant')->id(), $document, $input, $input['mutation_uuid']));
    }

    private function returnRules(): array
    {
        return ['version' => 'sometimes|required|integer|min:1', 'workflow_version' => 'sometimes|required|integer|min:1', 'expected_fingerprint' => 'sometimes|required|string|size:64', 'business_date_bs' => $this->dateRule(), 'reason' => 'required|string|min:5|max:500', 'lines' => 'required|array|min:1|max:100', 'lines.*.source_line_id' => 'required|integer|min:1', 'lines.*.return_source' => ['sometimes', 'required', 'string', 'regex:/^(unfulfilled|[1-9][0-9]{0,18})$/'], 'lines.*.return_mode' => 'sometimes|required|in:unfulfilled,transit,completed', 'lines.*.qty' => 'required|string|max:20', 'refund_now' => 'sometimes|boolean', 'money_account_id' => 'nullable|integer|min:1', 'overdraft_confirmed' => 'sometimes|boolean'];
    }

    public function returnPreview(Request $r, string $tenant, int $document, DocumentService $service): JsonResponse
    {
        return response()->json(['data' => self::json($service->returnPreview(auth('tenant')->id(), $document, $r->validate($this->returnRules())))]);
    }

    public function cancel(Request $r, string $tenant, string $source, int $id): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'business_date_bs' => $this->dateRule(), 'reason' => 'required|string|min:5|max:500', 'overdraft_confirmed' => 'sometimes|boolean']);
        $service = match ($source) {
            'document' => app(DocumentService::class), 'payments' => app(PaymentService::class), 'stock-adjustments' => app(InventoryService::class), default => abort(404)
        };

        return $this->result($service->cancel(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function openings(Request $r): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'business_date_bs' => $this->dateRule(), 'money' => 'present|array|max:100', 'money.*.account_id' => 'required|integer|distinct', 'money.*.amount' => 'required|string|max:20', 'stock' => 'present|array|max:100', 'stock.*.item_id' => 'required|integer|distinct', 'stock.*.qty' => 'required|string|max:20', 'stock.*.value' => 'required|string|max:20', 'stock.*.zero_cost_confirmed' => 'sometimes|boolean', 'parties' => 'present|array|max:100', 'parties.*.contact_id' => 'required|integer', 'parties.*.direction' => 'required|in:customer_owes,customer_credit,supplier_owed,supplier_advance', 'parties.*.amount' => 'required|string|max:20']);

        return $this->result($this->a->finalizeOpenings(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }

    public function owner(Request $r): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'kind' => 'required|in:contribution,withdrawal', 'amount' => 'required|string|max:20', 'money_account_id' => 'required|integer|min:1', 'business_date_bs' => $this->dateRule(), 'notes' => 'nullable|string|max:1000', 'overdraft_confirmed' => 'sometimes|boolean']);

        return $this->result($this->a->owner(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }

    public function ownerList(): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant']);

        $page = $this->a->rows('journal_entries')->where('source_type', 'owner')->where('source_event', 'post')
            ->select('id', 'owner_entry_kind', 'description', 'business_date_bs')
            ->selectSub($this->a->rows('journal_lines')->whereColumn('journal_entry_id', 'journal_entries.id')->selectRaw('SUM(debit_paisa)'), 'amount_paisa')
            ->selectSub($this->a->rows('journal_entries as reversals')->whereColumn('reversal_of_id', 'journal_entries.id')->select('business_date_bs')->limit(1), 'cancellation_date_bs')
            ->orderByDesc('id')->paginate(25);
        $page->getCollection()->transform(fn ($row) => [...(array) $row, 'status' => $row->cancellation_date_bs ? 'cancelled' : 'posted']);

        return response()->json(self::json($page->toArray()));
    }

    public function cancelOwner(Request $r, string $tenant, int $id): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'business_date_bs' => $this->dateRule(), 'reason' => 'required|string|min:5|max:500']);
        $actor = auth('tenant')->id();

        return $this->result($this->a->mutate($actor, $input['mutation_uuid'], 'owner.cancel.'.$id, $input, function () use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $original = $this->a->requireRow('journal_entries', $id);
            abort_unless($original->source_type === 'owner' && $original->source_event === 'post', 403);
            $existing = $this->a->rows('journal_entries')->where('reversal_of_id', $id)->first();
            $reverse = $existing?->id ?? $this->a->reverse($actor, $id, NepaliDate::normalize($input['business_date_bs']), $input['reason']);

            return ['table' => 'journal_entries', 'id' => $reverse];
        }));
    }

    public function countStock(Request $r, InventoryService $stock): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'item_id' => 'required|integer|min:1', 'counted_qty' => 'required|string|max:20', 'expected_qty_milli' => 'required|regex:/^\d{1,18}$/', 'unit_cost' => 'sometimes|string|max:20', 'zero_cost_confirmed' => 'sometimes|boolean', 'business_date_bs' => $this->dateRule(), 'reason' => 'required|string|min:5|max:500']);

        return $this->result($stock->adjust(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }

    public function adjustments(): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant']);

        return response()->json(self::json($this->a->rows('stock_adjustments')->orderByDesc('id')->paginate(25)->toArray()));
    }

    public function close(Request $r, ReportController $reports): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'business_date_bs' => $this->dateRule(), 'reason' => 'required|string|min:5|max:500', 'password' => 'required|string']);
        $actor = auth('tenant')->id();
        abort_unless(Hash::check($input['password'], auth('tenant')->user()->password), 403, 'Password incorrect.');
        unset($input['password']);

        return $this->result($this->a->mutate($actor, $input['mutation_uuid'], 'period.close', $input, function (Tenant $tenant) use ($actor, $input, $reports) {
            $this->a->authorize($actor, ['owner', 'accountant']);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $date);
            $report = $reports->overview($date, $date);
            if ($report['debit_paisa'] !== $report['credit_paisa'] || (int) $report['inventory_paisa'] !== $reports->stockValue($date)) {
                $this->a->fail('Reconciliation failed; period remains open.');
            } foreach ($reports->reconciliation($date) as $party) {
                if ($party['difference_paisa']) {
                    $this->a->fail('Party reconciliation failed; period remains open.');
                }
            }
            $tenant->update(['closed_through_bs' => $date]);
            $this->a->audit($actor, 'period.closed', 'tenants', $tenant->id, ['reason' => $input['reason'], 'date_bs' => $date]);

            return ['table' => 'tenants', 'id' => $tenant->id];
        }));
    }

    public function payments(): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant']);

        return response()->json(self::json($this->a->rows('payments')->orderByDesc('id')->paginate(25)->toArray()));
    }

    public function paymentPreview(Request $r): JsonResponse
    {
        $input = $r->validate(['kind' => 'required|in:receipt,supplier_payment,customer_refund,supplier_refund', 'contact_id' => 'required|integer|min:1', 'amount' => 'required|string|max:20']);
        $actor = auth('tenant')->id();
        $cashier = $this->a->role($actor) === 'cashier';
        if ($cashier && $input['kind'] !== 'receipt') {
            abort(403);
        } $party = $this->a->requireRow('contacts', $input['contact_id']);
        $customer = in_array($input['kind'], ['receipt', 'customer_refund']);
        $refund = in_array($input['kind'], ['customer_refund', 'supplier_refund']);
        $remaining = Money::parse($input['amount']);
        $bills = [];
        $docs = $this->a->rows('documents')->where('contact_id', $party->id)->where('status', 'posted')->whereIn('type', $customer ? ['sale'] : ['purchase', 'expense'])->orderByRaw('COALESCE(due_date_bs,business_date_bs)')->orderBy('id');
        if ($cashier) {
            $docs->where('created_by', $actor);
        }
        foreach ($docs->limit(100)->get() as $doc) {
            $balance = app(PaymentService::class)->invoiceBalance((int) $doc->id);
            $available = $refund ? $balance['credit_paisa'] : $balance['due_paisa'];
            if ($available > 0) {
                $applied = min($remaining, $available);
                $bills[] = ['id' => $doc->id, 'number' => $doc->number, 'available_paisa' => $available, 'suggested_paisa' => $applied];
                $remaining -= $applied;
            }
        }
        $data = ['bills' => $bills, 'unallocated_paisa' => $remaining];
        if (! $cashier) {
            $data['party_balance_paisa'] = $this->a->balance($this->a->account($customer ? 'receivables' : 'payables'), (int) $party->id) * ($customer ? 1 : -1);
        }

        return response()->json(['data' => self::json($data)]);
    }

    public function payment(Request $r, PaymentService $service): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'kind' => 'required|in:receipt,supplier_payment,customer_refund,supplier_refund,transfer', 'business_date_bs' => $this->dateRule(), 'contact_id' => 'nullable|integer|min:1', 'money_account_id' => 'required|integer|min:1', 'destination_account_id' => 'nullable|integer|min:1', 'amount' => 'required|string|max:20', 'reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:1000', 'allocations' => 'sometimes|array|max:100', 'allocations.*.document_id' => 'required|integer|min:1', 'allocations.*.amount' => 'required|string|max:20', 'unallocated_confirmed' => 'sometimes|boolean', 'overdraft_confirmed' => 'sometimes|boolean']);
        if ($input['kind'] !== 'transfer' && empty($input['contact_id'])) {
            $this->a->fail('Choose customer/supplier.', 'contact_id');
        }

        return $this->result($service->post(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }
}
