<?php

namespace App\Service;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CatalogService
{
    public function __construct(private AccountingService $a, private PartyService $parties) {}

    public static function masterRules(string $resource): array
    {
        $rules = match ($resource) {
            'contacts' => ['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:500', 'pan' => 'nullable|string|max:30', 'is_customer' => 'required|boolean', 'is_supplier' => 'required|boolean', 'is_employee' => 'sometimes|boolean', 'is_rent' => 'sometimes|boolean'],
            'items' => ['name' => 'required|string|max:150', 'sku' => 'nullable|string|max:100', 'kind' => 'required|in:stock,service', 'unit_label' => 'required|string|max:30', 'sale_price' => 'required|string|max:20', 'pos_unit' => ['sometimes', Rule::in(array_keys(PosService::UNITS))], 'pos_methods' => 'sometimes|array|min:1|max:6', 'pos_methods.*' => ['required', Rule::in(PosService::METHODS)], 'pos_custom_units' => 'sometimes|array|max:50', 'pos_custom_units.*.label' => 'required|string|max:50', 'pos_custom_units.*.qty' => 'required|string|max:20', 'service_minutes' => 'sometimes|integer|min:1|max:720', 'category_id' => 'nullable|integer|min:1', 'preferred_supplier_id' => 'nullable|integer|min:1', 'reorder_target_qty' => 'sometimes|string|max:20', 'low_stock_qty' => 'sometimes|string|max:20', 'default_tax_bps' => 'sometimes|integer|min:0|max:10000', 'default_tax_category' => 'sometimes|in:standard,zero,exempt,outside_scope'],
            'accounts' => ['name' => 'required|string|max:150', 'money_kind' => 'required|in:cash,bank'],
            'expense-categories' => ['name' => 'required|string|max:150'],default => abort(404),
        };

        return $resource === 'items' ? [...$rules, 'aliases' => 'sometimes|array|list|max:20', 'aliases.*' => ['required', 'string', 'max:100', 'regex:/^[ -~]+$/D']] : $rules;
    }

    public function masterValues(string $resource, array $input, ?int $id = null): array
    {
        $input = Validator::make($input, self::masterRules($resource))->validate();
        $old = $id ? $this->a->requireRow($resource, $id) : null;
        if ($resource === 'contacts') {
            if (! $input['is_customer'] && ! $input['is_supplier'] && ! ($input['is_employee'] ?? false) && ! ($input['is_rent'] ?? false)) {
                $this->a->fail('Choose at least one party role.');
            }
            if ($old) {
                abort_if($old->is_system, 403);
                $next = (object) [...(array) $old, ...$input];
                if ((! $next->is_customer && $this->a->balance($this->a->account('receivables'), $id)) || (! $this->a->payableParty($next) && $this->a->balance($this->a->account('payables'), $id))) {
                    $this->a->fail('Keep party role while its money channel has outstanding balance.');
                }
            }
        }
        if ($resource === 'items') {
            app(BarcodeService::class)->validateCodes([...$input, 'sku' => array_key_exists('sku', $input) ? $input['sku'] : $old?->sku], $id);
            $input['sale_price_paisa'] = Money::parse($input['sale_price']);
            $input['low_stock_qty_milli'] = Money::quantity($input['low_stock_qty'] ?? '0');
            if (isset($input['reorder_target_qty'])) {
                $input['reorder_target_qty_milli'] = Money::quantity($input['reorder_target_qty']);
            }
            unset($input['sale_price'],$input['low_stock_qty'],$input['reorder_target_qty']);
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
            $this->itemSettings($input, $old);
            if ($old && ($old->kind !== $input['kind'] || $old->unit_label !== $input['unit_label'] || (isset($input['pos_unit']) && $old->pos_unit !== $input['pos_unit'])) && $this->a->rows('stock_movements')->where('item_id', $id)->exists()) {
                $this->a->fail('Unit/kind cannot change after stock activity.');
            }
        }

        return $input;
    }

    public function saveMaster(int $actor, string $resource, array $input, ?int $id = null): int
    {
        $roles = $resource === 'contacts' ? ['owner', 'manager', 'cashier', 'accountant'] : ($resource === 'items' ? ['owner', 'manager', 'accountant'] : ['owner']);
        $this->a->authorize($actor, $roles);

        return DB::transaction(function () use ($actor, $resource, $input, $id, $roles) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $this->a->authorize($actor, $roles);
            $table = $resource === 'expense-categories' ? 'expense_categories' : $resource;
            $values = $this->masterValues($resource, $input, $id);
            $aliases = $values['aliases'] ?? null;
            unset($values['aliases']);
            if ($id) {
                abort_unless(in_array($resource, ['items', 'contacts']), 403);
                $this->a->rows($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);
                $rowId = $id;
            } elseif (in_array($resource, ['accounts', 'expense-categories'])) {
                $account = DB::table('accounts')->insertGetId(['tenant_id' => $tenant->id, 'code' => (string) (100000 + (int) $this->a->rows('accounts')->max('id')), 'name' => $values['name'], 'category' => $resource === 'accounts' ? 'asset' : 'expense', 'normal_side' => 'dr', 'is_money' => $resource === 'accounts', 'money_kind' => $resource === 'accounts' ? $values['money_kind'] : null, 'created_at' => now(), 'updated_at' => now()]);
                $rowId = $resource === 'accounts' ? $account : DB::table($table)->insertGetId(['tenant_id' => $tenant->id, 'name' => $values['name'], 'account_id' => $account, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $rowId = DB::table($table)->insertGetId(['tenant_id' => $tenant->id, ...$values, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($resource === 'items' && $aliases !== null) {
                app(BarcodeService::class)->saveAliases($rowId, $aliases);
            }
            $tenant->increment('data_version');
            $this->a->audit($actor, $resource.'.saved', $table, $rowId);

            return $rowId;
        }, 3);
    }

    public function manage(int $actor): void
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
    }

    public function category(int $actor, array $input, ?int $id = null): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'catalog.category.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $this->manage($actor);
            $old = $id ? $this->a->requireRow('item_categories', $id) : null;
            if ($old) {
                abort_unless($old->version == $input['version'], 409, 'Category changed. Reload before saving.');
            }
            $name = trim(preg_replace('/\s+/u', ' ', $input['name']));
            if ($name === '' || $this->a->rows('item_categories')->where('name', $name)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->a->fail('Choose a unique category name.', 'name');
            }
            $values = ['name' => $name, 'archived_at' => ($input['archived'] ?? false) ? ($old?->archived_at ?? now()) : null, 'updated_at' => now()];
            if ($old) {
                $this->a->rows('item_categories')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
            } else {
                $id = DB::table('item_categories')->insertGetId(['tenant_id' => $tenant->id, 'created_at' => now(), ...$values]);
            }

            return ['table' => 'item_categories', 'id' => $id];
        });
    }

    public function itemSettings(array $input, ?object $old): void
    {
        if (! empty($input['category_id'])) {
            $category = $this->a->requireRow('item_categories', $input['category_id']);
            if ($category->archived_at && $old?->category_id != $category->id) {
                $this->a->fail('Choose an active category.', 'category_id');
            }
        }
        if (! empty($input['preferred_supplier_id'])) {
            $supplier = $this->a->requireRow('contacts', $input['preferred_supplier_id']);
            if (($supplier->archived_at || ! $supplier->is_supplier || $supplier->is_system) && $old?->preferred_supplier_id != $supplier->id) {
                $this->a->fail('Choose an active supplier.', 'preferred_supplier_id');
            }
        }
        $target = (int) ($input['reorder_target_qty_milli'] ?? $old?->reorder_target_qty_milli ?? 0);
        $low = (int) ($input['low_stock_qty_milli'] ?? $old?->low_stock_qty_milli ?? 0);
        if ($target && ($target < $low || $input['kind'] !== 'stock')) {
            $this->a->fail('Target stock must cover low-stock quantity and use a stock item.', 'reorder_target_qty');
        }
    }

    public function filterCategory(Builder $query, ?int $category): void
    {
        if ($category === null) {
            return;
        }
        if ($category) {
            $this->a->requireRow('item_categories', $category);
            $query->where('items.category_id', $category);
        } else {
            $query->whereNull('items.category_id');
        }
    }

    public function pending(): array
    {
        $quantities = [];
        // ponytail: scan open PO snapshots; relational workflow lines if measured backlog size needs indexed aggregation.
        foreach ($this->a->rows('business_workflows')->where('kind', 'purchase_order')->whereNull('document_id')->whereIn('status', ['open', 'in_progress', 'ready', 'fulfilled'])->get(['lines']) as $order) {
            foreach (json_decode($order->lines, true) as $line) {
                $id = (int) $line['item_id'];
                $quantities[$id] = Money::checked(bcadd((string) ($quantities[$id] ?? 0), (string) $line['qty_milli']));
            }
        }

        return $quantities;
    }

    public function suggestion(object $item, array $pending): array
    {
        $qty = (int) ($this->a->rows('inventory_balances')->where('item_id', $item->id)->value('qty_milli') ?? 0);
        $supplier = $item->preferred_supplier_id ? $this->a->requireRow('contacts', $item->preferred_supplier_id) : null;
        $active = $supplier && $supplier->is_supplier && ! $supplier->is_system && ! $supplier->archived_at;
        $price = $active ? $this->parties->rate((int) $supplier->id, $item, 'purchase') : null;
        $price ??= (int) ($item->last_purchase_price_paisa ?? $item->sale_price_paisa);
        $tax = Tenant::findOrFail(app(CurrentTenant::class)->id())->tax_recording_enabled;
        $state = ['id' => (int) $item->id, 'name' => $item->name, 'kind' => $item->kind, 'unit_label' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'preferred_supplier_id' => $item->preferred_supplier_id, 'supplier_name' => $supplier?->name, 'supplier_active' => (bool) $active, 'qty_milli' => $qty, 'low_stock_qty_milli' => (int) $item->low_stock_qty_milli, 'reorder_target_qty_milli' => (int) $item->reorder_target_qty_milli, 'pending_qty_milli' => $pending[$item->id] ?? 0, 'unit_price_paisa' => $price, 'default_tax_category' => $tax ? $item->default_tax_category : 'outside_scope', 'default_tax_bps' => $tax ? (int) $item->default_tax_bps : 0];

        $suggested = bcsub(bcsub((string) $state['reorder_target_qty_milli'], (string) $qty), (string) $state['pending_qty_milli']);

        return [...$state, 'suggested_qty_milli' => bccomp($suggested, '0') > 0 ? Money::checked($suggested) : 0, 'fingerprint' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR))];
    }

    public function assertReorder(array $input): void
    {
        if (empty($input['contact_id'])) {
            $this->a->fail('Choose a supplier.', 'contact_id');
        }
        foreach (array_keys($input['reorder']) as $key) {
            if (! ctype_digit((string) $key) || (string) (int) $key !== (string) $key) {
                $this->a->fail('Reorder item identifiers are invalid.', 'reorder');
            }
        }
        $ids = array_map('intval', array_column($input['lines'], 'item_id'));
        $keys = array_map('intval', array_keys($input['reorder']));
        sort($ids);
        sort($keys);
        if ($input['kind'] !== 'purchase_order' || $ids !== $keys) {
            $this->a->fail('Reorder must match selected purchase-order items.', 'reorder');
        }
        $pending = $this->pending();
        foreach ($ids as $id) {
            $item = $this->a->requireRow('items', $id);
            $row = $this->suggestion($item, $pending);
            abort_unless(! $item->archived_at && $item->kind === 'stock' && $row['supplier_active'] && $item->preferred_supplier_id == $input['contact_id'] && $row['suggested_qty_milli'] > 0 && $row['qty_milli'] <= $row['low_stock_qty_milli'] && hash_equals($row['fingerprint'], $input['reorder'][$id]), 409, 'Stock, supplier, price or open orders changed. Refresh reorder suggestions and review again.');
        }
    }
}
