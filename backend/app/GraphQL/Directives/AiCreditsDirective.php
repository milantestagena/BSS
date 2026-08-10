<?php

namespace App\GraphQL\Directives;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Nuwave\Lighthouse\Exceptions\RateLimitException;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Gates AI-spending fields (today: generateHonestReport) — see CLAUDE.md section 5. Lighthouse
 * doesn't support plain Laravel HTTP `@middleware` on fields in this version (bug caught
 * 2026-08-10 — "No directive found for `middleware`"), so this is a proper Lighthouse
 * FieldMiddleware directive instead, which wraps the resolver directly.
 *
 * Two modes, since real login (Google OAuth) isn't wired into the wizard flow yet — waiting on
 * the owner's Google OAuth Client ID/Secret:
 * - Logged-in user: spends 1 real credit from their Wallet, blocks at balance <= 0. This is the
 *   real, permanent mechanism once step-8 login-gating exists on the frontend (CLAUDE.md
 *   section 3: "Login/credit gate se primenjuje samo na koraku konkretnog smeštaja").
 * - Anonymous visitor (the only case that exists in practice right now): a per-IP rate limit
 *   instead, so demo/testing traffic can't run up real spend before the proper gate exists.
 *   NOT a permanent design — once login is wired, anonymous access to this field should
 *   probably be removed entirely, not just rate-limited.
 */
class AiCreditsDirective extends BaseDirective implements FieldMiddleware
{
    private const ANONYMOUS_LIMIT_PER_HOUR = 20;

    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
        """
        Gates an AI-spending field: real wallet credits for a logged-in user, a per-IP rate
        limit for anonymous visitors. See CLAUDE.md section 5.
        """
        directive @aiCredits on FIELD_DEFINITION
        GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver): \Closure => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver) {
            /** @var User|null $user */
            $user = $context->user();

            if ($user) {
                $wallet = $user->wallet;
                if (! $wallet || $wallet->balance <= 0) {
                    throw new OutOfCreditsException();
                }

                $wallet->decrement('balance');
                $user->creditTransactions()->create([
                    'amount' => -1,
                    'type' => 'ai_query',
                    'description' => 'Honest Report generation',
                ]);
            } else {
                $limiter = app(RateLimiter::class);
                $key = 'ai-credits:' . request()->ip();

                if ($limiter->tooManyAttempts($key, self::ANONYMOUS_LIMIT_PER_HOUR)) {
                    throw new RateLimitException($resolveInfo->fieldName);
                }

                $limiter->hit($key, 3600);
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }
}
