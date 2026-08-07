import { Component, input, output } from '@angular/core';

export interface TravelersValue {
  adultsCount: number | null;
  childrenAges: number[];
  needsCrib: boolean[];
}

/**
 * Combined adults/children/crib widget — replaces the old comma-separated-text children_ages
 * field and single blanket needs_crib checkbox with a proper add/remove-child list and a
 * per-child crib toggle (index-aligned with childrenAges, matching the backend's
 * `needs_crib: boolean[]` shape). Owner's explicit ask, 2026-07-30: "broj ljudi... dinamicki,
 * kao i creeb, na 1 strani." Used on the "broj_putnika" step in BOTH the generic and
 * campaign-mode flows (see WizardComponent) — nothing campaign-specific here.
 */
@Component({
  selector: 'app-travelers-input',
  standalone: true,
  templateUrl: './travelers-input.html',
})
export class TravelersInputComponent {
  /** Bug fixed 2026-08-06: the header used to be hardcoded in travelers-input.html, so editing
   *  the adults_count question's label in WizardSeeder had no visible effect. Now driven by
   *  the same seeded label as every other question — the default here only covers a render
   *  before the parent has the question loaded. */
  adultsLabel = input('How many adults are traveling?');

  adultsCount = input<number | null>(null);
  childrenAges = input<number[]>([]);
  needsCrib = input<boolean[]>([]);

  valueChange = output<TravelersValue>();

  incrementAdults(): void {
    // null (unanswered) displays as 1 in the template's `?? 1` fallback — must increment FROM
    // that same displayed baseline, or the first click silently no-ops (null -> 0+1 -> 1 again,
    // no visible change). Bug caught 2026-07-30 by owner testing.
    this.emit((this.adultsCount() ?? 1) + 1, this.childrenAges(), this.needsCrib());
  }

  decrementAdults(): void {
    this.emit(Math.max(1, (this.adultsCount() ?? 1) - 1), this.childrenAges(), this.needsCrib());
  }

  addChild(): void {
    this.emit(this.adultsCount(), [...this.childrenAges(), 5], [...this.needsCrib(), false]);
  }

  removeChild(index: number): void {
    this.emit(
      this.adultsCount(),
      this.childrenAges().filter((_, i) => i !== index),
      this.needsCrib().filter((_, i) => i !== index)
    );
  }

  /** Changing a child's age auto-sets their crib default (true at <=2, false above) — still
   *  user-editable via onCribToggle afterward, this is just a sane starting point. */
  onChildAgeChange(index: number, raw: string): void {
    const age = Number(raw);
    const ages = this.childrenAges().slice();
    ages[index] = Number.isNaN(age) ? 0 : age;

    const cribs = this.needsCrib().slice();
    cribs[index] = ages[index] <= 2;

    this.emit(this.adultsCount(), ages, cribs);
  }

  onCribToggle(index: number, checked: boolean): void {
    const cribs = this.needsCrib().slice();
    cribs[index] = checked;
    this.emit(this.adultsCount(), this.childrenAges(), cribs);
  }

  private emit(adultsCount: number | null, childrenAges: number[], needsCrib: boolean[]): void {
    this.valueChange.emit({ adultsCount, childrenAges, needsCrib });
  }
}
