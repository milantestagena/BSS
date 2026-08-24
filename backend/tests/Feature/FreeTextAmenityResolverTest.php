<?php

namespace Tests\Feature;

use App\GraphQL\Resolvers\FreeTextAmenityResolver;
use App\Models\SearchSession;
use App\Models\TaxonomyNode;
use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Direct-resolver-call style, same convention as GeographyResolverTest — see
 * FreeTextAmenityResolver's docblock, 2026-08-24.
 */
class FreeTextAmenityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_matched_slugs_into_amenities_yes_and_persists_them(): void
    {
        TaxonomyNode::create(['type' => 'room_facility', 'slug' => 'klima', 'label' => 'Air conditioning', 'sort_order' => 0]);
        TaxonomyNode::create(['type' => 'accommodation_facility', 'slug' => 'bazen', 'label' => 'Swimming pool', 'sort_order' => 0]);

        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['slugs' => ['klima']]));
        });

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => [
                'smestaj_preference' => 'Would love air conditioning, it gets so hot there',
                'amenities_yes' => ['bazen'],
            ],
        ]);

        $result = (new FreeTextAmenityResolver)->extract(null, ['sessionId' => $session->id]);

        $this->assertEqualsCanonicalizing(['bazen', 'klima'], $result);
        $this->assertEqualsCanonicalizing(['bazen', 'klima'], $session->fresh()->free_text_answers['amenities_yes']);
    }

    public function test_does_not_duplicate_an_already_selected_slug(): void
    {
        TaxonomyNode::create(['type' => 'room_facility', 'slug' => 'klima', 'label' => 'Air conditioning', 'sort_order' => 0]);

        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['slugs' => ['klima']]));
        });

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['smestaj_preference' => 'air conditioning please', 'amenities_yes' => ['klima']],
        ]);

        $result = (new FreeTextAmenityResolver)->extract(null, ['sessionId' => $session->id]);

        $this->assertSame(['klima'], $result);
    }

    public function test_returns_existing_amenities_unchanged_without_calling_the_model_when_free_text_is_empty(): void
    {
        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('chat');
        });

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['amenities_yes' => ['bazen']],
        ]);

        $result = (new FreeTextAmenityResolver)->extract(null, ['sessionId' => $session->id]);

        $this->assertSame(['bazen'], $result);
    }

    public function test_returns_existing_amenities_unchanged_when_nothing_matches(): void
    {
        TaxonomyNode::create(['type' => 'room_facility', 'slug' => 'klima', 'label' => 'Air conditioning', 'sort_order' => 0]);

        $this->mock(OpenAiClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode(['slugs' => []]));
        });

        $session = SearchSession::create([
            'status' => 'in_progress',
            'free_text_answers' => ['smestaj_preference' => 'somewhere quiet and peaceful', 'amenities_yes' => ['bazen']],
        ]);

        $result = (new FreeTextAmenityResolver)->extract(null, ['sessionId' => $session->id]);

        $this->assertSame(['bazen'], $result);
        $this->assertSame(['bazen'], $session->fresh()->free_text_answers['amenities_yes']);
    }
}
