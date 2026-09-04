import { Injectable } from '@angular/core';
import { GraphqlService } from './graphql.service';

const CONSENT_STORAGE_KEY = 'tripinele-cookie-consent';
const META_PIXEL_ID = '1432078978797736';

const RECORD_PAGE_VISIT_MUTATION = `
  mutation RecordPageVisit($path: String!) {
    recordPageVisit(path: $path)
  }
`;

export type ConsentChoice = 'accepted' | 'declined';

interface FbqShim {
  (...args: unknown[]): void;
  callMethod?: (...args: unknown[]) => void;
  queue: unknown[][];
  push: FbqShim;
  loaded: boolean;
  version: string;
}

/**
 * Owns the Meta Pixel's lifecycle and the always-on (no-cookie) page-visit log — split into two
 * because they have different consent requirements: the Pixel sets third-party tracking cookies
 * and only loads after explicit consent (see CookieConsentComponent); recordPageVisit stores no
 * cookie and no IP (resolved server-side to a city/country, then discarded — see
 * PageVisitResolver), so it's a plain visit counter, not "tracking" in the consent-banner sense,
 * and fires unconditionally.
 */
@Injectable({ providedIn: 'root' })
export class AnalyticsService {
  constructor(private gql: GraphqlService) {}

  /** Reads the stored consent choice, if the visitor has already answered — null if the banner
   *  hasn't been shown/answered yet this browser. */
  getStoredConsent(): ConsentChoice | null {
    const stored = localStorage.getItem(CONSENT_STORAGE_KEY);
    return stored === 'accepted' || stored === 'declined' ? stored : null;
  }

  setConsent(choice: ConsentChoice): void {
    localStorage.setItem(CONSENT_STORAGE_KEY, choice);
    if (choice === 'accepted') {
      this.loadPixel();
    }
  }

  /** Call once, at app bootstrap — loads the Pixel immediately only if consent was already
   *  granted on a previous visit; otherwise waits for CookieConsentComponent to call
   *  setConsent('accepted'). */
  initIfConsented(): void {
    if (this.getStoredConsent() === 'accepted') {
      this.loadPixel();
    }
  }

  /** Best-effort, fire-and-forget — see recordPageVisit's docblock (backend). Never blocks or
   *  throws into the caller; a failed network request here must never affect the real page. */
  recordVisit(path: string): void {
    void this.gql.request(RECORD_PAGE_VISIT_MUTATION, { path }).catch(() => {
      // Best-effort only.
    });
  }

  /**
   * Faithful port of Meta's own base Pixel snippet, just wrapped so it only ever runs after
   * explicit consent instead of unconditionally at page load (that's the whole reason this
   * moved out of a static index.html script tag, 2026-09-05). `fbq`'s own internal queue
   * (`fbq.queue`, populated until `callMethod` exists) means init/track/any later
   * Wizard.trackPixelEvent() call all queue correctly regardless of exactly when
   * fbevents.js finishes downloading — no separate queue of our own needed.
   */
  private loadPixel(): void {
    const w = window as unknown as { fbq?: FbqShim; _fbq?: FbqShim };
    if (w.fbq) return;

    const fbq = ((...args: unknown[]) => {
      if (fbq.callMethod) {
        fbq.callMethod(...args);
      } else {
        fbq.queue.push(args);
      }
    }) as FbqShim;
    fbq.queue = [];
    fbq.push = fbq;
    fbq.loaded = true;
    fbq.version = '2.0';

    w.fbq = fbq;
    w._fbq ??= fbq;

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    document.head.appendChild(script);

    fbq('init', META_PIXEL_ID);
    fbq('track', 'PageView');
  }
}
