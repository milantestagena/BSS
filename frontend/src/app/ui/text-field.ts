import { Component, input, model } from '@angular/core';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'ui-text-field',
  standalone: true,
  imports: [FormsModule],
  template: `
    @if (multiline()) {
      <textarea
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
               focus:outline-none focus:ring-2 focus:ring-slate-400 focus:border-slate-400
               disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
        rows="3"
        [placeholder]="placeholder()"
        [disabled]="disabled()"
        [(ngModel)]="value"
      ></textarea>
    } @else {
      <input
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm
               [appearance:textfield] focus:outline-none focus:ring-2 focus:ring-slate-400
               focus:border-slate-400 [&::-webkit-inner-spin-button]:appearance-none
               [&::-webkit-outer-spin-button]:appearance-none
               disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
        [type]="type()"
        [step]="step()"
        [placeholder]="placeholder()"
        [disabled]="disabled()"
        [(ngModel)]="value"
      />
    }
  `,
})
export class TextFieldComponent {
  type = input<'text' | 'number'>('text');
  /** Only meaningful for type="number" — e.g. a budget field jumping by 100 instead of 1 on
   *  the native spinner arrows. See question-input.html for the per-question override. */
  step = input<string>('1');
  placeholder = input('');
  multiline = input(false);
  disabled = input(false);
  value = model<string>('');
}
