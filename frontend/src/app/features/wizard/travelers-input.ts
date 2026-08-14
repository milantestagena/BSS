import { Component, input, output } from '@angular/core';
import { I18nService } from '../../core/i18n.service';
import { NumberStepperComponent } from '../../ui/number-stepper';

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
  imports: [NumberStepperComponent],
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

  constructor(public i18n: I18nService) {}

  /** Owner's catch, 2026-08-14: the "Children" section header stayed plural even with exactly
   *  one child listed — same class of singular/plural mismatch as the destination group
   *  headers. childCount/childrenCount already exist (lowercase, built for mid-sentence use
   *  like "2 children") — capitalized here for use as a standalone label instead of adding a
   *  near-duplicate key pair. */
  get childrenSectionLabel(): string {
    const label = this.childrenAges().length === 1 ? this.i18n.t('childCount') : this.i18n.t('childrenCount');
    return label.charAt(0).toUpperCase() + label.slice(1);
  }

  /** ui-number-stepper's min=1 already clamps every path (buttons AND typing) — the `?? 1`
   *  fallback here only covers the brief instant the input is emptied while typing a
   *  replacement value. */
  onAdultsChange(value: number | null): void {
    this.emit(value ?? 1, this.childrenAges(), this.needsCrib());
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
   *  user-editable via onCribToggle afterward, this is just a sane starting point.
   *  ui-number-stepper's min=0/max=17 already clamps every path — the `?? 0` fallback here only
   *  covers the brief instant the input is emptied while typing a replacement value. */
  onChildAgeStepperChange(index: number, value: number | null): void {
    this.setChildAge(index, value ?? 0);
  }

  private setChildAge(index: number, age: number): void {
    const ages = this.childrenAges().slice();
    ages[index] = age;

    const cribs = this.needsCrib().slice();
    cribs[index] = age <= 2;

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
