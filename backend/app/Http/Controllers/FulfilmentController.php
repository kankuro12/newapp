<?php

namespace App\Http\Controllers;

use App\Service\FulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfilmentController extends Controller
{
    public function __construct(private FulfilmentService $service, private BusinessController $business) {}

    private function rules(): array
    {
        return ['version' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'source_id' => 'nullable|integer|min:1', 'vat_recoverable' => 'sometimes|boolean', 'reference' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:position,qty', 'lines.*.position' => 'required|integer|min:1|max:100|distinct', 'lines.*.qty' => 'required|string|max:20'];
    }

    private function result(array $result): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->data(auth('tenant')->id(), $result['id']))], $result['replayed'] ? 200 : 201);
    }

    public function show(string $tenant, int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->data(auth('tenant')->id(), $id))]);
    }

    public function preview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->rules());

        return response()->json(['data' => BusinessController::json($this->service->preview(auth('tenant')->id(), $id, $input))]);
    }

    public function save(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->rules(), 'mutation_uuid' => 'required|uuid', 'expected_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);

        return $this->result($this->service->save(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function cancel(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'workflow_version' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'reason' => 'required|string|min:3|max:500']);

        return $this->result($this->service->cancel(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    private function billRules(bool $ordered = false): array
    {
        $terms = array_intersect_key($this->business->documentRules(), array_flip(['business_date_bs', 'due_date_bs', 'money_account_id', 'supplier_bill_number', 'supplier_bill_date_bs', 'overdraft_confirmed', 'vat_recoverable', 'notes']));

        $source = $ordered ? 'position' : 'fulfilment_line_id';

        return [...$terms, 'version' => 'required|integer|min:1', 'paid_now' => 'required|string|max:20', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:'.$source.',qty', 'lines.*.'.$source => 'required|integer|min:1|distinct', 'lines.*.qty' => 'required|string|max:20'];
    }

    public function billPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->billRules());

        return response()->json(['data' => BusinessController::json($this->service->billPreview(auth('tenant')->id(), $id, $input))]);
    }

    public function bill(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->billRules(), 'mutation_uuid' => 'required|uuid', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'expected_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);

        return $this->business->result($this->service->bill(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function orderedPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->billRules(true));

        return response()->json(['data' => BusinessController::json($this->service->billPreview(auth('tenant')->id(), $id, $input, true))]);
    }

    public function orderedBill(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->billRules(true), 'mutation_uuid' => 'required|uuid', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'expected_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);

        return $this->business->result($this->service->bill(auth('tenant')->id(), $id, $input, $input['mutation_uuid'], true));
    }

    private function billedRules(): array
    {
        return ['version' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'handover_confirmed' => 'required|boolean', 'reference' => 'nullable|string|max:150', 'notes' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array:document_line_id,qty', 'lines.*.document_line_id' => 'required|integer|min:1|distinct', 'lines.*.qty' => 'required|string|max:20'];
    }

    public function billedPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->billedRules());

        return response()->json(['data' => BusinessController::json($this->service->billedPreview(auth('tenant')->id(), $id, $input))]);
    }

    public function billedSave(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->billedRules(), 'mutation_uuid' => 'required|uuid', 'expected_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/']);

        return $this->result($this->service->saveBilled(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    private function packageResult(array $result): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->packageData(auth('tenant')->id(), $result['id']))], $result['replayed'] ? 200 : 201);
    }

    private function proofRules(): array
    {
        return ['mutation_uuid' => 'required|uuid', 'expected_fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/'];
    }

    private function packageRules(): array
    {
        return array_diff_key($this->billedRules(), ['handover_confirmed' => true]);
    }

    public function packPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->packageRules());

        return response()->json(['data' => BusinessController::json($this->service->packPreview(auth('tenant')->id(), $id, $input))]);
    }

    public function pack(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->packageRules(), ...$this->proofRules()]);

        return $this->packageResult($this->service->pack(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function packageShow(string $tenant, int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->packageData(auth('tenant')->id(), $id))]);
    }

    private function actionRules(bool $shipping = false): array
    {
        return ['version' => 'required|integer|min:1', 'workflow_version' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), ...($shipping ? ['carrier' => 'nullable|string|max:100', 'tracking_reference' => 'nullable|string|max:150'] : ['handover_confirmed' => 'required|boolean'])];
    }

    public function shipPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->actionRules(true));

        return response()->json(['data' => BusinessController::json($this->service->shipPreview(auth('tenant')->id(), $id, $input))]);
    }

    public function ship(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->actionRules(true), ...$this->proofRules()]);

        return $this->packageResult($this->service->ship(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function deliveryPreview(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->actionRules());

        return response()->json(['data' => BusinessController::json($this->service->deliveryPreview(auth('tenant')->id(), $id, $input))]);
    }

    public function deliver(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate([...$this->actionRules(), ...$this->proofRules()]);

        return $this->packageResult($this->service->deliver(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    private function reversalRules(): array
    {
        return [...array_diff_key($this->actionRules(), ['handover_confirmed' => true]), 'mutation_uuid' => 'required|uuid', 'reason' => 'required|string|min:3|max:500'];
    }

    public function undoDelivery(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->reversalRules());

        return $this->packageResult($this->service->undoDelivery(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }

    public function cancelPackage(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate($this->reversalRules());

        return $this->packageResult($this->service->cancelPackage(auth('tenant')->id(), $id, $input, $input['mutation_uuid']));
    }
}
