<?php

namespace App\Http\Controllers;

use App\Service\AccountingService;
use App\Service\BasketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BasketController extends Controller
{
    public function __construct(private AccountingService $a, private BasketService $service, private BusinessController $business) {}

    public function offers(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
        if (! $r->isMethod('GET')) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'sometimes|integer|min:1', 'name' => 'required|string|max:100', 'offer_kind' => 'sometimes|in:basket,bundle,buy_get,items', 'maximum_applications' => 'nullable|integer|min:1|max:1000000000', 'rules' => 'sometimes|array|list|max:20', 'rules.*' => 'required|array:item_id,item_ids,category_ids,role,qty,unit_snapshot,pos_unit,item_kind,item_snapshots', 'rules.*.item_id' => 'sometimes|integer|min:1', 'rules.*.item_ids' => 'sometimes|array|list|max:100', 'rules.*.item_ids.*' => 'required|integer|min:1', 'rules.*.category_ids' => 'sometimes|array|list|max:20', 'rules.*.category_ids.*' => 'required|integer|min:1', 'rules.*.item_snapshots' => 'sometimes|array|list|max:100', 'rules.*.item_snapshots.*' => 'required|array:item_id,unit_snapshot,pos_unit,item_kind', 'rules.*.item_snapshots.*.item_id' => 'required|integer|min:1', 'rules.*.item_snapshots.*.unit_snapshot' => 'required|string|max:30', 'rules.*.item_snapshots.*.pos_unit' => 'required|string|max:20', 'rules.*.item_snapshots.*.item_kind' => 'required|in:stock,service', 'rules.*.role' => 'required|in:component,buy,get,target', 'rules.*.qty' => 'sometimes|string|max:20', 'rules.*.unit_snapshot' => 'sometimes|required|string|max:30', 'rules.*.pos_unit' => 'sometimes|required|string|max:20', 'rules.*.item_kind' => 'sometimes|in:stock,service', 'enabled' => 'required|boolean', 'discount_mode' => 'required|in:fixed,percent', 'discount_value' => 'required|string|max:20', 'minimum_spend' => 'required|string|max:20', 'maximum_discount' => 'nullable|string|max:20', 'starts_bs' => ['nullable', ...array_slice($this->business->dateRule(), 1)], 'ends_bs' => ['nullable', ...array_slice($this->business->dateRule(), 1)], 'starts_time' => 'nullable|string|date_format:H:i', 'ends_time' => 'nullable|string|date_format:H:i', 'weekdays' => 'sometimes|array|list|max:7', 'weekdays.*' => 'required|integer|between:0,6|distinct', 'cashier_allowed' => 'required|boolean']);
            $result = $this->service->save($actor, $input, $id);

            return response()->json(['data' => BusinessController::json($this->service->data($this->a->requireRow('basket_offers', $result['id'])))], $result['replayed'] ? 200 : 201);
        }
        if ($id) {
            $offer = $this->a->requireRow('basket_offers', $id);
            abort_if($this->a->role($actor) === 'cashier' && (! $offer->enabled || ! $offer->cashier_allowed), 403);

            return response()->json(['data' => BusinessController::json($this->service->data($offer))]);
        }
        $input = $r->validate(['enabled' => 'sometimes|boolean']);
        $q = $this->a->rows('basket_offers');
        if ($this->a->role($actor) === 'cashier') {
            $q->where('enabled', true)->where('cashier_allowed', true);
        } elseif (isset($input['enabled'])) {
            $q->where('enabled', $input['enabled']);
        }

        return response()->json(['data' => BusinessController::json($q->orderBy('name')->orderBy('id')->get()->map(fn ($row) => $this->service->data($row)))]);
    }
}
