import { Component, OnInit, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { WizardService } from '../../core/wizard.service';

interface AmenityOption {
  slug: string;
  label: string;
  type: string;
}

/** The four taxonomy types Big YES/NO searches across — see WizardSeeder::seedAmenities()
 *  and wizard_architecture, 2026-08-04. Deliberately NOT routed through the generic
 *  loadGeographyForCurrentStep() (single taxonomyType per question) — this widget owns its
 *  own combined fetch instead. */
const AMENITY_TYPES = ['tip_smestaja', 'accommodation_facility', 'room_facility', 'meal_plan'];

const MAX_SUGGESTIONS = 6;

/**
 * "Big YES / Big NO" amenity picker — owner's design, 2026-08-04: type a few characters,
 * pick a suggestion (or press Enter to accept the top one), it becomes a pill. Two
 * independent lists (yes/no) sharing one combined vocabulary; picking something in one list
 * removes it from the other's suggestions (can't want AND avoid the same thing). Typed text
 * that matches nothing becomes free text instead (see unmatchedText) — never silently lost.
 *
 * Replaces reading long checkbox lists with ~3 keystrokes per thing that matters — owner's
 * own framing: "lakse da ukuca ukupno 15 karaktera... nego da cita opise, tekstove i sl."
 */
@Component({
  selector: 'app-amenity-picker',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './amenity-picker.html',
})
export class AmenityPickerComponent implements OnInit {
  yesSlugs = input<string[]>([]);
  noSlugs = input<string[]>([]);

  yesChange = output<string[]>();
  noChange = output<string[]>();
  /** Typed text that matched nothing in the combined vocabulary — never dropped. `isAvoid`
   *  tells the parent which fallback field to route it to (positive wishlist vs. avoid-notes),
   *  since the two read with opposite framing. */
  unmatchedText = output<{ text: string; isAvoid: boolean }>();

  readonly allOptions = signal<AmenityOption[]>([]);
  readonly yesQuery = signal('');
  readonly noQuery = signal('');

  /** Purely visual confirmation for unmatched text — see flushUnmatched. Bug fixed 2026-08-04:
   *  typing something with no taxonomy match (e.g. "Crowd" in the NO box) DID get captured
   *  (routed to smestaj_avoid, invisible field), but with zero on-screen confirmation it read
   *  as "vanished" ("ukucao Crowd, pritisnuo Enter, nema ga nidje"). These render as inert
   *  (non-removable) chips, visually distinct from real taxonomy pills, so typing something
   *  always visibly lands SOMEWHERE. */
  readonly customYes = signal<string[]>([]);
  readonly customNo = signal<string[]>([]);

  constructor(private wizard: WizardService) {}

  async ngOnInit(): Promise<void> {
    const results = await Promise.all(AMENITY_TYPES.map((type) => this.wizard.loadGeographyOptions(type)));
    this.allOptions.set(
      results.flatMap((nodes, i) => nodes.map((n) => ({ slug: n.slug, label: n.label, type: AMENITY_TYPES[i] })))
    );
  }

  labelFor(slug: string): string {
    return this.allOptions().find((o) => o.slug === slug)?.label ?? slug;
  }

  yesSuggestions(): AmenityOption[] {
    return this.suggestionsFor(this.yesQuery(), this.yesSlugs(), this.noSlugs());
  }

  noSuggestions(): AmenityOption[] {
    return this.suggestionsFor(this.noQuery(), this.noSlugs(), this.yesSlugs());
  }

  private suggestionsFor(query: string, ownSlugs: string[], otherSlugs: string[]): AmenityOption[] {
    const q = query.trim().toLowerCase();
    if (q.length === 0) return [];

    return this.allOptions()
      .filter((o) => !ownSlugs.includes(o.slug) && !otherSlugs.includes(o.slug))
      .filter((o) => o.label.toLowerCase().includes(q))
      .sort((a, b) => {
        const aStarts = a.label.toLowerCase().startsWith(q) ? 0 : 1;
        const bStarts = b.label.toLowerCase().startsWith(q) ? 0 : 1;
        return aStarts - bStarts;
      })
      .slice(0, MAX_SUGGESTIONS);
  }

  addYes(slug: string): void {
    this.yesChange.emit([...this.yesSlugs(), slug]);
    this.yesQuery.set('');
  }

  addNo(slug: string): void {
    this.noChange.emit([...this.noSlugs(), slug]);
    this.noQuery.set('');
  }

  removeYes(slug: string): void {
    this.yesChange.emit(this.yesSlugs().filter((s) => s !== slug));
  }

  removeNo(slug: string): void {
    this.noChange.emit(this.noSlugs().filter((s) => s !== slug));
  }

  /** Enter with at least one live suggestion accepts the top one (owner's spec: "mi ponudimo
   *  beach, on prihvati, enter") — otherwise the typed text has no taxonomy match at all, so
   *  it's handed off as free text rather than silently dropped. Bug fixed 2026-08-04: unmatched
   *  YES and NO text used to land in the SAME free-text field, which reads backwards for NO
   *  ("wishlist: Crowd, Loud" sounds like they're wanted, not avoided) — now tagged so the
   *  parent can route each to a field with the right framing. */
  onYesEnter(): void {
    const top = this.yesSuggestions()[0];
    if (top) {
      this.addYes(top.slug);
      return;
    }
    this.flushUnmatched(this.yesQuery(), false);
    this.yesQuery.set('');
  }

  onNoEnter(): void {
    const top = this.noSuggestions()[0];
    if (top) {
      this.addNo(top.slug);
      return;
    }
    this.flushUnmatched(this.noQuery(), true);
    this.noQuery.set('');
  }

  private flushUnmatched(raw: string, isAvoid: boolean): void {
    const text = raw.trim();
    if (text.length === 0) return;

    this.unmatchedText.emit({ text, isAvoid });
    if (isAvoid) {
      this.customNo.update((c) => [...c, text]);
    } else {
      this.customYes.update((c) => [...c, text]);
    }
  }
}
