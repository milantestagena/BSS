import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  ViewChild,
  effect,
  input,
  model,
} from '@angular/core';
import flatpickr from 'flatpickr';
import type { Instance as FlatpickrInstance } from 'flatpickr/dist/types/instance';

@Component({
  selector: 'ui-date-field',
  standalone: true,
  template: `
    <input
      #inputEl
      type="text"
      [placeholder]="placeholder()"
      class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm
             focus:outline-none focus:ring-2 focus:ring-slate-400 focus:border-slate-400"
    />
  `,
})
export class DateFieldComponent implements AfterViewInit, OnDestroy {
  @ViewChild('inputEl') inputEl!: ElementRef<HTMLInputElement>;

  // English default (canonical) — question-input.html always overrides this with the
  // locale-aware i18n string. Was hardcoded Serbian until 2026-08-11 German-language work.
  placeholder = input('Choose a date');
  /** ISO date string (YYYY-MM-DD), or '' when unset. */
  value = model<string>('');

  private picker: FlatpickrInstance | null = null;

  /** Bug fixed 2026-09-03 (owner caught it live: the recommended-date prefill wrote the right
   *  value into the session/answer, real end-to-end, but the calendar field itself stayed blank)
   *  — flatpickr only ever reads `value()` once, at `ngAfterViewInit` (`defaultDate: this.value()`
   *  below), which is BEFORE a prefill written a tick or more later (goNext's async
   *  loadGeographyForCurrentStep -> prefillRecommendedDates chain) has any value to read yet.
   *  This effect re-syncs the picker's own displayed date whenever `value` changes from
   *  OUTSIDE the picker (prefill, or a parent overwriting it) — `false` as setDate's second arg
   *  skips firing onChange, so this can never loop back into a write of its own. Runs once
   *  before ngAfterViewInit too (picker still null then, no-op, guarded by `?.`). */
  constructor() {
    effect(() => {
      // setDate()'s type (unlike defaultDate's) doesn't accept undefined — an empty string
      // clears the field just fine, no need for the same `|| undefined` fallback used below.
      this.picker?.setDate(this.value(), false);
    });
  }

  ngAfterViewInit(): void {
    this.picker = flatpickr(this.inputEl.nativeElement, {
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'd.m.Y',
      defaultDate: this.value() || undefined,
      onChange: (_dates, dateStr) => this.value.set(dateStr),
    }) as FlatpickrInstance;
  }

  ngOnDestroy(): void {
    this.picker?.destroy();
  }
}
