import { Component, input } from '@angular/core';

@Component({
  selector: 'ui-spinner',
  standalone: true,
  template: `
    <svg
      class="animate-spin"
      [class.h-4]="size() === 'sm'"
      [class.w-4]="size() === 'sm'"
      [class.h-6]="size() === 'md'"
      [class.w-6]="size() === 'md'"
      viewBox="0 0 24 24"
      fill="none"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  `,
})
export class SpinnerComponent {
  size = input<'sm' | 'md'>('sm');
}
