<?php

namespace App\Http\Controllers;

use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\PartyService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartyController extends Controller
{
    public function __construct(private AccountingService $a, private PartyService $service, private BusinessController $business) {}

    private function actor(): int
    {
        $id = auth('tenant')->id();
        $this->service->manage($id);

        return $id;
    }

    private function result(array $result): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->a->requireRow($result['table'], $result['id']))], $result['replayed'] ? 200 : 201);
    }

    public function trading(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->actor();
        if ($r->isMethod('PATCH')) {
            $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'sales_terms_days' => 'required|integer|min:0|max:3650', 'purchase_terms_days' => 'required|integer|min:0|max:3650', 'credit_limit' => 'nullable|string|max:20']);

            return $this->result($this->service->configure($actor, $id, $input));
        }

        return response()->json(['data' => BusinessController::json($this->a->requireRow('contacts', $id))]);
    }

    public function rates(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->actor();
        $this->a->requireRow('contacts', $id);
        if ($r->isMethod('POST')) {
            $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'sometimes|integer|min:1', 'item_id' => 'required|integer|min:1', 'channel' => 'required|in:sale,purchase', 'price' => 'required|string|max:20', 'enabled' => 'required|boolean']);

            return $this->result($this->service->saveRate($actor, $id, $input));
        }
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $q = $this->a->rows('party_prices')->where('contact_id', $id)->orderByDesc('id')->paginate(25);
        $q->getCollection()->transform(function ($row) {
            $row->item_name = $this->a->requireRow('items', $row->item_id)->name;

            return BusinessController::json($row);
        });

        return response()->json($q);
    }

    public function priceLists(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $actor = auth('tenant')->id();
        if (! $r->isMethod('GET')) {
            $this->service->manage($actor);
            $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'sometimes|integer|min:1', 'name' => 'required|string|max:100', 'channel' => 'required|in:sale,purchase', 'pricing_scheme' => 'sometimes|in:volume,slab', 'enabled' => 'required|boolean', 'adjustment_mode' => 'required|in:increase,decrease', 'adjustment_percent' => 'required|string|max:20', 'starts_bs' => ['nullable', ...array_slice($this->business->dateRule(), 1)], 'ends_bs' => ['nullable', ...array_slice($this->business->dateRule(), 1)], 'rules' => 'present|array|list|max:1000', 'rules.*' => 'required|array:item_id,min_qty,price,unit_snapshot,pos_unit,item_kind', 'rules.*.item_id' => 'required|integer|min:1', 'rules.*.min_qty' => 'required|string|max:20', 'rules.*.price' => 'required|string|max:20', 'rules.*.unit_snapshot' => 'sometimes|required|string|max:30', 'rules.*.pos_unit' => 'sometimes|required|string|max:20', 'rules.*.item_kind' => 'sometimes|in:stock,service']);
            $result = $this->service->savePriceList($actor, $input, $id);

            return response()->json(['data' => BusinessController::json($this->service->priceListData($result['id']))], $result['replayed'] ? 200 : 201);
        }
        $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
        $input = $r->validate(['channel' => 'sometimes|in:sale,purchase', 'page' => 'sometimes|integer|min:1', 'enabled' => 'sometimes|boolean']);
        $cashier = $this->a->role($actor) === 'cashier';
        abort_if($cashier && ($input['channel'] ?? 'sale') === 'purchase', 403);
        if ($id) {
            $list = $this->a->requireRow('price_lists', $id);
            abort_if($cashier && $list->channel !== 'sale', 403);

            return response()->json(['data' => BusinessController::json($this->service->priceListData($id))]);
        }
        $q = $this->a->rows('price_lists');
        if ($cashier || isset($input['channel'])) {
            $q->where('channel', $input['channel'] ?? 'sale');
        }
        if (isset($input['enabled'])) {
            $q->where('enabled', $input['enabled']);
        }
        $page = $q->orderBy('name')->orderBy('id')->paginate(25);
        $page->getCollection()->transform(fn ($row) => BusinessController::json($row));

        return response()->json($page);
    }

    public function assignLists(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->actor();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'sales_price_list_id' => 'present|nullable|integer|min:1', 'purchase_price_list_id' => 'present|nullable|integer|min:1']);

        return $this->result($this->service->assignPriceLists($actor, $id, $input));
    }

    public function suggestions(Request $r, string $tenant, int $id = 0): JsonResponse
    {
        $input = $r->validate(['items' => 'required|array|list|min:1|max:100', 'items.*' => 'required|integer|min:1', 'channel' => 'required|in:sale,purchase', 'quantities' => 'sometimes|array|max:100', 'quantities.*' => 'required|string|max:20', 'price_list_id' => 'nullable|integer|min:1', 'business_date_bs' => ['sometimes', ...$this->business->dateRule()]]);
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant', 'cashier']);
        if ($input['channel'] === 'purchase') {
            $this->actor();
        }
        $party = $id ? $this->a->requireRow('contacts', $id) : null;
        if ($party && ($party->archived_at || ($input['channel'] === 'sale' ? ! $party->is_customer : ! $party->is_supplier))) {
            $this->a->fail('Choose active matching party.');
        }
        $rows = [];
        foreach (array_unique($input['items']) as $itemId) {
            $item = $this->a->requireRow('items', $itemId);
            if ($item->archived_at) {
                $this->a->fail('Item archived.');
            }
            $qty = Money::quantity($input['quantities'][$itemId] ?? '1');
            if (! $qty) {
                $this->a->fail('Quantity must be positive.', 'quantities');
            }
            $rows[] = ['item_id' => $itemId, ...$this->service->pricing($id, $item, $input['channel'], $qty, isset($input['price_list_id']) ? (int) $input['price_list_id'] : null, isset($input['business_date_bs']) ? NepaliDate::normalize($input['business_date_bs']) : null)];
        }

        return response()->json(['data' => BusinessController::json($rows)]);
    }

    public function collections(Request $r): JsonResponse
    {
        $this->actor();
        $input = $r->validate(['channel' => 'sometimes|in:receivables,payables', 'q' => 'nullable|string|max:100', 'page' => 'sometimes|integer|min:1']);
        $channel = $input['channel'] ?? 'receivables';
        $ledger = $this->a->rows('journal_lines')->where('account_id', $this->a->account($channel))->whereIn('journal_entry_id', $this->a->rows('journal_entries')->where('business_date_bs', '<=', NepaliDate::today())->select('id'))->select('contact_id')->selectRaw('SUM(debit_paisa-credit_paisa)*? AS due_paisa', [$channel === 'receivables' ? 1 : -1])->groupBy('contact_id');
        $q = $this->a->rows('contacts')->joinSub($ledger, 'balances', fn ($j) => $j->on('contacts.id', '=', 'balances.contact_id'))->where('balances.due_paisa', '>', 0)->select('contacts.id', 'contacts.name', 'contacts.phone', 'contacts.archived_at', 'balances.due_paisa');
        if (! empty($input['q'])) {
            $q->where(fn ($q) => $q->where('contacts.name', 'like', '%'.$input['q'].'%')->orWhere('contacts.phone', 'like', '%'.$input['q'].'%'));
        }$page = $q->orderByDesc('balances.due_paisa')->orderBy('contacts.id')->paginate(25);
        $page->getCollection()->transform(fn ($row) => BusinessController::json($row));

        return response()->json($page);
    }

    public function listing(Request $r): JsonResponse
    {
        $this->actor();
        $input = $r->validate(['status' => 'sometimes|in:open,done,cancelled,all', 'contact_id' => 'nullable|integer|min:1', 'due' => 'sometimes|boolean', 'mine' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1']);
        $q = $this->a->rows('party_followups');
        if (($input['status'] ?? 'open') !== 'all') {
            $q->where('status', $input['status'] ?? 'open');
        }if (! empty($input['contact_id'])) {
            $this->a->requireRow('contacts', $input['contact_id']);
            $q->where('contact_id', $input['contact_id']);
        }if (! empty($input['due'])) {
            $q->where('due_date_bs', '<=', NepaliDate::today());
        }if (! empty($input['mine'])) {
            $q->where('assigned_to', auth('tenant')->id());
        }
        $page = $q->orderBy('due_date_bs')->orderBy('id')->paginate(25);
        $page->getCollection()->transform(fn ($row) => $this->data($row));
        $response = $page->toArray();
        $response['staff'] = $this->a->rows('tenant_user')->where('active', true)->whereIn('role', ['owner', 'manager', 'accountant'])->whereIn('user_id', DB::table('users')->whereNull('disabled_at')->select('id'))->get()->map(fn ($member) => ['id' => (string) $member->user_id, 'name' => DB::table('users')->where('id', $member->user_id)->value('name')]);

        return response()->json(BusinessController::json($response));
    }

    private function data(object $row): array
    {
        $data = (array) $row;
        $party = $this->a->requireRow('contacts', $row->contact_id);
        $data['party_name'] = $party->name;
        $data['phone'] = $party->phone;
        $data['assigned_name'] = DB::table('users')->where('id', $row->assigned_to)->value('name');
        $data['overdue'] = $row->status === 'open' && $row->due_date_bs < NepaliDate::today();
        if ($row->document_id) {
            $doc = $this->a->requireRow('documents', $row->document_id);
            $data['bill_number'] = $doc->number;
            $data['bill_status'] = $doc->status;
        }

        return BusinessController::json($data);
    }

    public function show(Request $r, string $tenant, int $id): JsonResponse
    {
        $this->actor();
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $history = $this->a->rows('party_followup_events')->where('followup_id', $id)->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $this->data($this->a->requireRow('party_followups', $id)), 'history' => BusinessController::json($history->toArray())]);
    }

    public function save(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $actor = $this->actor();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1', 'contact_id' => $id ? 'prohibited' : 'required|integer|min:1', 'document_id' => $id ? 'prohibited' : 'nullable|integer|min:1', 'title' => $id ? 'sometimes|required|string|max:150' : 'required|string|max:150', 'due_date_bs' => $id ? ['sometimes', ...$this->business->dateRule()] : $this->business->dateRule(), 'channel' => $id ? 'sometimes|in:phone,visit,message,note' : 'required|in:phone,visit,message,note', 'assigned_to' => 'nullable|integer|min:1', 'status' => $id ? 'sometimes|in:open,done,cancelled' : 'prohibited', 'notes' => 'nullable|string|max:1000']);
        $result = $this->service->followup($actor, $input, $id);

        return response()->json(['data' => $this->data($this->a->requireRow('party_followups', $result['id']))], $result['replayed'] ? 200 : 201);
    }
}
