<?php

namespace App\Http\Controllers;

use App\Service\BillingService;
use App\Service\GatewayService;
use App\Service\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private BillingService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->accounts(auth('tenant')->id()))])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:150']);

        return response()->json(['data' => BusinessController::json($this->service->createAccount(auth('tenant')->id(), $input['name']))], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->service->account(auth('tenant')->id(), $id))])->header('Cache-Control', 'no-store');
    }

    public function business(Request $request, int $id, TenantService $tenants): JsonResponse
    {
        $this->service->account(auth('tenant')->id(), $id);
        $input = $request->validate(['name' => 'required|string|max:150', 'business_type' => 'sometimes|in:general,meat,restaurant,barber,salon,milk,glass,wood,gym', 'address' => 'nullable|string|max:500', 'phone' => 'nullable|string|max:30', 'pan' => 'nullable|string|max:30', 'default_locale' => 'sometimes|in:en,ne']);

        return response()->json(['data' => BusinessController::json($tenants->create(auth('tenant')->id(), [...$input, 'billing_account_id' => $id]))], 201);
    }

    public function packages(): JsonResponse
    {
        return response()->json(['data' => $this->service->packages()])->header('Cache-Control', 'no-store');
    }

    public function subscription(int $id): JsonResponse
    {
        $this->service->account(auth('tenant')->id(), $id);

        return response()->json(['data' => $this->service->entitlements($id)])->header('Cache-Control', 'no-store');
    }

    public function trial(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['package_id' => 'required|integer|min:1']);

        return response()->json(['data' => BusinessController::json($this->service->startTrial(auth('tenant')->id(), $id, $input['package_id']))], 201);
    }

    public function gateways(GatewayService $gateways): JsonResponse
    {
        return response()->json(['data' => $gateways->available()])->header('Cache-Control', 'no-store');
    }

    public function payments(int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->paymentHistory(auth('tenant')->id(), $id)])->header('Cache-Control', 'no-store');
    }

    public function businesses(int $id): JsonResponse
    {
        return response()->json(['data' => $this->service->accountBusinesses(auth('tenant')->id(), $id)])->header('Cache-Control', 'no-store');
    }

    public function checkout(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'package_id' => 'required|integer|min:1',
            'gateway' => 'required|in:esewa,khalti,stripe,paypal', 'currency' => 'required|in:NPR,USD,EUR', 'expected_version' => 'sometimes|integer|min:1',
            'retained_business_ids' => 'sometimes|array|max:10000', 'retained_business_ids.*' => 'required|integer|min:1|distinct']);
        $result = $this->service->checkout(auth('tenant')->id(), $id, $input);

        return response()->json(['data' => $this->service->paymentData($result['attempt'])], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store');
    }

    public function payment(int $id, string $reference): JsonResponse
    {
        return response()->json(['data' => $this->service->paymentData($this->service->payment(auth('tenant')->id(), $id, $reference))])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, int $id, string $reference): JsonResponse
    {
        $callback = $request->validate(['data' => 'sometimes|string|max:16000']);

        return response()->json(['data' => $this->service->paymentData($this->service->confirm(auth('tenant')->id(), $id, $reference, $callback))])->header('Cache-Control', 'no-store');
    }
}
