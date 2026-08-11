<?php

namespace App\GraphQL\Directives;

use Illuminate\Http\Request;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Applied to a translatable field (TaxonomyNode/WizardStep/WizardQuestion `label`) — returns the
 * requested locale's translation if one exists, otherwise falls back to the canonical English
 * value untouched. See HasTranslations/Translation, 2026-08-11 German-language work: the storage
 * layer (lazy AI/human translations, hash-based staleness) already existed from the Honest
 * Report i18n design but nothing actually READ it via GraphQL until now.
 *
 * Locale comes from the `X-Locale` request header (see frontend/src/app/core/graphql.service.ts)
 * rather than a per-query argument — every labeled field in every query benefits automatically,
 * no need to thread a `locale` arg through every resolver/query.
 */
class TranslateDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
        """
        Returns this field's translation for the request's X-Locale header, falling back to the
        canonical English value when no translation exists yet (or the locale is 'en'). Pass
        `attribute` when combined with @rename (Translation rows are keyed by the underlying
        Eloquent attribute, e.g. `landing_headline`, not the camelCase GraphQL field name).
        """
        directive @translate(attribute: String) on FIELD_DEFINITION
        GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver): \Closure => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver) {
            $value = $resolver($root, $args, $context, $resolveInfo);

            if (! method_exists($root, 'translate')) {
                return $value;
            }

            // Deliberately NOT short-circuited when locale === 'en' — the canonical `label` is
            // English for almost everything (TaxonomyNode/WizardStep/WizardQuestion), but
            // WizardCampaign's canonical text is Serbian (pre-existing, unrelated gap), so an
            // 'en' Translation row genuinely exists and must actually be checked here too.
            $locale = app(Request::class)->header('X-Locale', 'en');
            $attribute = $this->directiveArgValue('attribute') ?? $resolveInfo->fieldName;

            return $root->translate($attribute, $locale) ?? $value;
        });
    }
}
