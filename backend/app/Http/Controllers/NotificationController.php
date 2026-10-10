<?php

namespace App\Http\Controllers;

use App\Service\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function preferences(Request $request): JsonResponse
    {
        $fields = array_fill_keys(['operational_email', 'promotional_email', 'operational_fcm', 'promotional_fcm', 'operational_whatsapp', 'promotional_whatsapp'], 'sometimes|boolean');
        $changes = $request->isMethod('PATCH') ? $request->validate($fields) : [];

        return response()->json(['data' => $this->notifications->preferences(auth('tenant')->id(), $changes)])->header('Cache-Control', 'no-store');
    }

    public function devices(): JsonResponse
    {
        return response()->json(['data' => $this->notifications->devices(auth('tenant')->id())])->header('Cache-Control', 'no-store');
    }

    public function register(Request $request): JsonResponse
    {
        $input = $request->validate(['device_uuid' => 'required|uuid', 'target_kind' => 'sometimes|in:token,fid', 'token' => ['required', 'string', 'max:4096', 'regex:/^[A-Za-z0-9:._-]+$/D'], 'name' => 'sometimes|string|max:100', 'consent' => 'required|accepted']);

        return response()->json(['data' => $this->notifications->registerDevice(auth('tenant')->id(), $input)], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(int $id): Response
    {
        $this->notifications->revokeDevice(auth('tenant')->id(), $id);

        return response()->noContent();
    }
}
