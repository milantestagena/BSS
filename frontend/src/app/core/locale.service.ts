import { Injectable, signal } from '@angular/core';

export type AppLocale = 'en' | 'de';

const STORAGE_KEY = 'tripinele-locale';

/**
 * Current UI language — 'en' (canonical, default) or 'de' (DACH market, see CLAUDE.md section
 * 8). Persisted to localStorage so it survives a refresh/new tab. GraphqlService reads this to
 * set the X-Locale header on every request (see TranslateDirective, backend) — every taxonomy
 * label, wizard question, and step heading is translated server-side automatically once this
 * changes, no per-component wiring needed. Static UI chrome strings (buttons, gating messages,
 * ...) go through the separate `t()` helper in i18n-strings.ts instead, since those aren't
 * backed by a database row.
 */
@Injectable({ providedIn: 'root' })
export class LocaleService {
  readonly locale = signal<AppLocale>(this.readStored());

  setLocale(locale: AppLocale): void {
    this.locale.set(locale);
    localStorage.setItem(STORAGE_KEY, locale);
  }

  private readStored(): AppLocale {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
    return stored === 'de' ? 'de' : 'en';
  }
}
