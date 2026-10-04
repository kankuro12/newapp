<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ImportService
{
    public const COLUMNS = [
        'contacts' => ['id', 'name', 'phone', 'email', 'address', 'pan', 'is_customer', 'is_supplier', 'is_employee', 'is_rent'],
        'items' => ['id', 'name', 'sku', 'kind', 'unit_label', 'sale_price', 'low_stock_qty', 'reorder_target_qty', 'category', 'preferred_supplier_id', 'preferred_supplier', 'pos_unit', 'pos_methods', 'pos_custom_units', 'service_minutes', 'default_tax_category', 'default_tax_bps', 'aliases'],
        'price_lists' => ['id', 'name', 'channel', 'pricing_scheme', 'enabled', 'adjustment_mode', 'adjustment_percent', 'starts_bs', 'ends_bs', 'item_id', 'item_sku', 'item_name', 'min_qty', 'price', 'unit_snapshot', 'pos_unit', 'item_kind'],
    ];

    public function __construct(private AccountingService $a, private CatalogService $catalog) {}

    private function identity(string $value): string
    {
        return DB::selectOne('select HEX(WEIGHT_STRING(CAST(? AS CHAR))) as identity', [$value])->identity;
    }

    public function input(string $resource, ?object $old = null, ?Tenant $tenant = null): array
    {
        if ($resource === 'items') {
            return ['aliases' => $old ? ($old->aliases ?? app(BarcodeService::class)->aliases((int) $old->id)) : [], ...$this->itemInput($old, $tenant)];
        }
        if ($resource === 'contacts') {
            return ['name' => $old?->name ?? '', 'phone' => $old?->phone, 'email' => $old?->email, 'address' => $old?->address, 'pan' => $old?->pan, 'is_customer' => $old ? (bool) $old->is_customer : true, 'is_supplier' => (bool) ($old?->is_supplier ?? false), 'is_employee' => (bool) ($old?->is_employee ?? false), 'is_rent' => (bool) ($old?->is_rent ?? false)];
        }

    }

    private function itemInput(?object $old, ?Tenant $tenant): array
    {
        $unit = match ($tenant?->pos_profile) {
            'meat' => 'kg','milk' => 'l','glass' => 'sq_ft','wood' => 'cu_ft',default => 'unit'
        };
        $kind = in_array($tenant?->pos_profile, ['barber', 'salon']) ? 'service' : 'stock';

        return ['name' => $old?->name ?? '', 'sku' => $old?->sku, 'kind' => $old?->kind ?? $kind, 'unit_label' => $old?->unit_label ?? $unit, 'sale_price' => Money::format((int) ($old?->sale_price_paisa ?? 0)), 'low_stock_qty' => Money::format((int) ($old?->low_stock_qty_milli ?? 0), 3), 'reorder_target_qty' => Money::format((int) ($old?->reorder_target_qty_milli ?? 0), 3), 'category_id' => $old?->category_id, 'preferred_supplier_id' => $old?->preferred_supplier_id, 'pos_unit' => $old?->pos_unit ?? $unit, 'pos_methods' => json_decode($old?->pos_methods ?? 'null', true) ?? PosService::METHODS, 'pos_custom_units' => json_decode($old?->pos_custom_units ?? '[]', true), 'service_minutes' => $old?->service_minutes ?? 30, 'default_tax_category' => $old?->default_tax_category ?? 'outside_scope', 'default_tax_bps' => $old?->default_tax_bps ?? 0];
    }

    private function parse(string $csv, int $limit = 500): array
    {
        if (strlen($csv) > 1048576 || ! mb_check_encoding($csv, 'UTF-8')) {
            $this->a->fail('Use UTF-8 CSV up to 1 MiB.', 'csv');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $records = [];
        $line = 1;
        try {
            while (! feof($stream)) {
                $start = ftell($stream);
                $cells = fgetcsv($stream, null, ',', '"', '');
                $raw = substr($csv, $start, ftell($stream) - $start);
                $row = $line;
                $line += substr_count($raw, "\n");
                if ($cells === false || trim($raw) === '') {
                    continue;
                }
                if (! preg_match('/^(?:"(?:[^"]|"")*"|[^",\r\n]*)(?:,(?:"(?:[^"]|"")*"|[^",\r\n]*))*\r?\n?$/D', $raw)) {
                    $this->a->fail('Malformed CSV quoting near row '.$row.'.', 'csv');
                }
                $records[] = ['row' => $row, 'cells' => $cells];
                if (count($records) > $limit + 1) {
                    $this->a->fail('Import at most '.$limit.' data rows per batch.', 'csv');
                }
            }
        } finally {
            fclose($stream);
        }
        if (count($records) < 2) {
            $this->a->fail('CSV needs a header and at least one data row.', 'csv');
        }
        $headers = array_map(fn ($value) => mb_strtolower(trim($value ?? '')), array_shift($records)['cells']);
        if (count($headers) > 100 || array_filter($headers, fn ($header) => mb_strlen($header) > 150)) {
            $this->a->fail('Use at most 100 columns with header names up to 150 characters.', 'csv');
        }
        if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
            $this->a->fail('CSV headers must be nonempty and unique.', 'csv');
        }
        foreach ($records as $record) {
            if (count($record['cells']) !== count($headers)) {
                $this->a->fail('Column count differs at row '.$record['row'].'.', 'csv');
            }
        }

        return [$headers, $records];
    }

    public function preview(int $actor, array $input): array
    {
        $this->catalog->manage($actor);

        return DB::transaction(function () use ($actor, $input) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $this->catalog->manage($actor);

            return $this->review($tenant, $input);
        });
    }

    private function source(array $input): array
    {
        $resource = $input['resource'];
        [$headers, $records] = $this->parse($input['csv'], $resource === 'price_lists' ? 1000 : 500);
        $columns = self::COLUMNS[$resource];
        $mapping = array_map(fn ($column) => $column ?? '', $input['mapping'] ?? $headers);
        $headerErrors = [];
        if (count($mapping) !== count($headers) || ! array_is_list($mapping)) {
            $this->a->fail('Column mapping must match the CSV header order.', 'mapping');
        }
        foreach ($mapping as $index => $column) {
            if (($column === '' && ! isset($input['mapping'])) || ($column !== '' && ! in_array($column, $columns, true))) {
                $headerErrors[] = ['column' => $headers[$index], 'message' => 'Choose a supported column or explicitly ignore it.'];
            }
        }
        $used = array_filter($mapping, fn ($column) => $column !== '');
        if (count(array_unique($used)) !== count($used)) {
            $headerErrors[] = ['column' => 'mapping', 'message' => 'Map each field once.'];
        }
        if (! $used) {
            $headerErrors[] = ['column' => 'mapping', 'message' => 'Choose at least one supported field.'];
        }

        return [$headers, $records, $columns, $mapping, $headerErrors];
    }

    private function review(Tenant $tenant, array $input): array
    {
        if ($input['resource'] === 'price_lists') {
            return $this->reviewPriceLists($tenant, $input);
        }
        $resource = $input['resource'];
        [$headers, $records, $columns, $mapping, $headerErrors] = $this->source($input);
        $rows = [];
        $categories = [];
        $seen = [];
        $seenCodes = [];
        $counts = ['create' => 0, 'update' => 0, 'categories' => 0];
        foreach ($records as $record) {
            $row = ['row' => $record['row'], 'id' => null, 'name' => '', 'action' => 'create', 'input' => [], 'changes' => [], 'errors' => [], 'category' => null];
            try {
                if ($headerErrors) {
                    throw ValidationException::withMessages(['mapping' => 'Correct column mapping before importing.']);
                }
                $cells = [];
                foreach ($mapping as $index => $column) {
                    if ($column !== '') {
                        $cells[$column] = trim($record['cells'][$index] ?? '');
                    }
                }
                if (array_key_exists('id', $cells) && $cells['id'] !== '') {
                    $id = Money::digits($cells['id']);
                    if (! preg_match('/^[1-9]\d{0,18}$/D', $id) || (string) (int) $id !== $id) {
                        throw ValidationException::withMessages(['id' => 'Use an existing positive record ID, or blank for new.']);
                    }
                    $row['id'] = (int) $id;
                    $row['action'] = 'update';
                }
                unset($cells['id']);
                $old = $row['id'] ? $this->a->requireRow($resource, $row['id']) : null;
                if ($old && ($old->archived_at || ($old->is_system ?? false))) {
                    throw ValidationException::withMessages(['id' => 'Archived and system records cannot be imported.']);
                }
                $values = $this->input($resource, $old, $tenant);
                $existing = $values;
                if ($old && $resource === 'items') {
                    $existing['category'] = $old->category_id ? $this->a->rows('item_categories')->where('id', $old->category_id)->value('name') : null;
                    $existing['preferred_supplier'] = $old->preferred_supplier_id ? $this->a->rows('contacts')->where('id', $old->preferred_supplier_id)->value('name') : null;
                }
                foreach ($cells as $key => &$value) {
                    if ($old && str_starts_with($value, "'") && isset($existing[$key]) && substr($value, 1) === $existing[$key]) {
                        $value = substr($value, 1);
                    }
                    if (str_starts_with($key, 'is_')) {
                        $flag = mb_strtolower(Money::digits($value));
                        if (! in_array($flag, ['1', '0', 'true', 'false', 'yes', 'no', 'y', 'n', ''], true)) {
                            throw ValidationException::withMessages([$key => 'Use 1/0, yes/no or true/false.']);
                        }
                        $value = in_array($flag, ['1', 'true', 'yes', 'y'], true);
                    } elseif (in_array($key, ['phone', 'email', 'address', 'pan', 'sku', 'preferred_supplier_id']) && $value === '') {
                        $value = null;
                    } elseif ($key === 'pos_methods') {
                        $value = explode('|', $value);
                    } elseif (in_array($key, ['pos_custom_units', 'aliases'])) {
                        $value = json_decode($value, true);
                        if (! is_array($value)) {
                            throw ValidationException::withMessages([$key => 'Use a JSON array. Custom units need label and qty; alternate codes need strings.']);
                        }
                    }
                }unset($value);
                if (array_key_exists('category', $cells)) {
                    $name = trim(preg_replace('/\s+/u', ' ', $cells['category']));
                    $category = $name !== '' ? $this->a->rows('item_categories')->where('name', $name)->first() : null;
                    if ($category?->archived_at && $old?->category_id != $category->id) {
                        throw ValidationException::withMessages(['category' => 'Choose an active category.']);
                    }
                    if (mb_strlen($name) > 150) {
                        throw ValidationException::withMessages(['category' => 'Category name exceeds 150 characters.']);
                    }
                    $values['category_id'] = $category?->id;
                    $row['category'] = $category?->name ?? ($name !== '' ? $name : null);
                    unset($cells['category']);
                }
                if (array_key_exists('preferred_supplier', $cells)) {
                    $name = $cells['preferred_supplier'];
                    $suppliers = $name !== '' ? $this->a->rows('contacts')->where('name', $name)->where('is_supplier', true)->where('is_system', false)->whereNull('archived_at')->get() : collect();
                    if ($name !== '' && $suppliers->count() !== 1) {
                        throw ValidationException::withMessages(['preferred_supplier' => 'Supplier name must match exactly one active supplier; use ID for ambiguous names.']);
                    }
                    $supplierId = $suppliers->first()?->id;
                    if (! empty($cells['preferred_supplier_id']) && $supplierId && $cells['preferred_supplier_id'] != $supplierId) {
                        throw ValidationException::withMessages(['preferred_supplier' => 'Supplier name and ID do not match.']);
                    }
                    if ($name !== '' || ! array_key_exists('preferred_supplier_id', $cells)) {
                        $cells['preferred_supplier_id'] = $supplierId;
                    }
                    unset($cells['preferred_supplier']);
                }
                if (! $old && $resource === 'items' && array_key_exists('unit_label', $cells) && ! array_key_exists('pos_unit', $cells)) {
                    $code = str_replace(' ', '_', mb_strtolower($cells['unit_label']));
                    $values['pos_unit'] = isset(PosService::UNITS[$code]) ? $code : 'unit';
                }
                $values = [...$values, ...$cells];
                $row['name'] = $values['name'];
                if ($resource === 'items') {
                    $hasSku = $values['sku'] !== null && $values['sku'] !== '';
                    if (! $hasSku && ! $old) {
                        throw ValidationException::withMessages(['sku' => 'New CSV items need a unique SKU.']);
                    }
                    $key = $hasSku ? 'sku:'.$this->identity($values['sku']) : 'id:'.$row['id'];
                    if ($hasSku && $this->a->rows('items')->where('sku', $values['sku'])->when($row['id'], fn ($q) => $q->where('id', '<>', $row['id']))->exists()) {
                        throw ValidationException::withMessages(['sku' => 'SKU exists; supply that item ID to update it.']);
                    }
                } else {
                    $key = 'party:'.$this->identity($values['name']).':'.$this->identity($values['phone'] ?? '');
                    if ($this->a->rows('contacts')->where('name', $values['name'])->where('phone', $values['phone'])->when($row['id'], fn ($q) => $q->where('id', '<>', $row['id']))->exists()) {
                        throw ValidationException::withMessages(['name' => 'Party name/phone already exists; supply ID to update it.']);
                    }
                }
                if (isset($seen[$key]) || ($row['id'] && isset($seen['id:'.$row['id']]))) {
                    throw ValidationException::withMessages(['id' => 'Duplicate record within this CSV batch.']);
                }
                $seen[$key] = true;
                if ($row['id']) {
                    $seen['id:'.$row['id']] = true;
                }
                $normalized = $this->catalog->masterValues($resource, $values, $row['id']);
                if ($resource === 'items') {
                    foreach ([...$normalized['aliases'], ...($hasSku ? [$values['sku']] : [])] as $code) {
                        $identity = $this->identity($code);
                        if (isset($seenCodes[$identity])) {
                            throw ValidationException::withMessages(['aliases' => 'Product code repeated within this CSV batch.']);
                        }
                        $seenCodes[$identity] = true;
                    }
                    $values['sale_price'] = Money::format($normalized['sale_price_paisa']);
                    $values['low_stock_qty'] = Money::format($normalized['low_stock_qty_milli'], 3);
                    $values['reorder_target_qty'] = Money::format($normalized['reorder_target_qty_milli'], 3);
                }
                $row['input'] = $values;
                $before = $this->input($resource, $old, $tenant);
                foreach ($values as $key => $value) {
                    $equal = is_array($value) ? $before[$key] === $value : (string) $before[$key] === (string) $value;
                    if (! $old || ! $equal) {
                        $beforeValue = $old ? $before[$key] : null;
                        if (str_ends_with($key, '_id')) {
                            $beforeValue = $beforeValue === null ? null : (string) $beforeValue;
                            $value = $value === null ? null : (string) $value;
                        }
                        $row['changes'][] = ['column' => $key, 'before' => $beforeValue, 'after' => $value];
                    }
                }
                $counts[$row['action']]++;
                if ($row['category'] !== null && ! $values['category_id']) {
                    $categories[$this->identity($row['category'])] = $row['category'];
                }
            } catch (ValidationException $error) {
                foreach ($error->errors() as $column => $messages) {
                    foreach ($messages as $message) {
                        $row['errors'][] = compact('column', 'message');
                    }
                }
            } catch (\InvalidArgumentException $error) {
                $row['errors'][] = ['column' => 'value', 'message' => $error->getMessage()];
            } catch (\Throwable $error) {
                if (! $error instanceof HttpExceptionInterface || ! in_array($error->getStatusCode(), [403, 404, 422])) {
                    throw $error;
                }
                $row['errors'][] = ['column' => 'reference', 'message' => 'Record unavailable or protected in this business.'];
            }
            $rows[] = $row;
        }
        $counts['categories'] = count($categories);
        $valid = ! $headerErrors && ! array_filter($rows, fn ($row) => $row['errors']);
        $digest = $valid ? hash('sha256', json_encode(['tenant_id' => $tenant->id, 'version' => $tenant->data_version, 'resource' => $resource, 'rows' => $rows, 'categories' => $categories], JSON_THROW_ON_ERROR)) : null;

        return compact('resource', 'headers', 'mapping', 'columns', 'headerErrors', 'rows', 'counts', 'valid', 'digest') + ['version' => (string) $tenant->data_version, 'categories' => array_values($categories), 'ignored' => array_values(array_filter($headers, fn ($header, $index) => $mapping[$index] === '', ARRAY_FILTER_USE_BOTH))];
    }

    private function recordId(string $value, string $field): int
    {
        $digits = Money::digits($value);
        if (! preg_match('/^[1-9]\d{0,18}$/D', $digits) || (string) (int) $digits !== $digits) {
            throw ValidationException::withMessages([$field => 'Use an existing positive ID.']);
        }

        return (int) $digits;
    }

    private function priceListInput(?int $id = null): array
    {
        if (! $id) {
            return ['name' => '', 'channel' => 'sale', 'pricing_scheme' => 'volume', 'enabled' => true, 'adjustment_mode' => 'increase', 'adjustment_percent' => '0.00', 'starts_bs' => null, 'ends_bs' => null, 'rules' => []];
        }
        $list = (object) app(PartyService::class)->priceListData($id);

        return ['version' => $list->version, 'name' => $list->name, 'channel' => $list->channel, 'pricing_scheme' => $list->pricing_scheme, 'enabled' => (bool) $list->enabled, 'adjustment_mode' => $list->adjustment_bps < 0 ? 'decrease' : 'increase', 'adjustment_percent' => Money::format(abs($list->adjustment_bps)), 'starts_bs' => $list->starts_bs, 'ends_bs' => $list->ends_bs, 'rules' => array_map(fn ($rule) => ['item_id' => $rule->item_id, 'item_name' => $rule->item_name, 'min_qty' => Money::format($rule->min_qty_milli, 3), 'price' => Money::format($rule->price_paisa), 'unit_snapshot' => $rule->unit_snapshot, 'pos_unit' => $rule->pos_unit, 'item_kind' => $rule->item_kind], $list->rules->all())];
    }

    private function priceItem(array $cells): ?object
    {
        $item = null;
        foreach (['item_id' => 'id', 'item_sku' => 'sku', 'item_name' => 'name'] as $field => $column) {
            if (($cells[$field] ?? '') === '') {
                continue;
            }
            $value = $field === 'item_id' ? $this->recordId($cells[$field], $field) : $cells[$field];
            if ($item && is_string($value) && str_starts_with($value, "'") && substr($value, 1) === $item->$column) {
                $value = substr($value, 1);
            }
            $matches = $this->a->rows('items')->where($column, $value)->whereNull('archived_at')->limit(2)->get();
            if ($matches->count() !== 1 || ($item && $item->id != $matches[0]->id)) {
                throw ValidationException::withMessages([$field => 'Item references must agree and match one active item in this business.']);
            }
            $item = $matches[0];
        }

        return $item;
    }

    private function reviewPriceLists(Tenant $tenant, array $input): array
    {
        [$headers, $records, $columns, $mapping, $headerErrors] = $this->source($input);
        $resource = 'price_lists';
        $rows = [];
        $groups = [];
        $originals = [];
        $counts = ['create' => 0, 'update' => 0, 'categories' => 0];
        foreach ($records as $record) {
            $row = ['row' => $record['row'], 'id' => null, 'name' => '', 'action' => 'create', 'category' => null, 'changes' => [], 'errors' => []];
            try {
                if ($headerErrors) {
                    throw ValidationException::withMessages(['mapping' => 'Correct column mapping before importing.']);
                }
                $cells = [];
                foreach ($mapping as $i => $column) {
                    if ($column !== '') {
                        $cells[$column] = trim($record['cells'][$i] ?? '');
                    }
                }
                $id = ($cells['id'] ?? '') !== '' ? $this->recordId($cells['id'], 'id') : null;
                $before = $id ? ($originals[$id] ??= $this->priceListInput($id)) : $this->priceListInput();
                $metadata = array_intersect_key($cells, array_flip(['name', 'channel', 'pricing_scheme', 'enabled', 'adjustment_mode', 'adjustment_percent', 'starts_bs', 'ends_bs']));
                if ($id && isset($metadata['name']) && str_starts_with($metadata['name'], "'") && substr($metadata['name'], 1) === $before['name']) {
                    $metadata['name'] = substr($metadata['name'], 1);
                }
                if (isset($metadata['enabled'])) {
                    $flag = mb_strtolower(Money::digits($metadata['enabled']));
                    if (! in_array($flag, ['1', '0', 'true', 'false', 'yes', 'no', 'y', 'n'], true)) {
                        throw ValidationException::withMessages(['enabled' => 'Use 1/0, yes/no or true/false.']);
                    }
                    $metadata['enabled'] = in_array($flag, ['1', 'true', 'yes', 'y'], true);
                }
                if (isset($metadata['adjustment_percent'])) {
                    $metadata['adjustment_percent'] = Money::format(Money::parse($metadata['adjustment_percent'], 2, 100000));
                }
                foreach (['starts_bs', 'ends_bs'] as $field) {
                    if (array_key_exists($field, $metadata)) {
                        $metadata[$field] = $metadata[$field] === '' ? null : NepaliDate::normalize($metadata[$field]);
                    }
                }
                if (! $id && (($metadata['name'] ?? '') === '' || ! in_array($metadata['channel'] ?? '', ['sale', 'purchase'], true))) {
                    throw ValidationException::withMessages(['name' => 'Each new-list row needs name and sale/purchase channel. Updates need list ID.']);
                }
                $key = $id ? 'id:'.$id : 'name:'.$this->identity($metadata['name']).':'.$metadata['channel'];
                if (! isset($groups[$key])) {
                    if (count($groups) >= 50) {
                        throw ValidationException::withMessages(['name' => 'Import at most 50 lists per batch.']);
                    }
                    $groups[$key] = ['id' => $id, 'first' => count($rows), 'before' => $before, 'input' => [...$before, 'rules' => empty($input['replace_rules']) ? $before['rules'] : []], 'supplied' => [], 'seen' => []];
                }
                $group = &$groups[$key];
                foreach ($metadata as $field => $value) {
                    if (array_key_exists($field, $group['supplied']) && $group['supplied'][$field] !== $value) {
                        throw ValidationException::withMessages([$field => 'Repeated list metadata must agree on every row.']);
                    }
                    $group['supplied'][$field] = $value;
                    $group['input'][$field] = $value;
                }
                $row['id'] = $id;
                $row['name'] = $group['input']['name'];
                $row['action'] = $id ? 'update' : 'create';
                $item = $this->priceItem($cells);
                if ($item) {
                    if (($cells['min_qty'] ?? '') === '' || ($cells['price'] ?? '') === '') {
                        throw ValidationException::withMessages(['price' => 'Item rows need minimum quantity and NPR price.']);
                    }
                    $qty = Money::format(Money::quantity($cells['min_qty']), 3);
                    $ruleKey = $item->id.':'.$qty;
                    if (isset($group['seen'][$ruleKey])) {
                        throw ValidationException::withMessages(['min_qty' => 'Duplicate item/minimum within this list.']);
                    }
                    $group['seen'][$ruleKey] = true;
                    $rule = ['item_id' => $item->id, 'item_name' => $item->name, 'min_qty' => $qty, 'price' => Money::format(Money::parse($cells['price'])), 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
                    $snapshot = array_intersect_key($cells, array_flip(['unit_snapshot', 'pos_unit', 'item_kind']));
                    if (array_filter($snapshot, fn ($v) => $v !== '')) {
                        foreach (['unit_snapshot', 'pos_unit', 'item_kind'] as $field) {
                            if (($snapshot[$field] ?? '') !== $rule[$field]) {
                                throw ValidationException::withMessages([$field => 'Item unit/kind changed. Review current item before importing tiers.']);
                            }
                        }
                    }
                    $group['input']['rules'] = array_values(array_filter($group['input']['rules'], fn ($old) => $old['item_id'] != $item->id || $old['min_qty'] !== $qty));
                    $group['input']['rules'][] = $rule;
                } elseif (array_filter(array_intersect_key($cells, array_flip(['min_qty', 'price', 'unit_snapshot', 'pos_unit', 'item_kind'])), fn ($v) => $v !== '')) {
                    throw ValidationException::withMessages(['item_id' => 'Choose an item for each price rule. Leave all rule cells blank for metadata only.']);
                }
                unset($group);
            } catch (\Throwable $error) {
                $row['errors'] = $this->priceErrors($error);
            }
            unset($group);
            $rows[] = $row;
        }
        $listNames = [];
        foreach ($groups as &$group) {
            try {
                // Same domain guards used by the guided editor; preview has no writes.
                app(PartyService::class)->priceListValues($group['input'], $group['id']);
                $identity = $this->identity($group['input']['name']).':'.$group['input']['channel'];
                if (isset($listNames[$identity])) {
                    throw ValidationException::withMessages(['name' => 'List names must be distinct within each channel.']);
                }
                $listNames[$identity] = true;
                foreach ($group['input'] as $field => $value) {
                    if ($field === 'version') {
                        continue;
                    }
                    if (! $group['id'] || $group['before'][$field] !== $value) {
                        $rows[$group['first']]['changes'][] = ['column' => $field, 'before' => $group['id'] ? $group['before'][$field] : null, 'after' => $value];
                    }
                }
                $counts[$group['id'] ? 'update' : 'create']++;
            } catch (\Throwable $error) {
                $rows[$group['first']]['errors'] = [...$rows[$group['first']]['errors'], ...$this->priceErrors($error)];
            }
        }
        unset($group);
        $valid = ! $headerErrors && ! array_filter($rows, fn ($row) => $row['errors']);
        $lists = array_values(array_map(fn ($group) => ['id' => $group['id'], 'input' => $group['input']], $groups));
        $digest = $valid ? hash('sha256', json_encode(['tenant_id' => $tenant->id, 'version' => $tenant->data_version, 'replace_rules' => (bool) ($input['replace_rules'] ?? false), 'rows' => $rows, 'lists' => $lists], JSON_THROW_ON_ERROR)) : null;

        return compact('resource', 'headers', 'mapping', 'columns', 'headerErrors', 'rows', 'counts', 'valid', 'digest', 'lists') + ['version' => (string) $tenant->data_version, 'categories' => [], 'ignored' => array_values(array_filter($headers, fn ($h, $i) => $mapping[$i] === '', ARRAY_FILTER_USE_BOTH))];
    }

    private function priceErrors(\Throwable $error): array
    {
        if ($error instanceof ValidationException) {
            $errors = [];
            foreach ($error->errors() as $column => $messages) {
                foreach ($messages as $message) {
                    $errors[] = compact('column', 'message');
                }
            }

            return $errors;
        }
        if ($error instanceof \InvalidArgumentException) {
            return [['column' => 'value', 'message' => $error->getMessage()]];
        }
        if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 404, 409, 422])) {
            return [['column' => 'reference', 'message' => $error->getStatusCode() === 409 ? $error->getMessage() : 'Record unavailable or protected in this business.']];
        }
        throw $error;
    }

    public static function csvCell(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return preg_match('/^[\s]*[=+@-]/u', $text) ? "'".$text : $text;
    }

    public function priceListExport(): \Generator
    {
        // Capture scoped builders before response streaming clears request tenant context.
        $lists = $this->a->rows('price_lists')->orderBy('id');
        $rates = $this->a->rows('price_list_rates');
        $items = $this->a->rows('items');

        return (function () use ($lists, $rates, $items) {
            foreach ($lists->cursor() as $list) {
                $values = ['id' => $list->id, 'name' => $list->name, 'channel' => $list->channel, 'pricing_scheme' => $list->pricing_scheme, 'enabled' => (bool) $list->enabled, 'adjustment_mode' => $list->adjustment_bps < 0 ? 'decrease' : 'increase', 'adjustment_percent' => Money::format(abs($list->adjustment_bps)), 'starts_bs' => $list->starts_bs, 'ends_bs' => $list->ends_bs];
                $rules = (clone $rates)->where('price_list_id', $list->id)->orderBy('item_id')->orderBy('min_qty_milli')->get();
                $names = (clone $items)->whereIn('id', $rules->pluck('item_id'))->get(['id', 'name', 'sku'])->keyBy('id');
                foreach ($rules->isEmpty() ? [null] : $rules as $rule) {
                    yield $values + ($rule ? ['item_id' => $rule->item_id, 'item_sku' => $names[$rule->item_id]->sku, 'item_name' => $names[$rule->item_id]->name, 'min_qty' => Money::format($rule->min_qty_milli, 3), 'price' => Money::format($rule->price_paisa), 'unit_snapshot' => $rule->unit_snapshot, 'pos_unit' => $rule->pos_unit, 'item_kind' => $rule->item_kind] : []);
                }
            }
        })();
    }

    public function apply(int $actor, array $input): array
    {
        $this->catalog->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'imports.apply', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->catalog->manage($actor);
            abort_unless((string) $tenant->data_version === $input['version'], 409, 'Business data changed. Preview this CSV again before applying.');
            $review = $this->review($tenant, $input);
            if (! $review['valid']) {
                $this->a->fail('Fix all CSV errors and preview again.', 'csv');
            }
            abort_unless(hash_equals($review['digest'], $input['digest']), 409, 'Business data changed. Preview this CSV again before applying.');
            $categoryIds = [];
            foreach ($review['categories'] as $name) {
                $result = $this->catalog->category($actor, ['name' => $name, 'mutation_uuid' => (string) Str::uuid()]);
                $categoryIds[$this->identity($name)] = $result['id'];
            }
            // ponytail: bounded 500-row synchronous batch reuses master guards/audit; queued chunks only if measured import latency requires them.
            if ($input['resource'] === 'price_lists') {
                foreach ($review['lists'] as $list) {
                    app(PartyService::class)->savePriceList($actor, [...$list['input'], 'mutation_uuid' => (string) Str::uuid()], $list['id']);
                }
            } else {
                foreach ($review['rows'] as $row) {
                    $values = $row['input'];
                    if ($row['category'] !== null && ! $values['category_id']) {
                        $values['category_id'] = $categoryIds[$this->identity($row['category'])];
                    }
                    $this->catalog->saveMaster($actor, $input['resource'], $values, $row['id']);
                }
            }
            $id = DB::table('master_import_batches')->insertGetId(['tenant_id' => $tenant->id, 'created_by' => $actor, 'resource' => $input['resource'], 'created_count' => $review['counts']['create'], 'updated_count' => $review['counts']['update'], 'category_count' => $review['counts']['categories'], 'created_at' => now()]);

            return ['table' => 'master_import_batches', 'id' => $id];
        });
    }
}
