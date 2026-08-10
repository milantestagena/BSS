<?php

namespace Tests\Feature;

use App\Models\SearchSession;
use App\Models\User;
use App\Services\OpenAiClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Covers the @aiCredits Lighthouse directive — see wizard_architecture memory, 2026-08-10.
 * Real HTTP-level GraphQL tests (not direct resolver calls) since the directive wraps field
 * resolution, not something a plain PHP method call would exercise.
 */
class AiCreditsDirectiveTest extends TestCase
{
    use RefreshDatabase;

    private const MUTATION = <<<'GRAPHQL'
        mutation($id: ID!) {
            generateHonestReport(sessionId: $id, listingName: "Test", listingDescription: "Test desc", reviews: []) {
                summary
            }
        }
    GRAPHQL;

    private function fakeOpenAi(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->andReturn(json_encode(['pros' => [], 'cons' => [], 'summary' => 'ok']));
        });
    }

    public function test_anonymous_visitor_within_limit_succeeds(): void
    {
        $this->fakeOpenAi();
        $session = SearchSession::create(['status' => 'in_progress']);

        $response = $this->postJson('/graphql', ['query' => self::MUTATION, 'variables' => ['id' => $session->id]]);

        $response->assertJsonPath('data.generateHonestReport.summary', 'ok');
    }

    public function test_anonymous_visitor_over_limit_is_blocked(): void
    {
        $this->fakeOpenAi();
        $session = SearchSession::create(['status' => 'in_progress']);

        $limiter = app(RateLimiter::class);
        $key = 'ai-credits:127.0.0.1';
        for ($i = 0; $i < 20; $i++) {
            $limiter->hit($key, 3600);
        }

        $response = $this->postJson('/graphql', ['query' => self::MUTATION, 'variables' => ['id' => $session->id]]);

        $response->assertJsonPath('errors.0.message', fn (string $message) => str_contains($message, 'Rate limit'));
    }

    public function test_logged_in_user_with_credits_spends_one_and_succeeds(): void
    {
        $this->fakeOpenAi();
        $session = SearchSession::create(['status' => 'in_progress']);
        $user = User::factory()->create(); // welcome bonus grants 5 credits, see User::booted()

        $response = $this->actingAs($user)->postJson('/graphql', ['query' => self::MUTATION, 'variables' => ['id' => $session->id]]);

        $response->assertJsonPath('data.generateHonestReport.summary', 'ok');
        $this->assertSame(4, $user->wallet->fresh()->balance);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'amount' => -1, 'type' => 'ai_query']);
    }

    public function test_logged_in_user_with_no_credits_is_blocked(): void
    {
        $this->fakeOpenAi();
        $session = SearchSession::create(['status' => 'in_progress']);
        $user = User::factory()->create();
        $user->wallet->update(['balance' => 0]);

        $response = $this->actingAs($user)->postJson('/graphql', ['query' => self::MUTATION, 'variables' => ['id' => $session->id]]);

        $response->assertJsonPath('errors.0.message', 'Out of AI credits.');
    }
}
