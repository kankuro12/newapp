<?php

namespace App\Http\Controllers;

use App\Service\AccountingService;
use App\Service\CatalogService;
use App\Service\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private AccountingService $a, private CatalogService $catalog, private ImportService $imports) {}

    private function rules(): array
    {
        return ['resource' => 'required|in:contacts,items,price_lists', 'csv' => 'required|string|max:1048576', 'mapping' => 'sometimes|array|max:100', 'mapping.*' => 'nullable|string|max:50', 'replace_rules' => 'sometimes|boolean'];
    }

    public function preview(Request $r): JsonResponse
    {
        $this->catalog->manage(auth('tenant')->id());
        $input = $r->validate($this->rules());

        return response()->json(['data' => BusinessController::json($this->imports->preview(auth('tenant')->id(), $input))]);
    }

    public function apply(Request $r): JsonResponse
    {
        $this->catalog->manage(auth('tenant')->id());
        $input = $r->validate([...$this->rules(), 'digest' => 'required|string|size:64', 'version' => 'required|string|regex:/^\d{1,20}$/', 'mutation_uuid' => 'required|uuid']);
        $result = $this->imports->apply(auth('tenant')->id(), $input);

        return response()->json(['data' => BusinessController::json($this->a->requireRow('master_import_batches', $result['id']))], $result['replayed'] ? 200 : 201);
    }

    public function listing(): JsonResponse
    {
        $this->catalog->manage(auth('tenant')->id());

        return response()->json(BusinessController::json($this->a->rows('master_import_batches')->orderByDesc('id')->paginate(25)->toArray()));
    }

    public function export(string $tenant, string $resource, string $mode): StreamedResponse
    {
        $this->catalog->manage(auth('tenant')->id());
        abort_unless(isset(ImportService::COLUMNS[$resource]) && in_array($mode, ['template', 'export']), 404);
        $columns = ImportService::COLUMNS[$resource];
        if ($resource === 'price_lists') {
            $rows = $mode === 'export' ? $this->imports->priceListExport() : [];

            return response()->streamDownload(function () use ($columns, $rows) {
                $file = fopen('php://output', 'w');
                fwrite($file, "\xEF\xBB\xBF");
                fputcsv($file, $columns, ',', '"', '');
                foreach ($rows as $values) {
                    fputcsv($file, array_map(fn ($key) => ImportService::csvCell($values[$key] ?? ''), $columns), ',', '"', '');
                }
                fclose($file);
            }, $resource.'-'.$mode.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $codeQuery = $this->a->rows('item_codes');
        $query = $this->a->rows($resource)->whereNull('archived_at')->when($resource === 'contacts', fn ($q) => $q->where('is_system', false))->orderBy('id');
        $categories = $this->a->rows('item_categories')->pluck('name', 'id');
        $suppliers = $this->a->rows('contacts')->where('is_supplier', true)->whereNull('archived_at')->pluck('name', 'id');

        return response()->streamDownload(function () use ($columns, $query, $mode, $resource, $categories, $suppliers, $codeQuery) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns, ',', '"', '');
            if ($mode === 'export') {
                foreach ($query->cursor() as $row) {
                    if ($resource === 'items') {
                        $row->aliases = (clone $codeQuery)->where('item_id', $row->id)->orderBy('id')->pluck('code')->all();
                    }
                    $values = $this->imports->input($resource, $row) + ['id' => $row->id];
                    if ($resource === 'items') {
                        $values['category'] = $row->category_id ? ($categories[$row->category_id] ?? '') : '';
                        $values['preferred_supplier'] = $row->preferred_supplier_id ? ($suppliers[$row->preferred_supplier_id] ?? '') : '';
                    }
                    fputcsv($file, array_map(function ($key) use ($values) {
                        $value = $values[$key] ?? '';
                        if ($key === 'pos_methods') {
                            $value = implode('|', $value);
                        } elseif (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        } elseif (is_bool($value)) {
                            $value = $value ? '1' : '0';
                        }

                        return ImportService::csvCell($value);
                    }, $columns), ',', '"', '');
                }
            }
            fclose($file);
        }, $resource.'-'.$mode.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
