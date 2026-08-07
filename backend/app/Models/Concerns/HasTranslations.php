<?php

namespace App\Models\Concerns;

use App\Models\Translation;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * English is the canonical source for every translatable field (see CLAUDE.md i18n decision,
 * originally written for the Honest Report — reused here verbatim for taxonomy/wizard content
 * rather than inventing a second mechanism). Other locales are lazy, on-demand AI translations
 * with hash-based staleness detection: if the English value changes after a translation was
 * made, the translation flips to 'stale' on next read instead of silently going out of sync.
 */
trait HasTranslations
{
    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Fetch (and, if needed, mark stale) the translation for a field/locale.
     * Returns null if no translation exists yet — caller decides whether to fall back
     * to the canonical English value or trigger a "Prevedi" translation request.
     */
    public function translate(string $field, string $locale): ?string
    {
        $translation = $this->translations()
            ->where('field', $field)
            ->where('locale', $locale)
            ->first();

        if (! $translation) {
            return null;
        }

        $currentHash = hash('crc32', (string) $this->{$field});

        if ($translation->source_hash !== $currentHash && $translation->status !== 'stale') {
            $translation->update(['status' => 'stale']);
        }

        return $translation->value;
    }
}
