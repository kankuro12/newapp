<?php

namespace App\Service;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BarcodeService
{
    public function __construct(private AccountingService $a) {}

    public function aliases(int $id): array
    {
        return $this->a->rows('item_codes')->where('item_id', $id)->orderBy('id')->pluck('code')->all();
    }

    public function labelItems(int $actor, array $input): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'cashier', 'accountant']);
        if (! isset($input['category_id']) && ! isset($input['supplier_id'])) {
            $this->a->fail('Choose category or supplier before adding matching items.');
        }
        if (isset($input['supplier_id'])) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $supplier = $this->a->requireRow('contacts', $input['supplier_id']);
            if (! $supplier->is_supplier || $supplier->archived_at) {
                $this->a->fail('Choose active supplier.');
            }
        }
        $query = $this->a->rows('items')->select('id', 'name', 'sku', 'unit_label', 'sale_price_paisa')->whereNull('archived_at');
        app(CatalogService::class)->filterCategory($query, $input['category_id'] ?? null);
        $items = $query->when(isset($input['supplier_id']), fn ($q) => $q->where('preferred_supplier_id', $input['supplier_id']))->orderBy('name')->limit(101)->get();
        if ($items->count() > 100) {
            $this->a->fail('More than 100 matching items. Refine category / supplier or choose items individually.');
        }

        return $items->map(fn ($item) => [...(array) $item, 'aliases' => $this->aliases((int) $item->id)])->all();
    }

    public function labels(int $actor, array $input): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'cashier', 'accountant']);

        return DB::transaction(function () use ($actor, $input) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor, false);
            $this->a->authorize($actor, ['owner', 'manager', 'cashier', 'accountant']);
            $rows = [];
            $copies = 0;
            foreach ($input['rows'] as $index => $row) {
                $item = $this->a->requireRow('items', $row['item_id']);
                $code = $row['code'];
                if ($item->archived_at || ($code !== null && (! preg_match('/^[ -~]+$/D', $code) || ! in_array($code, [$item->sku, ...$this->aliases((int) $item->id)], true)))) {
                    $this->a->fail('Choose an active item and its current SKU / alternate code, or no barcode.', 'rows.'.$index.'.code');
                }
                $copies += (int) $row['copies'];
                if ($copies > 1000) {
                    $this->a->fail('Print at most 1000 labels per batch.');
                }
                $price = (int) $item->sale_price_paisa;
                $tax = $tenant->tax_recording_enabled && $item->default_tax_category === 'standard' ? Money::multiplyDivide($price, (int) $item->default_tax_bps, 10000) : 0;
                $rows[] = ['item_id' => $item->id, 'name' => $item->name, 'code' => $code, 'copies' => (int) $row['copies'], 'unit_label' => $item->unit_label, 'price_paisa' => $price + ($input['include_tax'] ? $tax : 0), 'tax_paisa' => $tax];
            }

            return ['rows' => $rows, 'version' => (string) $tenant->data_version];
        });
    }

    public function validateCodes(array $values, ?int $id): void
    {
        $codes = [...($values['aliases'] ?? ($id ? $this->aliases($id) : [])), ...(($values['sku'] ?? '') !== '' ? [$values['sku']] : [])];
        $seen = [];
        foreach ($codes as $code) {
            $key = DB::selectOne('select HEX(WEIGHT_STRING(CAST(? AS CHAR))) as identity', [$code])->identity;
            if (isset($seen[$key]) || $this->a->rows('items')->where('sku', $code)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists() || $this->a->rows('item_codes')->where('code', $code)->when($id, fn ($q) => $q->where('item_id', '<>', $id))->exists()) {
                $this->a->fail('Product code already used. Keep each SKU and alternate code unique in this branch.', 'aliases');
            }
            $seen[$key] = true;
        }
    }

    public function saveAliases(int $id, array $codes): void
    {
        $this->a->rows('item_codes')->where('item_id', $id)->delete();
        foreach ($codes as $code) {
            DB::table('item_codes')->insert(['tenant_id' => app(CurrentTenant::class)->id(), 'item_id' => $id, 'code' => $code]);
        }
    }

    public function config(): array
    {
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());

        return ['rules' => json_decode($tenant->barcode_rules ?? '[]', true), 'version' => (string) $tenant->data_version];
    }

    public function saveItem(int $actor, int $id, array $input): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'barcodes.item.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            abort_unless((string) $tenant->data_version === $input['version'], 409, 'Business changed. Reload item codes before saving.');
            $item = $this->a->requireRow('items', $id);
            if ($item->archived_at) {
                $this->a->fail('Restore archived item before changing its codes.');
            }
            $values = app(ImportService::class)->input('items', $item);
            app(CatalogService::class)->saveMaster($actor, 'items', [...$values, 'aliases' => $input['aliases']], $id);

            return ['table' => 'items', 'id' => $id];
        });
    }

    public function configure(int $actor, array $input): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'barcodes.config', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            abort_unless((string) $tenant->data_version === $input['version'], 409, 'Business changed. Reload barcode settings before saving.');
            $rules = array_map(fn ($rule) => [...$rule, 'total_length' => (int) $rule['total_length'], 'product_digits' => (int) $rule['product_digits'], 'value_digits' => (int) $rule['value_digits'], 'decimals' => (int) $rule['decimals']], $input['rules']);
            foreach ($rules as $i => $rule) {
                if (strlen($rule['prefix']) + $rule['product_digits'] + $rule['value_digits'] + 1 !== $rule['total_length'] || ($rule['mode'] === 'amount' && $rule['decimals'] > 2)) {
                    $this->a->fail('Digits must fill the code before its checksum. NPR amounts allow at most two decimals.', 'rules.'.$i);
                }
                foreach (array_slice($rules, 0, $i) as $previous) {
                    if ($previous['total_length'] === $rule['total_length'] && (str_starts_with($rule['prefix'], $previous['prefix']) || str_starts_with($previous['prefix'], $rule['prefix']))) {
                        $this->a->fail('Prefixes overlap for the same barcode length.', 'rules.'.$i.'.prefix');
                    }
                }
            }
            $tenant->update(['barcode_rules' => json_encode($rules)]);

            return ['table' => 'tenants', 'id' => $tenant->id];
        });
    }

    private function item(string $code): ?object
    {
        $matches = $this->a->rows('items')->where(fn ($q) => $q->where('sku', $code)->orWhereIn('id', $this->a->rows('item_codes')->where('code', $code)->select('item_id')))->limit(2)->get();
        if ($matches->count() > 1) {
            $this->a->fail('Ambiguous product code. Correct item setup.', 'code');
        }
        $item = $matches->first();
        if ($item?->archived_at) {
            $this->a->fail('Product code belongs to an archived item.', 'code');
        }

        return $item;
    }

    public function resolve(string $code, ?int $contact = null, ?int $listId = null, ?int $date = null): array
    {
        $item = $this->item($code);
        $rule = null;
        $m = ['mode' => 'quantity', 'value' => '1'];
        if (! $item) {
            foreach ($this->config()['rules'] as $candidate) {
                if (strlen($code) === $candidate['total_length'] && str_starts_with($code, $candidate['prefix'])) {
                    $rule = $candidate;
                    break;
                }
            }
            if (! $rule || ! preg_match('/^\d{12,13}$/D', $code)) {
                $this->a->fail('Code not found. Check product codes or enabled scale format.', 'code');
            }
            $sum = 0;
            for ($i = strlen($code) - 2, $weight = 3; $i >= 0; $i--, $weight = 4 - $weight) {
                $sum += (int) $code[$i] * $weight;
            }
            if ((10 - $sum % 10) % 10 !== (int) substr($code, -1)) {
                $this->a->fail('Barcode checksum invalid. Scan or enter the full label again.', 'code');
            }
            $product = substr($code, strlen($rule['prefix']), $rule['product_digits']);
            $item = $this->item($product);
            if (! $item) {
                $this->a->fail('Scale product code not found in this branch.', 'code');
            }
            $raw = (int) substr($code, strlen($rule['prefix']) + $rule['product_digits'], $rule['value_digits']);
            if ($raw === 0) {
                $this->a->fail('Encoded quantity or amount must be positive.', 'code');
            }
            $m = ['mode' => $rule['mode'], 'value' => Money::format($raw, $rule['decimals'])];
            if ($rule['mode'] === 'quantity') {
                $m['unit'] = $rule['unit'];
            }
        }
        if ($contact) {
            $party = $this->a->requireRow('contacts', $contact);
            if (! $party->is_customer || $party->archived_at) {
                $this->a->fail('Choose active customer.', 'contact_id');
            }
        }
        $item->sale_price_paisa = app(PartyService::class)->rate($contact ?? 0, $item, 'sale', 0, $listId, $date) ?? $item->sale_price_paisa;
        $m += ['barcode' => $code, 'barcode_fingerprint' => hash('sha256', json_encode([$item->id, $item->pos_unit, $rule], JSON_THROW_ON_ERROR))];
        app(PosService::class)->measure($item, $m);

        return ['item_id' => $item->id, 'measurement' => $m, 'rule' => $rule];
    }
}
