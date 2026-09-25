import { Injectable, effect, signal } from '@angular/core';

export type AppLocale = 'en' | 'de';

const STORAGE_KEY = 'tripinele-locale';

/**
 * Current UI language — 'en' (canonical) or 'de' (DACH market, see CLAUDE.md section 8).
 * Persisted to localStorage so it survives a refresh/new tab. GraphqlService reads this to
 * set the X-Locale header on every request (see TranslateDirective, backend) — every taxonomy
 * label, wizard question, and step heading is translated server-side automatically once this
 * changes, no per-component wiring needed. Static UI chrome strings (buttons, gating messages,
 * ...) go through the separate `t()` helper in i18n-strings.ts instead, since those aren't
 * backed by a database row.
 *
 * Which language a visitor STARTS in (2026-09-25 — the FB/IG campaign targets Germany, and a
 * German visitor used to land on an English page unless they had already switched themselves,
 * with zero of ~100 cold visitors answering even the first question):
 *   1. `?lang=de|en` on the URL — an explicit instruction from the link itself (ads), remembered.
 *   2. a choice the visitor already made with the language switch.
 *   3. the browser's preferred language: German -> 'de', anything else -> 'en'. Not persisted,
 *      so it keeps following the browser until the visitor picks a language themselves.
 */
@Injectable({ providedIn: 'root' })
export class LocaleService {
  readonly locale = signal<AppLocale>(this.detectInitial());

  constructor() {
    // Keeps <html lang> in step with what is actually shown — index.html hardcodes "en", which
    // makes Chrome offer to "translate" a page that is already German.
    effect(() => {
      if (typeof document !== 'undefined') {
        document.documentElement.lang = this.locale();
      }
    });
  }

  setLocale(locale: AppLocale): void {
    this.locale.set(locale);
    this.store(locale);
  }

  private detectInitial(): AppLocale {
    const fromUrl = this.readFromUrl();
    if (fromUrl) {
      this.store(fromUrl);
      return fromUrl;
    }

    const stored = this.readStored();
    if (stored) {
      return stored;
    }

    return this.readFromBrowser();
  }

  private readFromUrl(): AppLocale | null {
    if (typeof window === 'undefined') return null;
    const lang = new URLSearchParams(window.location.search).get('lang');
    return lang === 'de' || lang === 'en' ? lang : null;
  }

  private readStored(): AppLocale | null {
    try {
      const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
      return stored === 'de' || stored === 'en' ? stored : null;
    } catch {
      // Storage can be unavailable (some in-app/private browsers) — behave as "nothing stored".
      return null;
    }
  }

  /** The browser's top-ranked language decides: a browser that lists German first gets German
   *  even if it also accepts English (it is that visitor's own first choice). */
  private readFromBrowser(): AppLocale {
    if (typeof navigator === 'undefined') return 'en';
    const preferred = (navigator.languages?.length ? navigator.languages[0] : navigator.language) ?? '';
    return preferred.toLowerCase().startsWith('de') ? 'de' : 'en';
  }

  private store(locale: AppLocale): void {
    try {
      localStorage.setItem(STORAGE_KEY, locale);
    } catch {
      // Best-effort — the in-memory signal still holds the choice for this page load.
    }
  }
}
