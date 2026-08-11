import { Component } from '@angular/core';

let nextId = 0;

/**
 * Small "i" icon button that reveals its projected content in a popover — native HTML
 * `popover` API gives free light-dismiss (click outside closes it) and Esc-to-close with no JS,
 * plus an explicit X per the owner's ask. Works identically on mobile and desktop, unlike the
 * `hidden lg:block` side column it replaces for the wizard's step explainer (2026-08-11) — that
 * column was invisible below the lg breakpoint, which was the actual cause of "uputstvo ne
 * postoji na mobilnom". `[attr.X]` bindings throughout (not property bindings) since `popover`/
 * `popovertarget` are new HTML attributes Angular's template checker doesn't know as DOM
 * properties yet.
 */
@Component({
  selector: 'ui-info-popover',
  standalone: true,
  template: `
    <button
      type="button"
      [attr.popovertarget]="id"
      class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-500 hover:border-slate-400 hover:text-slate-700"
      aria-label="More info"
    >
      i
    </button>
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
        aria-label="Close"
      >
        &times;
      </button>
      <ng-content></ng-content>
    </div>
  `,
})
export class InfoPopoverComponent {
  readonly id = `info-popover-${nextId++}`;
}
