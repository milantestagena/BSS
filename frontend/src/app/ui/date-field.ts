import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  ViewChild,
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
