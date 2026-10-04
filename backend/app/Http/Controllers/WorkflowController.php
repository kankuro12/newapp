<?php

namespace App\Http\Controllers;

use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    public function __construct(private AccountingService $a, private WorkflowService $service, private BusinessController $business) {}

    private function result(array $result): JsonResponse
    {
        if ($result['table'] === 'documents') {
            return $this->business->result($result);
        }

        return response()->json(['data' => BusinessController::json($this->service->data(auth('tenant')->id(), $result['id']))], $result['replayed'] ? 200 : 201);
    }

    public function listing(Request $r): JsonResponse
    {
        $input = $r->validate(['kind' => ['nullable', Rule::in(WorkflowService::KINDS)], 'status' => 'nullable|in:draft,sent,accepted,rejected,open,in_progress,ready,fulfilled,cancelled,converted', 'q' => 'nullable|string|max:150', 'overdue' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);
        $q = $this->a->rows('business_workflows');
        $actor = auth('tenant')->id();
        if ($this->a->role($actor) === 'cashier') {
            $q->where('created_by', $actor)->where('kind', '<>', 'purchase_order');
        }
        foreach (['kind', 'status'] as $key) {
            if (! empty($input[$key])) {
                $q->where($key, $input[$key]);
            }
        }
        if (! empty($input['q'])) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$input['q'].'%')->orWhere('reference', 'like', '%'.$input['q'].'%'));
        }
        if (! empty($input['overdue'])) {
            $q->where('due_date_bs', '<', NepaliDate::today())->whereNotIn('status', ['fulfilled', 'cancelled', 'converted', 'rejected']);
        }
        $page = $q->select(['id', 'kind', 'status', 'version', 'sequence', 'title', 'reference', 'niche', 'business_date_bs', 'due_date_bs', 'valid_until_bs', 'total_paisa', 'contact_id', 'party_snapshot', 'source_id', 'document_id'])->orderByDesc('id')->paginate(25);
        $page->getCollection()->transform(function ($row) {
            $row->party_snapshot = json_decode($row->party_snapshot, true);
            $row->number = ['quote' => 'QUO', 'sales_order' => 'SO', 'purchase_order' => 'PO'][$row->kind].'-'.str_pad((string) $row->sequence, 6, '0', STR_PAD_LEFT);

            return BusinessController::json($row);
        });

        return response()->json($page);
    }

    public function show(string $tenant, int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->data(auth('tenant')->id(), $id))]);
    }

    public function preview(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $input = $r->validate([...$this->business->documentRules(), 'mutation_uuid' => 'sometimes|uuid', 'kind' => ['required', Rule::in(WorkflowService::KINDS)], 'lines.*.item_id' => 'required|integer|min:1', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1']);

        return response()->json(['data' => $this->business->json($this->service->preview(auth('tenant')->id(), $input, $id))]);
    }

    public function save(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $rules = $this->business->documentRules();
        $input = $r->validate([...$rules, 'kind' => ['required', Rule::in(WorkflowService::KINDS)], 'lines.*.item_id' => 'required|integer|min:1', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'version' => $id ? 'required|integer|min:1' : 'sometimes|integer|min:1', 'niche' => ['sometimes', Rule::in(WorkflowService::NICHES)], 'title' => 'nullable|string|max:150', 'reference' => 'nullable|string|max:150', 'specifications' => 'nullable|string|max:4000', 'reorder' => 'sometimes|array|min:1|max:100', 'reorder.*' => 'required|string|size:64', 'valid_until_bs' => ['nullable', ...array_slice($this->business->dateRule(), 1)]]);

        return $this->result($this->service->save(auth('tenant')->id(), $input, $input['mutation_uuid'], $id));
    }

    public function status(Request $r, string $tenant, int $id): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'status' => 'required|in:draft,sent,accepted,rejected,in_progress,ready,fulfilled,cancelled', 'reason' => 'nullable|string|max:500']);

        return $this->result($this->service->status(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function order(Request $r, string $tenant, int $id): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1']);

        return $this->result($this->service->order(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function bill(Request $r, string $tenant, int $id): JsonResponse
    {
        $allowed = array_intersect_key($this->business->documentRules(), array_flip(['mutation_uuid', 'business_date_bs', 'due_date_bs', 'paid_now', 'money_account_id', 'expected_total_paisa', 'vat_recoverable', 'supplier_bill_number', 'supplier_bill_date_bs', 'overdraft_confirmed']));
        $input = $r->validate([...$allowed, 'version' => 'required|integer|min:1', 'paid_now' => 'required|string|max:20', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/']);

        return $this->result($this->service->bill(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }
}
