import { Component, input, output } from '@angular/core';
import { SpinnerComponent } from './spinner';

@Component({
  selector: 'ui-button',
  standalone: true,
  imports: [SpinnerComponent],
  template: `
    <button
      type="button"
      class="inline-flex items-center justify-center gap-2 rounded-lg font-medium
             transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2
             disabled:cursor-not-allowed disabled:border-0 disabled:bg-slate-300 disabled:text-slate-500"
      [class]="variantClasses() + ' ' + sizeClasses()"
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
  /** 'lg' for a screen's single primary CTA (e.g. the campaign intro) — owner's ask,
   *  2026-08-11: the default size read as small/lightweight next to a big hero heading,
   *  "malo pritegni izgled". Every other button (Back/Proceed/Change/...) stays 'md'. */
  size = input<'md' | 'lg'>('md');
  disabled = input(false);
  loading = input(false);
  clicked = output<void>();

  sizeClasses(): string {
    return this.size() === 'lg' ? 'px-8 py-3.5 text-base font-semibold' : 'px-4 py-2 text-sm';
  }

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
