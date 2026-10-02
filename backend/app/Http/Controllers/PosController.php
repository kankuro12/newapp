<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\AppointmentService;
use App\Service\PosService;
use App\Service\RestaurantService;
use App\Service\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function __construct(private AccountingService $a, private PosService $pos, private RestaurantService $restaurant, private AppointmentService $appointments, private BusinessController $business) {}

    private function staff(): int
    {
        $actor = auth('tenant')->id();
        $this->a->authorize($actor, ['owner', 'manager', 'cashier']);

        return $actor;
    }

    private function result(array $result): JsonResponse
    {
        if ($result['table'] === 'documents' || $result['table'] === 'tenants') {
            return $this->business->result($result);
        }
        $data = match ($result['table']) {
            'restaurant_orders' => $this->restaurant->data($result['id']),'appointments' => $this->appointments->data($result['id']),default => (array) $this->a->requireRow($result['table'], $result['id'])
        };

        return response()->json(['data' => BusinessController::json($data)], $result['replayed'] ? 200 : 201);
    }

    public function config(): JsonResponse
    {
        return response()->json(['data' => BusinessController::json(['profiles' => PosService::PROFILES, 'methods' => PosService::METHODS, 'units' => PosService::UNITS, 'resources' => $this->a->rows('pos_resources')->orderBy('name')->get()])]);
    }

    public function profile(Request $r): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner']);
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'pos_profile' => ['required', Rule::in(PosService::PROFILES)]]);
        $result = $this->a->mutate(auth('tenant')->id(), $input['mutation_uuid'], 'pos.profile', $input, function (Tenant $tenant) use ($input) {
            $tenant->update(['pos_profile' => $input['pos_profile']]);

            return ['table' => 'tenants', 'id' => $tenant->id];
        });

        return $this->result($result);
    }

    public function branch(Request $r, TenantService $service): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'name' => 'required|string|max:150', 'pos_profile' => ['required', Rule::in(PosService::PROFILES)]]);

        return $this->result($service->branch(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }

    public function resource(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'sometimes|integer|min:1', 'kind' => 'required|in:staff,table', 'name' => 'required|string|max:100', 'start_minute' => 'required|integer|min:0|max:1439', 'end_minute' => 'required|integer|min:1|max:1440', 'active' => 'sometimes|boolean']);

        return $this->result($this->pos->resource(auth('tenant')->id(), $input, $input['mutation_uuid'], $id));
    }

    private function measurementRules(): array
    {
        return ['lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|min:1', 'lines.*.note' => 'nullable|string|max:300', 'lines.*.measurement' => 'required|array:mode,value,unit,length,length_unit,width,width_unit,thickness,thickness_unit,pieces,custom_index', 'lines.*.measurement.mode' => ['required', Rule::in(PosService::METHODS)], 'lines.*.measurement.value' => 'sometimes|string|max:20', 'lines.*.measurement.unit' => ['sometimes', Rule::in(array_keys(PosService::UNITS))], 'lines.*.measurement.custom_index' => 'sometimes|integer|min:0|max:49', 'lines.*.measurement.length' => 'sometimes|string|max:20', 'lines.*.measurement.width' => 'sometimes|string|max:20', 'lines.*.measurement.thickness' => 'sometimes|string|max:20', 'lines.*.measurement.pieces' => 'sometimes|string|max:20', 'lines.*.measurement.length_unit' => 'sometimes|in:mm,cm,m,in,ft', 'lines.*.measurement.width_unit' => 'sometimes|in:mm,cm,m,in,ft', 'lines.*.measurement.thickness_unit' => 'sometimes|in:mm,cm,m,in,ft'];
    }

    private function checkoutRules(): array
    {
        return ['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'contact_id' => 'nullable|integer|min:1', 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'paid_now' => 'required|string|max:20', 'money_account_id' => 'nullable|integer|min:1'];
    }

    public function preview(Request $r): JsonResponse
    {
        $input = $r->validate($this->measurementRules());
        foreach ($input['lines'] as $row) {
            $this->validateMeasurement($row['measurement']);
        }

return response()->json(['data' => BusinessController::json($this->pos->preview($input))]);
    }

    private function validateMeasurement(array $m): void
    {
        if (in_array($m['mode'], ['quantity', 'amount', 'pack']) && ! isset($m['value'])) {
            $this->a->fail('Enter quantity or amount.');
        }
    }

    public function sale(Request $r): JsonResponse
    {
        $input = $r->validate([...$this->measurementRules(), ...array_diff_key($this->checkoutRules(), ['version' => true])]);
        foreach ($input['lines'] as $row) {
            $this->validateMeasurement($row['measurement']);
        }

return $this->result($this->pos->sale(auth('tenant')->id(), $input, $input['mutation_uuid']));
    }

    public function orders(): JsonResponse
    {
        $this->staff();
        $data = $this->a->rows('restaurant_orders')->where('status', 'open')->orderBy('id')->get()->map(fn ($row) => $this->restaurant->data($row->id));

        return response()->json(['data' => BusinessController::json($data)]);
    }

    public function order(Request $r): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'resource_id' => 'nullable|integer|min:1', 'kind' => 'required|in:dine_in,takeaway', 'guest_name' => 'nullable|string|max:150']);

        return $this->result($this->restaurant->create($actor, $input, $input['mutation_uuid']));
    }

    public function send(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'lines' => 'required|array|min:1|max:100', 'lines.*.item_id' => 'required|integer|min:1', 'lines.*.qty' => 'required|string|max:20', 'lines.*.note' => 'nullable|string|max:300']);

        return $this->result($this->restaurant->send($actor, $id, $input, $input['mutation_uuid']));
    }

    public function ticket(Request $r, string $tenant, int $id, int $ticket): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'status' => 'required|in:preparing,ready,served,cancelled', 'reason' => 'nullable|string|max:500']);

        return $this->result($this->restaurant->ticket($actor, $id, $ticket, $input, $input['mutation_uuid']));
    }

    public function cancelOrder(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:500']);

        return $this->result($this->restaurant->cancel($actor, $id, $input, $input['mutation_uuid']));
    }

    public function orderCheckout(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate($this->checkoutRules());

        return $this->result($this->restaurant->checkout($actor, $id, $input, $input['mutation_uuid']));
    }

    public function bookings(Request $r): JsonResponse
    {
        $this->staff();
        $input = $r->validate(['date' => $this->business->dateRule()]);
        $day = NepaliDate::normalize($input['date']);
        $rows = $this->a->rows('appointments')->where('business_date_bs', $day)->orderBy('start_minute')->get()->map(fn ($row) => $this->appointments->data($row->id));

        return response()->json(['data' => BusinessController::json($rows)]);
    }

    public function booking(Request $r): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'resource_id' => 'required|integer|min:1', 'business_date_bs' => $this->business->dateRule(), 'start_minute' => 'required|integer|min:0|max:1439', 'client_name' => 'required|string|max:150', 'phone' => 'nullable|string|max:30', 'contact_id' => 'nullable|integer|min:1', 'item_ids' => 'sometimes|array|max:30', 'item_ids.*' => 'required|integer|min:1', 'duration_minutes' => 'sometimes|integer|min:1|max:720', 'status' => 'required|in:booked,blocked', 'notes' => 'nullable|string|max:1000']);

        return $this->result($this->appointments->create($actor, $input, $input['mutation_uuid']));
    }

    public function updateBooking(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'version' => 'required|integer|min:1', 'resource_id' => 'sometimes|integer|min:1', 'business_date_bs' => ['sometimes', ...$this->business->dateRule()], 'start_minute' => 'sometimes|integer|min:0|max:1439', 'status' => 'sometimes|in:booked,arrived,in_service,cancelled,no_show,blocked']);

        return $this->result($this->appointments->update($actor, $id, $input, $input['mutation_uuid']));
    }

    public function bookingCheckout(Request $r, string $tenant, int $id): JsonResponse
    {
        $actor = $this->staff();
        $input = $r->validate($this->checkoutRules());

        return $this->result($this->appointments->checkout($actor,$id,$input,$input['mutation_uuid']));
    }
}
