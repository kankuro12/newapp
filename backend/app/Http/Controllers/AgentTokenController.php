<?php

namespace App\Http\Controllers;

use App\Service\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgentTokenController extends Controller
{
    public function __construct(private AgentService $agents) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->agents->tokens(auth('tenant')->id()), 'mcp_url' => rtrim(config('app.url'), '/').'/mcp'])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['business_id' => 'required|integer|min:1', 'name' => 'required|string|max:100', 'write' => 'sometimes|boolean', 'days' => 'sometimes|integer|in:7,30,90,365']);

        return response()->json(['data' => $this->agents->createToken(auth('tenant')->id(), (int) $input['business_id'], $input['name'], (bool) ($input['write'] ?? false), (int) ($input['days'] ?? 90))], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(int $id): Response
    {
        $this->agents->revokeToken(auth('tenant')->id(), $id);

        return response()->noContent();
    }
}
