<?php

namespace App\Http\Controllers;

use App\Service\BillingService;
use App\Service\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Superadmin account, payment, campaign and delivery management. Operational metadata only.
 */
class PlatformBillingController extends Controller
{
    private function confirmPassword(Request $request): array
    {
        $input = $request->validate(['password' => 'required|string', 'reason' => 'required|string|min:5|max:500']);
        abort_unless(Hash::check($input['password'], auth('superadmin')->user()->password), 403, 'Password incorrect.');

        return $input;
    }

    public function accounts(Request $request, BillingService $billing): JsonResponse
    {
        $input = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = DB::table('billing_accounts')->orderByDesc('id');
        if (! empty($input['q'])) {
            $query->where('name', 'like', '%'.$input['q'].'%');
        }
        $page = $query->paginate(30);
        $page->getCollection()->transform(function ($account) use ($billing) {
            $entitlements = $billing->entitlements((int) $account->id);

            return ['id' => (string) $account->id, 'name' => $account->name, 'status' => $account->status, 'version' => (string) $account->version,
                'package_status' => $entitlements['status'], 'package' => $entitlements['package']['name'] ?? null, 'end_at' => $entitlements['end_at'],
                'owner_email' => DB::table('billing_account_user')->join('users', 'users.id', '=', 'billing_account_user.user_id')->where('billing_account_id', $account->id)->where('role', 'owner')->where('active', true)->value('users.email'),
                'business_count' => (string) DB::table('tenants')->where('billing_account_id', $account->id)->count()];
        });

        return response()->json(BusinessController::json($page->toArray()))->header('Cache-Control', 'no-store');
    }

    public function accountStatus(Request $request, int $id): JsonResponse
    {
        $input = [...$this->confirmPassword($request), ...$request->validate(['status' => 'required|in:active,suspended', 'version' => 'required|integer|min:1'])];
        $row = DB::transaction(function () use ($id, $input) {
            $account = DB::table('billing_accounts')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $account->version === (int) $input['version'], 409, 'Account changed. Reload.');
            DB::table('billing_accounts')->where('id', $id)->update(['status' => $input['status'], 'version' => $account->version + 1, 'updated_at' => now()]);
            DB::table('tenants')->where('billing_account_id', $id)->increment('data_version');
            DB::table('billing_audit_logs')->insert(['actor_id' => auth('superadmin')->id(), 'actor_guard' => 'superadmin', 'action' => 'account.'.$input['status'], 'subject_id' => $id, 'metadata' => json_encode(['reason' => $input['reason']]), 'created_at' => now()]);

            return DB::table('billing_accounts')->where('id', $id)->first();
        }, 3);

        return response()->json(['data' => BusinessController::json($row)]);
    }

    public function payments(Request $request, BillingService $billing): JsonResponse
    {
        $input = $request->validate(['status' => 'nullable|string|max:20', 'page' => 'nullable|integer|min:1']);
        $query = DB::table('subscription_payments')->join('billing_accounts', 'billing_accounts.id', '=', 'subscription_payments.billing_account_id')->orderByDesc('subscription_payments.id')
            ->select('subscription_payments.*', 'billing_accounts.name as account_name');
        if (! empty($input['status'])) {
            $query->where('subscription_payments.status', $input['status']);
        }
        $page = $query->paginate(30);
        $page->getCollection()->transform(fn ($attempt) => [...$billing->paymentData($attempt), 'account_name' => $attempt->account_name, 'billing_account_id' => (string) $attempt->billing_account_id, 'provider_transaction_id' => $attempt->provider_transaction_id, 'last_checked_at' => $attempt->last_checked_at]);

        return response()->json(BusinessController::json($page->toArray()))->header('Cache-Control', 'no-store');
    }

    public function revokePayment(Request $request, int $id, BillingService $billing): JsonResponse
    {
        $input = [...$this->confirmPassword($request), ...$request->validate(['status' => 'required|in:refunded,disputed'])];

        return response()->json(['data' => $billing->paymentData($billing->revokePayment(auth('superadmin')->id(), $id, $input['status'], $input['reason']))]);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $input = $request->validate(['status' => 'nullable|in:pending,sending,sent,skipped,failed,unknown', 'page' => 'nullable|integer|min:1']);
        // Context stays encrypted; diagnostics show event, channel and outcome only.
        $query = DB::table('message_deliveries')->orderByDesc('id')->select(['id', 'event', 'channel', 'status', 'error_code', 'attempts', 'campaign_id', 'billing_account_id', 'created_at', 'sent_at']);
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        return response()->json(BusinessController::json($query->paginate(50)->toArray()))->header('Cache-Control', 'no-store');
    }

    public function campaigns(CampaignService $campaigns): JsonResponse
    {
        return response()->json(['data' => $campaigns->list()])->header('Cache-Control', 'no-store');
    }

    public function saveCampaign(Request $request, CampaignService $campaigns, ?int $id = null): JsonResponse
    {
        $input = $request->validate(['name' => 'required|string|max:150', 'body' => 'required|string|min:5|max:2000', 'channels' => 'required|array|min:1|max:3',
            'channels.*' => 'required|distinct|in:'.implode(',', CampaignService::CHANNELS), 'audience' => 'required|in:'.implode(',', CampaignService::AUDIENCES),
            'version' => $id ? 'required|integer|min:1' : 'nullable']);

        return response()->json(['data' => $campaigns->save(auth('superadmin')->id(), $id, $input)], $id ? 200 : 201);
    }

    public function previewCampaign(int $id, CampaignService $campaigns): JsonResponse
    {
        return response()->json(['data' => $campaigns->preview($id)])->header('Cache-Control', 'no-store');
    }

    public function scheduleCampaign(Request $request, int $id, CampaignService $campaigns): JsonResponse
    {
        $input = [...$this->confirmPassword($request), ...$request->validate(['version' => 'required|integer|min:1', 'scheduled_at' => 'nullable|date|after:now'])];

        return response()->json(['data' => $campaigns->schedule(auth('superadmin')->id(), $id, (int) $input['version'], $input['scheduled_at'] ?? null, $input['reason'])]);
    }

    public function cancelCampaign(Request $request, int $id, CampaignService $campaigns): JsonResponse
    {
        $input = $this->confirmPassword($request);

        return response()->json(['data' => $campaigns->cancel(auth('superadmin')->id(), $id, $input['reason'])]);
    }
}
