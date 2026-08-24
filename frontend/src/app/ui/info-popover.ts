import { Component, input } from '@angular/core';
import { I18nService } from '../core/i18n.service';

let nextId = 0;

/**
 * Reveals its projected content in a popover — native HTML `popover` API gives free
 * light-dismiss (click outside closes it) and Esc-to-close with no JS, plus an explicit X per
 * the owner's ask. Works identically on mobile and desktop, unlike the `hidden lg:block` side
 * column it replaces for the wizard's step explainer (2026-08-11) — that column was invisible
 * below the lg breakpoint, which was the actual cause of "uputstvo ne postoji na mobilnom".
 * `[attr.X]` bindings throughout (not property bindings) since `popover`/`popovertarget` are new
 * HTML attributes Angular's template checker doesn't know as DOM properties yet.
 *
 * Default trigger is the small "i" icon button. Owner's ask, 2026-08-24: the destination cards'
 * cramped top-right corner (info icon + Superstar star fighting for the same spot) needed a
 * different trigger there — `customTrigger` input swaps the "i" button out for projected
 * `[trigger]` content (e.g. the card's own 2-line-clamped description), same popover underneath.
 */
@Component({
  selector: 'ui-info-popover',
  standalone: true,
  template: `
    @if (customTrigger()) {
      <button type="button" [attr.popovertarget]="id" class="block w-full text-left">
        <ng-content select="[trigger]"></ng-content>
      </button>
    } @else {
      <button
        type="button"
        [attr.popovertarget]="id"
        class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-500 hover:border-slate-400 hover:text-slate-700"
        [attr.aria-label]="i18n.t('moreInfo')"
      >
        i
      </button>
    }
    <div
      [id]="id"
      [attr.popover]="'auto'"
      class="fixed left-1/2 top-[15vh] w-[min(90vw,22rem)] max-h-[70vh] -translate-x-1/2 overflow-y-auto
             rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-relaxed text-slate-600 shadow-xl"
    >
      <button
        type="button"
        [attr.popovertarget]="id"
        [attr.popovertargetaction]="'hide'"
        class="float-right -mr-1 -mt-1 text-base leading-none text-slate-400 hover:text-slate-600"
        [attr.aria-label]="i18n.t('close')"
      >
        &times;
      </button>
      <ng-content></ng-content>
    </div>
  `,
})
export class InfoPopoverComponent {
  readonly id = `info-popover-${nextId++}`;
  customTrigger = input(false);

  constructor(public i18n: I18nService) {}
}
