import { Component, input, output } from '@angular/core';
import { SpinnerComponent } from './spinner';

@Component({
  selector: 'ui-button',
  standalone: true,
  imports: [SpinnerComponent],
  template: `
    <button
      type="button"
      class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium
             transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2
             disabled:cursor-not-allowed disabled:opacity-50"
      [class]="variantClasses()"
      [disabled]="disabled() || loading()"
      (click)="onClick()"
    >
      @if (loading()) {
        <ui-spinner size="sm" />
      }
      <ng-content />
    </button>
  `,
})
export class ButtonComponent {
  variant = input<'primary' | 'secondary'>('primary');
  disabled = input(false);
  loading = input(false);
  clicked = output<void>();

  variantClasses(): string {
    // Warm amber primary, 2026-08-06 (owner's call, "boja za proceed treba da se promeni") —
    // the old bg-slate-900 read flat/corporate against the sunset photo backdrop now behind
    // every screen; amber matches the sun emoji / hero palette instead.
    return this.variant() === 'primary'
      ? 'bg-amber-500 text-slate-900 hover:bg-amber-400 focus:ring-amber-300'
      : 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-300';
  }

  onClick(): void {
    if (!this.disabled() && !this.loading()) {
      this.clicked.emit();
    }
  }
}
