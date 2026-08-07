import { Component, input, output } from '@angular/core';

@Component({
  selector: 'ui-choice',
  standalone: true,
  template: `
    <button
      type="button"
      [disabled]="disabled()"
      class="inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium
             transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-slate-400"
      [class]="disabled()
        ? 'bg-slate-900/70 text-white border-slate-900/70 cursor-not-allowed'
        : selected()
          ? 'bg-slate-900 text-white border-slate-900'
          : 'bg-white text-slate-700 border-slate-300 hover:border-slate-400 hover:bg-slate-50'"
      (click)="onClick()"
    >
      <ng-content />
      @if (disabled()) {
        <span class="text-xs opacity-75" title="Već podrazumevano uključeno na osnovu prethodnog odgovora">🔒</span>
      } @else if (score(); as s) {
        <span class="text-xs opacity-60">({{ s }})</span>
      }
    </button>
  `,
})
export class ChoiceComponent {
  selected = input(false);
  score = input<number | null>(null);
  /** Forced on by an `implies` relation from an earlier answer (e.g. Foodie -> Great food) —
   *  shown selected and locked instead of hidden, so the user sees WHY it's assumed rather
   *  than it silently vanishing from the list. Owner's call, 2026-08-04. */
  disabled = input(false);
  toggled = output<void>();

  onClick(): void {
    if (!this.disabled()) {
      this.toggled.emit();
    }
  }
}
