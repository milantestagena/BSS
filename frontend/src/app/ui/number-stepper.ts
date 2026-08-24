import { Component, input, output } from '@angular/core';
import { TextFieldComponent } from './text-field';

/**
 * Shared [-] [input] [+] number control — owner's ask, 2026-08-14: "da imamo jasno formatiran
 * elemenat sa inputom... da ako menjamo na 1 mestu da menjamo" (one shared component, not
 * separately hand-built markup per field). Extracted from question-input.html's total_budget
 * case (the original +/- + visible-input pattern, 2026-08-13 — native spinner arrows are
 * CSS-hidden project-wide, see ui-text-field, so a real number question needs its own visible
 * buttons). Now also used for adults count and a child's age — clamps to [min, max] on every
 * path (buttons AND typing), null value displays as an empty field rather than "0".
 */
@Component({
  selector: 'ui-number-stepper',
  standalone: true,
  imports: [TextFieldComponent],
  template: `
    <div class="inline-flex items-center gap-2">
      <button
        type="button"
        class="h-9 w-9 shrink-0 rounded-lg border border-slate-300 bg-white text-lg font-medium text-slate-700 hover:bg-slate-50"
        (click)="decrement()"
      >
        −
      </button>
      <div class="w-20">
        <ui-text-field type="number" [step]="stepAttr" [value]="valueAsString" (valueChange)="onInputChange($event)" />
      </div>
      <button
        type="button"
        class="h-9 w-9 shrink-0 rounded-lg border border-slate-300 bg-white text-lg font-medium text-slate-700 hover:bg-slate-50"
        (click)="increment()"
      >
        +
      </button>
    </div>
  `,
})
export class NumberStepperComponent {
  value = input<number | null>(null);
  min = input(0);
  /** null = no ceiling (e.g. adults count, total_budget). */
  max = input<number | null>(null);
  step = input(1);

  valueChange = output<number | null>();

  get stepAttr(): string {
    return String(this.step());
  }

  get valueAsString(): string {
    return this.value() == null ? '' : String(this.value());
  }

  onInputChange(raw: string): void {
    if (raw === '') {
      this.valueChange.emit(null);
      return;
    }

    const parsed = Number(raw);
    this.valueChange.emit(Number.isNaN(parsed) ? null : this.clamp(parsed));
  }

  increment(): void {
    this.valueChange.emit(this.clamp((this.value() ?? this.min()) + this.step()));
  }

  decrement(): void {
    this.valueChange.emit(this.clamp((this.value() ?? this.min()) - this.step()));
  }

  private clamp(next: number): number {
    const max = this.max();
    const floored = Math.max(this.min(), next);

    return max == null ? floored : Math.min(max, floored);
  }
}
