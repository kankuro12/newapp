<?php

namespace App\Http\Controllers;

use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\BarcodeService;
use App\Service\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BarcodeController extends Controller
{
    public function __construct(private BarcodeService $barcodes, private AccountingService $a) {}

    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->barcodes->config()]);
    }

    public function labelItems(Request $r): JsonResponse
    {
        $input = $r->validate(['category_id' => 'nullable|integer|min:0', 'supplier_id' => 'nullable|integer|min:1']);

        return response()->json(['data' => BusinessController::json($this->barcodes->labelItems(auth('tenant')->id(), $input))]);
    }

    public function labels(Request $r): JsonResponse
    {
        $input = $r->validate(['include_tax' => 'required|boolean', 'rows' => 'required|array|list|min:1|max:100', 'rows.*' => 'required|array:item_id,code,copies', 'rows.*.item_id' => 'required|integer|min:1', 'rows.*.code' => 'present|nullable|string|max:100', 'rows.*.copies' => 'required|integer|min:1|max:1000']);

        return response()->json(['data' => BusinessController::json($this->barcodes->labels(auth('tenant')->id(), $input))]);
    }

    public function configure(Request $r): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|string|regex:/^\d{1,20}$/', 'rules' => 'present|array|list|max:20', 'rules.*' => 'required|array:name,prefix,total_length,product_digits,value_digits,decimals,mode,unit', 'rules.*.name' => 'required|string|max:60', 'rules.*.prefix' => 'required|string|regex:/^\d{1,6}$/', 'rules.*.total_length' => 'required|integer|in:12,13', 'rules.*.product_digits' => 'required|integer|min:1|max:9', 'rules.*.value_digits' => 'required|integer|min:1|max:9', 'rules.*.decimals' => 'required|integer|min:0|max:3', 'rules.*.mode' => 'required|in:quantity,amount', 'rules.*.unit' => ['required', Rule::in(array_keys(PosService::UNITS))]]);
        $result = $this->barcodes->configure($actor, $input);

        return response()->json(['data' => $this->barcodes->config()], $result['replayed'] ? 200 : 201);
    }

    public function scan(Request $r): JsonResponse
    {
        $input = $r->validate(['code' => 'required|string|max:100', 'contact_id' => 'nullable|integer|min:1', 'price_list_id' => 'nullable|integer|min:1', 'business_date_bs' => ['sometimes', ...app(BusinessController::class)->dateRule()]]);

        return response()->json(['data' => BusinessController::json($this->barcodes->resolve($input['code'], $input['contact_id'] ?? null, $input['price_list_id'] ?? null, isset($input['business_date_bs']) ? NepaliDate::normalize($input['business_date_bs']) : null))]);
    }

    public function item(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|string|regex:/^\d{1,20}$/', 'aliases' => 'present|array|list|max:20', 'aliases.*' => ['required', 'string', 'max:100', 'regex:/^[ -~]+$/D']]);
        $result = $this->barcodes->saveItem($actor, $id, $input);

        return response()->json(['data' => ['aliases' => $this->barcodes->aliases($id), 'version' => $this->barcodes->config()['version']]], $result['replayed'] ? 200 : 201);
    }
}
