import { AfterViewInit, Component, ElementRef, OnInit, signal, ViewChild } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { AccountBadgeComponent } from './features/account/account-badge';
import { AuthService } from './core/auth.service';
import { AnalyticsService } from './core/analytics.service';
import { ScrollContainerService } from './core/scroll-container.service';
import { FooterComponent } from './ui/footer';
import { CookieConsentComponent } from './ui/cookie-consent';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, AccountBadgeComponent, FooterComponent, CookieConsentComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss'
})
export class App implements OnInit, AfterViewInit {
  protected readonly title = signal('frontend');

  // See ScrollContainerService's docblock — WizardComponent reads this to replace what used to
  // be plain `window.scrollY`/`scrollTo` calls, 2026-09-05.
  @ViewChild('scrollContainer') private scrollContainerRef?: ElementRef<HTMLElement>;

  constructor(
    private auth: AuthService,
    private analytics: AnalyticsService,
    private scrollContainerService: ScrollContainerService
  ) {}

  // Fire-and-forget, app-wide — same "never block the visible page" convention as
  // WizardService.detectHomeCity. See AuthService/AccountBadgeComponent, 2026-08-10.
  ngOnInit(): void {
    void this.auth.loadCurrentUser();

    // recordVisit fires unconditionally (no cookie, no stored IP — see AnalyticsService's
    // docblock); initIfConsented only loads the actual Pixel if a prior visit already accepted
    // the cookie banner — 2026-09-05.
    this.analytics.recordVisit(window.location.pathname);
    this.analytics.initIfConsented();
  }

  ngAfterViewInit(): void {
    this.scrollContainerService.container.set(this.scrollContainerRef?.nativeElement ?? null);
  }
}
