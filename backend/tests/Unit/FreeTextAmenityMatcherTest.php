<?php

namespace Tests\Unit;

use App\Models\TaxonomyNode;
use App\Services\FreeTextAmenityMatcher;
use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Covers FreeTextAmenityMatcher — see FreeTextAmenityResolver's docblock, 2026-08-24.
 * OpenAiClient is mocked throughout, same convention as HonestReportGeneratorTest.
 */
class FreeTextAmenityMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): \Illuminate\Support\Collection
    {
        return collect([
            new TaxonomyNode(['slug' => 'klima', 'label' => 'Air conditioning']),
            new TaxonomyNode(['slug' => 'privatni_bazen', 'label' => 'Private pool']),
            new TaxonomyNode(['slug' => 'fen', 'label' => 'Hairdryer']),
        ]);
    }

    public function test_returns_matched_slugs_from_a_valid_json_response(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['slugs' => ['klima', 'fen']]));
        });

        $result = app(FreeTextAmenityMatcher::class)->match('needs air conditioning and a hairdryer', $this->catalog());

        $this->assertSame(['klima', 'fen'], $result);
    }

    public function test_filters_out_slugs_the_model_invented_outside_the_catalog(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['slugs' => ['klima', 'wifi_super_fast']]));
        });

        $result = app(FreeTextAmenityMatcher::class)->match('fast wifi and air conditioning please', $this->catalog());

        $this->assertSame(['klima'], $result);
    }

    public function test_returns_empty_array_without_calling_the_model_for_blank_text(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('chat');
        });

        $result = app(FreeTextAmenityMatcher::class)->match('   ', $this->catalog());

        $this->assertSame([], $result);
    }

    public function test_falls_back_to_empty_array_on_unparseable_response(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn('not json at all');
        });

        $result = app(FreeTextAmenityMatcher::class)->match('anything', $this->catalog());

        $this->assertSame([], $result);
    }

    public function test_strips_a_markdown_code_fence_if_present(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn("```json\n" . json_encode(['slugs' => ['privatni_bazen']]) . "\n```");
        });

        $result = app(FreeTextAmenityMatcher::class)->match('want a private pool', $this->catalog());

        $this->assertSame(['privatni_bazen'], $result);
    }
}
