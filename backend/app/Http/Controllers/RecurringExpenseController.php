<?php

namespace App\Http\Controllers;

use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\RecurringExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringExpenseController extends Controller
{
    public function __construct(private AccountingService $a, private RecurringExpenseService $service, private BusinessController $business) {}

    private function authorize(): void
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant']);
    }

    private function data(object $rule): array
    {
        return [...(array) $rule, 'payee_name' => $this->a->requireRow('contacts', $rule->contact_id)->name, 'category_name' => $this->a->requireRow('expense_categories', $rule->expense_category_id)->name, 'current_month' => $this->service->preview($rule, NepaliDate::monthRange(NepaliDate::today())[0])];
    }

    public function listing(Request $r): JsonResponse
    {
        $this->authorize();
        $filter = $r->validate(['kind' => 'nullable|in:salary,rent,other', 'page' => 'nullable|integer|min:1']);
        $q = $this->a->rows('recurring_expenses');
        if (! empty($filter['kind'])) {
            $q->where('kind', $filter['kind']);
        }
        $page = $q->orderBy('label')->paginate(25);
        $page->getCollection()->transform(fn ($rule) => $this->data($rule));

        return response()->json(BusinessController::json($page->toArray()));
    }

    public function show(string $tenant, int $id): JsonResponse
    {
        $this->authorize();

        return response()->json(['data' => BusinessController::json($this->data($this->a->requireRow('recurring_expenses', $id)))]);
    }

    public function save(Request $r, string $tenant, ?int $id = null): JsonResponse
    {
        $this->authorize();
        $rules = ['mutation_uuid' => 'required|uuid', 'label' => 'required|string|max:150', 'amount' => 'required|string|max:20', 'auto_generate' => 'required|boolean', 'enabled' => 'required|boolean'];
        $rules += $id ? ['version' => 'required|integer|min:1'] : ['kind' => 'required|in:salary,rent,other', 'contact_id' => 'nullable|integer|min:1', 'payee_name' => 'nullable|string|max:150', 'expense_category_id' => 'nullable|required_if:kind,other|integer|min:1', 'first_date_bs' => $this->business->dateRule(), 'monthly_day' => 'required|integer|min:1|max:32'];

        return $this->business->result($this->service->save(auth('tenant')->id(), $r->validate($rules), $id));
    }

    public function period(Request $r, string $tenant, int $id): JsonResponse
    {
        $this->authorize();
        $input = $r->validate(['period_bs' => $this->business->dateRule()]);
        $rule = $this->a->requireRow('recurring_expenses', $id);

        return response()->json(['data' => BusinessController::json($this->service->preview($rule, $this->service->period($rule, $input['period_bs'])))]);
    }

    public function history(string $tenant, int $id): JsonResponse
    {
        $this->authorize();
        $rule = $this->a->requireRow('recurring_expenses', $id);
        $page = $this->a->rows('recurring_expense_occurrences')->where('recurring_expense_id', $id)->orderByDesc('period_bs')->paginate(25);
        $page->getCollection()->transform(fn ($row) => [...(array) $row, ...$this->service->preview($rule, (int) $row->period_bs)]);

        return response()->json(BusinessController::json($page->toArray()));
    }

    public function post(Request $r, string $tenant, int $id): JsonResponse
    {
        $this->authorize();
        $input = $r->validate(['mutation_uuid' => 'required|uuid', 'period_bs' => $this->business->dateRule(), 'business_date_bs' => $this->business->dateRule(), 'expected_total_paisa' => 'required|regex:/^\d{1,11}$/', 'paid_now' => 'required|string|max:20', 'money_account_id' => 'nullable|integer|min:1', 'overdraft_confirmed' => 'sometimes|boolean']);

        return $this->business->result($this->service->post(auth('tenant')->id(), $id, $input));
    }
}
