import { Component, OnInit, effect, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { WizardService } from '../../core/wizard.service';
import { I18nService } from '../../core/i18n.service';
import { LocaleService } from '../../core/locale.service';

interface AmenityOption {
  slug: string;
  label: string;
  type: string;
}

/** The taxonomy types Big YES searches across — see WizardSeeder::seedAmenities() and
 *  wizard_architecture, 2026-08-04. Deliberately NOT routed through the generic
 *  loadGeographyForCurrentStep() (single taxonomyType per question) — this widget owns its
 *  own combined fetch instead.
 *  `meal_plan` moved OUT 2026-08-13 — it's now its own direct question on the broj_putnika
 *  step (see WizardSeeder's meal_plan_preference) instead of a budget-ratio guess pre-filled
 *  in here; picking it in two different places in the flow would just be confusing.
 *  `stay_type` added 2026-08-24 (Pets allowed) — unlike meal_plan it has no dedicated question
 *  of its own, so it belongs in this general combined vocabulary like the other facility types. */
const AMENITY_TYPES = ['tip_smestaja', 'accommodation_facility', 'room_facility', 'stay_type'];

const MAX_SUGGESTIONS = 6;

/**
 * "Big YES" amenity picker — owner's design, 2026-08-04: type a few characters, pick a
 * suggestion (or press Enter to accept the top one), it becomes a pill. Typed text that
 * matches nothing becomes free text instead (see unmatchedText) — never silently lost.
 *
 * Replaces reading long checkbox lists with ~3 keystrokes per thing that matters — owner's
 * own framing: "lakse da ukuca ukupno 15 karaktera... nego da cita opise, tekstove i sl."
 *
 * Owner's ask, 2026-08-24: the "Big NO" half (an avoid-list, its own text input + suggestions)
 * was removed — it never drove a real Booking filter, only fed Honest Report's avoid_amenities
 * signal, and Honest Report hasn't been wired into the live results screen since the mock hotel
 * cards were replaced with a real Booking.com link (see wizard.ts's goNext). Dead UI gated
 * behind login/credits for a feature nobody could see. If Honest Report ever gets a real
 * listing-data source and comes back, Big NO can come back with it — the backend signal
 * (SearchSessionQueryCompiler's avoid_amenities/smestaj_avoid) was left untouched.
 */
@Component({
  selector: 'app-amenity-picker',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './amenity-picker.html',
})
export class AmenityPickerComponent implements OnInit {
  yesSlugs = input<string[]>([]);

  yesChange = output<string[]>();
  /** Typed text that matched nothing in the combined vocabulary — never dropped, routed to the
   *  smestaj_preference wishlist field (see wizard.ts's onAmenityUnmatchedText). */
  unmatchedText = output<string>();

  readonly allOptions = signal<AmenityOption[]>([]);
  readonly yesQuery = signal('');

  /** Owner's ask, 2026-08-13: "možda ne mogu da se sete prave reči, al kad skroluju vide" —
   *  focusing the field now browses the FULL remaining list (empty query no longer means empty
   *  dropdown), typing still narrows it. Blur hides it on a short delay rather than immediately,
   *  so a click on a suggestion registers before the list disappears (blur fires first). */
  readonly yesFocused = signal(false);

  /** Purely visual confirmation for unmatched text — see flushUnmatched. Bug fixed 2026-08-04:
   *  typing something with no taxonomy match DID get captured (routed to smestaj_preference,
   *  invisible field), but with zero on-screen confirmation it read as "vanished". These render
   *  as inert (non-removable) chips, visually distinct from real taxonomy pills, so typing
   *  something always visibly lands SOMEWHERE. */
  readonly customYes = signal<string[]>([]);

  private isFirstLocaleEffect = true;

  constructor(
    private wizard: WizardService,
    public i18n: I18nService,
    private locale: LocaleService
  ) {
    // Same fix as WizardComponent's constructor effect, 2026-08-11 ("ne menja se sve na
    // promenu jezika, a mora") — this widget owns its own separate options fetch (see
    // AMENITY_TYPES docblock), so it needs its own re-fetch-on-locale-switch too.
    effect(() => {
      this.locale.locale();

      if (this.isFirstLocaleEffect) {
        this.isFirstLocaleEffect = false;
        return;
      }

      void this.fetchOptions();
    });
  }

  async ngOnInit(): Promise<void> {
    await this.fetchOptions();
  }

  private async fetchOptions(): Promise<void> {
    const results = await Promise.all(AMENITY_TYPES.map((type) => this.wizard.loadGeographyOptions(type)));
    this.allOptions.set(
      results.flatMap((nodes, i) => nodes.map((n) => ({ slug: n.slug, label: n.label, type: AMENITY_TYPES[i] })))
    );
  }

  labelFor(slug: string): string {
    return this.allOptions().find((o) => o.slug === slug)?.label ?? slug;
  }

  yesSuggestions(): AmenityOption[] {
    return this.suggestionsFor(this.yesQuery());
  }

  /** Empty query -> the full remaining (unselected) list, alphabetical, unlimited — this is the
   *  "browse" case (field just got focused, nothing typed yet). A real query narrows AND caps
   *  to MAX_SUGGESTIONS, same as before — that's still a search, not a browse. */
  private suggestionsFor(query: string): AmenityOption[] {
    const remaining = this.allOptions().filter((o) => !this.yesSlugs().includes(o.slug));
    const q = query.trim().toLowerCase();

    if (q.length === 0) {
      return [...remaining].sort((a, b) => a.label.localeCompare(b.label));
    }

    return remaining
      .filter((o) => o.label.toLowerCase().includes(q))
      .sort((a, b) => {
        const aStarts = a.label.toLowerCase().startsWith(q) ? 0 : 1;
        const bStarts = b.label.toLowerCase().startsWith(q) ? 0 : 1;
        return aStarts - bStarts;
      })
      .slice(0, MAX_SUGGESTIONS);
  }

  onYesFocus(): void {
    this.yesFocused.set(true);
  }

  /** Delayed, not immediate — blur fires before a suggestion button's click, so hiding right
   *  away would swallow the click before addYes() ever runs. */
  onYesBlur(): void {
    setTimeout(() => this.yesFocused.set(false), 150);
  }

  addYes(slug: string): void {
    this.yesChange.emit([...this.yesSlugs(), slug]);
    this.yesQuery.set('');
  }

  removeYes(slug: string): void {
    this.yesChange.emit(this.yesSlugs().filter((s) => s !== slug));
  }

  /** Enter with at least one live suggestion accepts the top one (owner's spec: "mi ponudimo
   *  beach, on prihvati, enter") — otherwise the typed text has no taxonomy match at all, so
   *  it's handed off as free text rather than silently dropped. */
  onYesEnter(): void {
    // Guarded on a real typed query now (2026-08-13) — suggestionsFor() returns the whole
    // browse list on an EMPTY query too, so without this an Enter press on an untouched,
    // just-focused field would silently add whatever sorts first alphabetically.
    const top = this.yesQuery().trim().length > 0 ? this.yesSuggestions()[0] : undefined;
    if (top) {
      this.addYes(top.slug);
      return;
    }
    this.flushUnmatched(this.yesQuery());
    this.yesQuery.set('');
  }

  private flushUnmatched(raw: string): void {
    const text = raw.trim();
    if (text.length === 0) return;

    this.unmatchedText.emit(text);
    this.customYes.update((c) => [...c, text]);
  }
}
