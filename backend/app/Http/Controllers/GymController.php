<?php

namespace App\Http\Controllers;

use App\Service\AccountingService;
use App\Service\GymService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GymController extends Controller
{
    public function __construct(private GymService $gym, private AccountingService $a, private BusinessController $business) {}

    private function actor(): int
    {
        return auth('tenant')->id();
    }

    private function result(array $result): JsonResponse
    {
        if ($result['table'] === 'documents') {
            return $this->business->result($result);
        }
        $data = match ($result['table']) {
            'gym_members' => $this->gym->member((int) $result['id']),
            'gym_memberships' => $this->gym->member((int) $this->a->requireRow('gym_memberships', $result['id'])->member_id),
            default => (array) $this->a->requireRow($result['table'], $result['id']),
        };

        return response()->json(['data' => BusinessController::json($data)], $result['replayed'] ? 200 : 201);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->gym->plans())]);
    }

    public function savePlan(Request $request, string $tenant, ?int $id = null): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'version' => $id ? 'required|integer|min:1' : 'nullable', 'name' => 'required|string|max:150', 'item_id' => 'required|integer|min:1',
            'fee' => 'required|string|max:20', 'duration_days' => 'required|integer|min:1|max:3650', 'visit_limit' => 'nullable|integer|min:1|max:3650', 'class_access' => 'sometimes|boolean', 'active' => 'sometimes|boolean']);

        return $this->result($this->gym->savePlan($this->actor(), $id, $input, $input['mutation_uuid']));
    }

    public function members(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:100']);

        return response()->json(['data' => BusinessController::json($this->gym->members($input['q'] ?? null))]);
    }

    public function member(string $tenant, int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->gym->member($id))]);
    }

    public function createMember(Request $request): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'business_date_bs' => $this->business->dateRule(), 'contact_id' => 'nullable|integer|min:1',
            'name' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:30', 'member_code' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:500']);

        return $this->result($this->gym->createMember($this->actor(), $input, $input['mutation_uuid']));
    }

    private function joinRules(): array
    {
        return ['member_id' => 'required|integer|min:1', 'plan_id' => 'required|integer|min:1', 'plan_version' => 'sometimes|integer|min:1', 'business_date_bs' => $this->business->dateRule(),
            'basket_offer_id' => 'nullable|integer|min:1', 'expected_fingerprint' => 'sometimes|required|string|regex:/^[a-f0-9]{64}$/'];
    }

    public function joinPreview(Request $request): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->gym->joinPreview($this->actor(), $request->validate($this->joinRules())))]);
    }

    public function join(Request $request): JsonResponse
    {
        $input = $request->validate([...$this->joinRules(), 'mutation_uuid' => 'required|uuid', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'paid_now' => 'required|string|max:20', 'money_account_id' => 'nullable|integer|min:1']);

        return $this->result($this->gym->join($this->actor(), $input, $input['mutation_uuid']));
    }

    public function cancelMembership(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1']);

        return $this->result($this->gym->cancelMembership($this->actor(), $id, $input, $input['mutation_uuid']));
    }

    public function checkIn(Request $request): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'member_id' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'minute' => 'nullable|integer|min:0|max:1439']);

        return $this->result($this->gym->checkIn($this->actor(), $input, $input['mutation_uuid']));
    }

    public function day(Request $request): JsonResponse
    {
        $input = $request->validate(['date' => $this->business->dateRule()]);

        return response()->json(['data' => BusinessController::json($this->gym->day((int) $input['date']))]);
    }

    public function createClass(Request $request): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'name' => 'required|string|max:150', 'trainer_name' => 'nullable|string|max:150', 'business_date_bs' => $this->business->dateRule(),
            'start_minute' => 'required|integer|min:0|max:1439', 'duration_minutes' => 'required|integer|min:5|max:600', 'capacity' => 'required|integer|min:1|max:1000']);

        return $this->result($this->gym->createClass($this->actor(), $input, $input['mutation_uuid']));
    }

    public function bookings(string $tenant, int $id): JsonResponse
    {
        return response()->json(['data' => BusinessController::json($this->gym->bookings($id))]);
    }

    public function book(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid', 'member_id' => 'required|integer|min:1']);

        return $this->result($this->gym->book($this->actor(), $id, $input, $input['mutation_uuid']));
    }

    public function cancelBooking(Request $request, string $tenant, int $id): JsonResponse
    {
        $input = $request->validate(['mutation_uuid' => 'required|uuid']);

        return $this->result($this->gym->cancelBooking($this->actor(), $id, $input, $input['mutation_uuid']));
    }
}
