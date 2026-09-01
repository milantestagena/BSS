import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { I18nService } from '../../core/i18n.service';
import { LocaleService } from '../../core/locale.service';

/**
 * Persistent, always-visible account status — see AuthService docblock. Deliberately NOT a
 * gate on anything yet (CLAUDE.md section 3: login only gates step 8) — just lets a visitor
 * see/reach their account, and gives Google's OAuth consent screen a clear, discoverable entry
 * point rather than a bare URL only I know about. 2026-08-10.
 *
 * EN/DE language switch lives here too, 2026-08-11 — same "always visible, top corner" slot,
 * no reason to mount a second fixed-position element just for it.
 *
 * Bug fixed 2026-08-14: this used to be a small `fixed top-4 right-4` chip with no background
 * of its own, so whatever chat bubble/question happened to scroll up underneath it (there's no
 * reserved gap for a `fixed` element in normal document flow) showed through and collided with
 * it — owner caught it live on mobile ("How many adults are traveling?" overlapping the EN/DE +
 * credits chips). Now a real full-width header bar with its own opaque/blurred background, so
 * nothing is ever visible underneath it regardless of scroll position — see app.html's
 * `pt-14` spacer on the router-outlet wrapper for the matching reserved space up top.
 */
@Component({
  selector: 'app-account-badge',
  standalone: true,
  template: `
    <!-- bg-stone-900 (warm dark neutral), not slate-900 (cool blue-gray) — 2026-08-21 design
         pass: slate read as an unrelated dark navy against the sunset/amber palette the rest
         of the site is now built around. -->
    <div class="fixed inset-x-0 top-0 z-30 flex items-center justify-end gap-2 border-b border-white/10 bg-stone-900/70 px-4 py-2.5 backdrop-blur-md">
      <div class="flex overflow-hidden rounded-full border border-white/50 bg-white/95 text-xs font-semibold shadow-lg backdrop-blur-sm">
        <button
          type="button"
          class="px-2.5 py-1.5 transition"
          [class.bg-slate-900]="locale.locale() === 'en'"
          [class.text-white]="locale.locale() === 'en'"
          [class.text-slate-500]="locale.locale() !== 'en'"
          (click)="locale.setLocale('en')"
        >
          EN
        </button>
        <button
          type="button"
          class="px-2.5 py-1.5 transition"
          [class.bg-slate-900]="locale.locale() === 'de'"
          [class.text-white]="locale.locale() === 'de'"
          [class.text-slate-500]="locale.locale() !== 'de'"
          (click)="locale.setLocale('de')"
        >
          DE
        </button>
      </div>
      @if (SHOW_ACCOUNT_BADGE) {
        @if (auth.currentUser(); as user) {
          <a
            routerLink="/account"
            class="flex items-center gap-2 rounded-full border border-white/50 bg-white/95 py-1.5 pl-1.5 pr-3 text-sm shadow-lg backdrop-blur-sm transition hover:bg-white"
          >
            @if (user.avatarUrl) {
              <img [src]="user.avatarUrl" alt="" class="h-6 w-6 rounded-full" />
            } @else {
              <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
                {{ user.name.charAt(0) }}
              </span>
            }
            <span class="font-medium text-slate-800">{{ user.wallet?.balance ?? 0 }} {{ i18n.t('credits') }}</span>
          </a>
        } @else if (auth.loaded()) {
          <a
            [href]="auth.signInUrl"
            class="rounded-full border border-white/50 bg-white/95 px-3 py-1.5 text-sm font-medium text-slate-800 shadow-lg backdrop-blur-sm transition hover:bg-white"
          >
            {{ i18n.t('signInWithGoogle') }}
          </a>
        }
      }
    </div>
  `,
  imports: [RouterLink],
})
export class AccountBadgeComponent {
  /** Owner's call, 2026-09-01: sign-in/credits chip hidden until "napredna pretraga" (advanced
   *  search, the actual credit-gated feature) ships — nothing currently gates on login (see this
   *  class's own docblock), so showing "Sign in" / a credits count promises a feature that isn't
   *  live yet. EN/DE switch stays visible regardless — unrelated to login. Flip back to true once
   *  advanced search is ready; the auth/credits machinery underneath is untouched, only display
   *  is suppressed. */
  protected readonly SHOW_ACCOUNT_BADGE = false;

  constructor(
    public auth: AuthService,
    public i18n: I18nService,
    public locale: LocaleService
  ) {}
}
