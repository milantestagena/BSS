import { Component, OnInit, signal } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { I18nService } from '../../core/i18n.service';

/** MVP account page — name/email/credit balance + share-and-earn link. See
 *  AuthService/AccountBadgeComponent. */
@Component({
  selector: 'app-account-page',
  standalone: true,
  template: `
    <div class="mx-auto max-w-md px-4 py-16">
      @if (auth.currentUser(); as user) {
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div class="mb-4 flex items-center gap-3">
            @if (user.avatarUrl) {
              <img [src]="user.avatarUrl" alt="" class="h-12 w-12 rounded-full" />
            } @else {
              <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-200 text-lg font-semibold text-slate-600">
                {{ user.name.charAt(0) }}
              </span>
            }
            <div>
              <p class="font-semibold text-slate-900">{{ user.name }}</p>
              <p class="text-sm text-slate-500">{{ user.email }}</p>
            </div>
          </div>

          <div class="rounded-xl bg-sky-50 p-4 text-center">
            <p class="text-3xl font-bold text-sky-900">{{ user.wallet?.balance ?? 0 }}</p>
            <p class="text-sm text-sky-700">{{ i18n.t('aiCreditsRemaining') }}</p>
          </div>

          <a [href]="auth.signOutUrl" class="mt-6 block text-center text-sm text-slate-400 hover:text-slate-600 hover:underline">
            {{ i18n.t('signOut') }}
          </a>
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <p class="font-semibold text-slate-900">{{ i18n.t('shareAndEarn') }}</p>
          <p class="mt-1 text-sm text-slate-500">
            {{ i18n.t('shareAndEarnSubtitle') }}
          </p>
          <div class="mt-3 flex items-center gap-2">
            <input
              type="text"
              readonly
              [value]="shareLink"
              class="w-full truncate rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-600"
            />
            <button
              type="button"
              (click)="share()"
              class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
            >
              {{ copied() ? i18n.t('copied') : canNativeShare ? i18n.t('share') : i18n.t('copy') }}
            </button>
          </div>
        </div>
      } @else if (auth.loaded()) {
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
          <p class="mb-4 text-sm text-slate-500">{{ i18n.t('notSignedIn') }}</p>
          <a
            [href]="auth.signInUrl"
            class="inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
          >
            {{ i18n.t('signInWithGoogle') }}
          </a>
        </div>
      }
    </div>
  `,
})
export class AccountPageComponent implements OnInit {
  readonly copied = signal(false);

  constructor(
    public auth: AuthService,
    public i18n: I18nService
  ) {}

  ngOnInit(): void {
    if (!this.auth.loaded()) {
      void this.auth.loadCurrentUser();
    }
  }

  /** `u<id>` — matches GoogleAuthController's self-ref format exactly, see User::referralCode(). */
  get shareLink(): string {
    const id = this.auth.currentUser()?.id;
    return `${location.origin}/?ref=u${id}`;
  }

  /** Web Share API — supported on iOS/Android Safari+Chrome and most desktop browsers except
   *  Firefox. Where available it opens the OS's native share sheet (WhatsApp, Instagram, SMS,
   *  Messenger, email, whatever the person actually has installed) instead of us hardcoding a
   *  handful of specific social platforms. Falls back to copy-to-clipboard everywhere else. */
  get canNativeShare(): boolean {
    return typeof navigator !== 'undefined' && 'share' in navigator;
  }

  share(): void {
    if (this.canNativeShare) {
      navigator
        .share({
          title: 'TripInele',
          text: 'Planning a late-summer swim trip? This wizard finds you a spot — and you get free AI credits if you use my link.',
          url: this.shareLink,
        })
        .catch(() => {
          // User cancelled the native share sheet — not an error, nothing to do.
        });
      return;
    }

    void navigator.clipboard.writeText(this.shareLink).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }
}
