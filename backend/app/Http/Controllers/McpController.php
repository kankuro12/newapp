<?php

namespace App\Http\Controllers;

use App\Service\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Model Context Protocol endpoint (Streamable HTTP, stateless JSON responses) for AI agents.
 * Authenticated by a per-business agent token: Authorization: Bearer <token>.
 */
class McpController extends Controller
{
    public function __construct(private AgentService $agents) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $message = json_decode($request->getContent(), true);
        if (! is_array($message) || array_is_list($message)) {
            return $this->error(null, -32600, 'Send one JSON-RPC 2.0 request per POST.');
        }
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }
        if (! array_key_exists('id', $message)) {
            // Notifications (for example notifications/initialized) need no reply.
            return response()->noContent(202);
        }
        try {
            $context = $this->agents->authenticate($request->bearerToken());
        } catch (HttpExceptionInterface $error) {
            return $this->error($id, -32001, $error->getMessage(), 401);
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, AgentService::PROTOCOL_VERSIONS, true) ? $params['protocolVersion'] : AgentService::PROTOCOL_VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'business-book', 'title' => 'Business Book', 'version' => '1.0.0'],
                'instructions' => 'You act for one business as '.$context['user']->name.' ('.$context['role'].') in '.$context['tenant']->name.'. Amounts are NPR decimal strings; dates are Bikram Sambat YYYYMMDD. Always call preview_sale and confirm the total before record_sale.',
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->agents->tools()]),
            'tools/call' => is_string($params['name'] ?? null)
                ? $this->result($id, $this->agents->call($context, $params['name'], is_array($params['arguments'] ?? null) ? $params['arguments'] : []))
                : $this->error($id, -32602, 'Tool name required.'),
            default => $this->error($id, -32601, 'Method not found: '.$method),
        };
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result])->header('Cache-Control', 'no-store');
    }

    private function error(mixed $id, int $code, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status)->header('Cache-Control', 'no-store');
    }
}
