<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentMcpTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Agent shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$business['slug'];
        $cash = $this->getJson($url.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $item = $this->postJson($url.'/items', ['name' => 'Haircut service', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '250'])->assertCreated()->json('data.id');

        return compact('owner', 'business', 'cash', 'item');
    }

    private function token(array $s, bool $write): string
    {
        return $this->postJson('/api/agent-tokens', ['business_id' => $s['business']['id'], 'name' => 'Test agent', 'write' => $write])->assertCreated()->json('data.token');
    }

    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function tool(string $token, string $name, array $arguments = []): array
    {
        return $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk()->json('result');
    }

    public function test_agent_previews_and_records_a_sale_once_through_the_normal_posting_path(): void
    {
        $s = $this->shop();
        $token = $this->token($s, true);
        $this->app['auth']->forgetGuards();
        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18'])->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')->assertJsonPath('result.serverInfo.name', 'business-book');
        $this->assertContains('record_sale', array_column($this->rpc($token, 'tools/list')->json('result.tools'), 'name'));
        $this->assertSame('Haircut service', $this->tool($token, 'search_items', ['query' => 'Hair'])['structuredContent']['items'][0]['name']);
        $preview = $this->tool($token, 'preview_sale', ['business_date_bs' => '20830101', 'lines' => [['item_id' => $s['item'], 'qty' => '2']]]);
        $this->assertFalse($preview['isError']);
        $this->assertSame('50000', $preview['structuredContent']['total_paisa']);
        $sale = ['business_date_bs' => '20830101', 'lines' => [['item_id' => $s['item'], 'qty' => '2']], 'expected_total_paisa' => '50000', 'paid_now' => '500', 'money_account_id' => $s['cash'], 'request_id' => (string) Str::uuid()];
        $first = $this->tool($token, 'record_sale', $sale);
        $this->assertFalse($first['isError'], $first['content'][0]['text']);
        $retry = $this->tool($token, 'record_sale', $sale);
        $this->assertTrue($retry['structuredContent']['replayed']);
        $this->assertSame(1, DB::table('documents')->where('type', 'sale')->count());
        $changed = $this->tool($token, 'record_sale', [...$sale, 'expected_total_paisa' => '1', 'request_id' => (string) Str::uuid()]);
        $this->assertTrue($changed['isError']);
        $this->assertSame(1, DB::table('documents')->where('type', 'sale')->count());
    }

    public function test_read_only_revoked_and_foreign_tokens_are_refused(): void
    {
        $s = $this->shop();
        $readOnly = $this->token($s, false);
        $result = $this->tool($readOnly, 'record_sale', ['lines' => [['item_id' => $s['item'], 'qty' => '1']], 'expected_total_paisa' => '25000', 'paid_now' => '0', 'request_id' => (string) Str::uuid()]);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('read-only', $result['content'][0]['text']);
        $this->assertSame(0, DB::table('documents')->count());

        $id = $this->getJson('/api/agent-tokens')->assertOk()->json('data.0.id');
        $this->deleteJson('/api/agent-tokens/'.$id)->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->rpc($readOnly, 'tools/list')->assertUnauthorized();
        $this->rpc('not-a-token', 'tools/list')->assertUnauthorized();

        $stranger = User::factory()->create();
        $this->actingAs($stranger, 'tenant');
        $this->postJson('/api/agent-tokens', ['business_id' => $s['business']['id'], 'name' => 'Intruder'])->assertNotFound();
    }

    public function test_removed_member_token_stops_working(): void
    {
        $s = $this->shop();
        $token = $this->token($s, true);
        DB::table('tenant_user')->where('user_id', $s['owner']->id)->update(['active' => false]);
        $this->app['auth']->forgetGuards();
        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }
}
