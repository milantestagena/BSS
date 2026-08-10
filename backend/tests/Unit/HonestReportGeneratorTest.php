<?php

namespace Tests\Unit;

use App\Models\SearchSession;
use App\Services\HonestReportGenerator;
use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Covers HonestReportGenerator — see wizard_architecture memory, 2026-08-10 (first real AI
 * feature). OpenAiClient is mocked throughout; we're testing prompt construction and response
 * parsing here, not the real OpenAI API.
 */
class HonestReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private array $listing = [
        'name' => 'Test Villa',
        'description' => 'A quiet villa with a private pool, 200m from the beach.',
        'reviews' => ['Pool was great, but wifi was patchy in the evenings.'],
    ];

    public function test_parses_a_valid_json_response(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                'pros' => ['Private pool matches the quiet-stay preference.', 'Close to the beach.'],
                'cons' => ['Evening wifi can be patchy per guest reviews.'],
                'summary' => 'A strong fit if you value privacy over connectivity.',
            ]));
        });

        $session = SearchSession::create(['status' => 'in_progress']);
        $result = app(HonestReportGenerator::class)->generate($session, $this->listing);

        $this->assertCount(2, $result['pros']);
        $this->assertCount(1, $result['cons']);
        $this->assertSame('A strong fit if you value privacy over connectivity.', $result['summary']);
    }

    public function test_strips_a_markdown_code_fence_if_present(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(
                "```json\n" . json_encode(['pros' => ['Good value.'], 'cons' => [], 'summary' => 'Solid.']) . "\n```"
            );
        });

        $session = SearchSession::create(['status' => 'in_progress']);
        $result = app(HonestReportGenerator::class)->generate($session, $this->listing);

        $this->assertSame(['Good value.'], $result['pros']);
    }

    public function test_falls_back_to_empty_shape_on_unparseable_response(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not json at all');
        });

        $session = SearchSession::create(['status' => 'in_progress']);
        $result = app(HonestReportGenerator::class)->generate($session, $this->listing);

        $this->assertSame(['pros' => [], 'cons' => [], 'summary' => ''], $result);
    }

    public function test_includes_session_signals_in_the_prompt_sent_to_the_model(): void
    {
        $country = \App\Models\TaxonomyNode::create(['type' => 'country', 'slug' => 'kipar', 'label' => 'Cyprus', 'sort_order' => 0]);
        $tag = \App\Models\TaxonomyNode::create(['type' => 'preference_tag', 'slug' => 'mirno_i_tiho', 'label' => 'Peaceful & quiet', 'sort_order' => 0]);

        $session = SearchSession::create([
            'status' => 'in_progress',
            'country_region_id' => $country->id,
            'free_text_answers' => ['preference_tags' => ['mirno_i_tiho']],
        ]);

        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->with(\Mockery::on(function (array $messages) {
                $userMessage = $messages[1]['content'];

                return str_contains($userMessage, 'Peaceful & quiet')
                    && str_contains($userMessage, 'Test Villa')
                    && str_contains($userMessage, 'wifi was patchy');
            }))->andReturn(json_encode(['pros' => [], 'cons' => [], 'summary' => '']));
        });

        app(HonestReportGenerator::class)->generate($session, $this->listing);
    }
}
