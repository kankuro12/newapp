<?php

namespace App\Http\Controllers;

use App\Service\AccountingService;
use App\Service\CatalogService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public function __construct(private AccountingService $a, private CatalogService $catalog) {}

    public function categories(Request $r): JsonResponse
    {
        $input = $r->validate(['q' => 'nullable|string|max:100', 'all' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1']);
        $rows = $this->a->rows('item_categories')->when(empty($input['all']), fn ($q) => $q->whereNull('archived_at'))->when(! empty($input['q']), fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%'))->orderBy('name')->paginate(25);

        return response()->json(BusinessController::json($rows->toArray()));
    }

    public function category(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $this->catalog->manage(auth('tenant')->id());
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'name' => 'required|string|max:150', 'version' => $id ? 'required|integer|min:1' : 'prohibited', 'archived' => $id ? 'required|boolean' : 'prohibited']);
        $result = $this->catalog->category(auth('tenant')->id(), $input, $id);

        return response()->json(['data' => BusinessController::json($this->a->requireRow('item_categories', $result['id']))], $result['replayed'] ? 200 : 201);
    }

    public function reorders(Request $r): JsonResponse
    {
        $this->catalog->manage(auth('tenant')->id());
        $input = $r->validate(['q' => 'nullable|string|max:100', 'supplier_id' => 'nullable|integer|min:1', 'category_id' => 'nullable|integer|min:0', 'page' => 'sometimes|integer|min:1']);
        $query = DB::table('items')->where('items.tenant_id', app(CurrentTenant::class)->id())->where('items.kind', 'stock')->whereNull('items.archived_at')->leftJoin('inventory_balances as stock', fn ($j) => $j->on('stock.item_id', '=', 'items.id')->on('stock.tenant_id', '=', 'items.tenant_id'))->whereRaw('COALESCE(stock.qty_milli,0) <= items.low_stock_qty_milli')->select('items.*');
        $this->catalog->filterCategory($query, $input['category_id'] ?? null);
        if (! empty($input['supplier_id'])) {
            $this->a->requireRow('contacts', $input['supplier_id']);
            $query->where('items.preferred_supplier_id', $input['supplier_id']);
        }
        if (! empty($input['q'])) {
            $query->where(fn ($q) => $q->where('items.name', 'like', '%'.$input['q'].'%')->orWhere('items.sku', $input['q']));
        }
        $pending = $this->catalog->pending();
        $rows = $query->orderBy('items.name')->paginate(25);
        $rows->getCollection()->transform(fn ($item) => BusinessController::json($this->catalog->suggestion($item, $pending)));

        return response()->json($rows);
    }
}
