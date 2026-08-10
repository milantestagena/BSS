import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';

/**
 * Persistent, always-visible account status — see AuthService docblock. Deliberately NOT a
 * gate on anything yet (CLAUDE.md section 3: login only gates step 8) — just lets a visitor
 * see/reach their account, and gives Google's OAuth consent screen a clear, discoverable entry
 * point rather than a bare URL only I know about. 2026-08-10.
 */
@Component({
  selector: 'app-account-badge',
  standalone: true,
  template: `
    <div class="fixed right-4 top-4 z-30">
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
          <span class="font-medium text-slate-800">{{ user.wallet?.balance ?? 0 }} credits</span>
        </a>
      } @else if (auth.loaded()) {
        <a
          [href]="auth.signInUrl"
          class="rounded-full border border-white/50 bg-white/95 px-3 py-1.5 text-sm font-medium text-slate-800 shadow-lg backdrop-blur-sm transition hover:bg-white"
        >
          Sign in with Google
        </a>
      }
    </div>
  `,
  imports: [RouterLink],
})
export class AccountBadgeComponent {
  constructor(public auth: AuthService) {}
}
