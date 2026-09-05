import { Injectable, signal } from '@angular/core';

/**
 * Holds a reference to the app shell's own internally-scrolling region (see app.html,
 * 2026-09-05 — "stavi skroler na nivo chata"). Registered once by App (an ancestor of every
 * routed component) and read by WizardComponent, which needs to read/control scroll position
 * for its own UX (auto-scroll to the newest question, lock scroll during the Booking redirect
 * transition) — those used to target `window` directly back when the whole page scrolled, but
 * now the actual scrolling element is this nested container instead.
 */
@Injectable({ providedIn: 'root' })
export class ScrollContainerService {
  readonly container = signal<HTMLElement | null>(null);
}
