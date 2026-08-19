import { Component, ElementRef, ViewChild, effect, input, output, signal } from '@angular/core';
import { DestinationGuide, TaxonomyNode } from '../core/wizard.types';
import { WizardService } from '../core/wizard.service';
import { I18nService } from '../core/i18n.service';
import { SpinnerComponent } from './spinner';

/**
 * Optional "deep-dive" destination guide — owner's ask, 2026-08-19, styled after a real
 * Instagram travel-carousel he shared (cover -> itinerary -> costs -> tips -> photos). Strictly
 * side-of-flow: reached via a link inside the existing ui-info-popover, never a wizard step.
 *
 * ONE instance lives at the wizard level (see wizard.html) — `node` is set/cleared by the
 * parent when a card's "see full guide" link is clicked, rather than one modal per card.
 * Content is fetched on demand the moment `node` becomes non-null, never preloaded.
 *
 * Native `<dialog>` + showModal()/close() — a real DOM property (`[open]`, no `[attr.]`
 * workaround needed, unlike the `popover`/`popovertarget` attributes ui-info-popover uses),
 * appropriate here since this is a bigger scrollable overlay than that small anchored popover
 * is built for. Zero new dependencies, matches this project's "native HTML API first" style.
 */
@Component({
  selector: 'app-destination-guide-modal',
  standalone: true,
  imports: [SpinnerComponent],
  templateUrl: './destination-guide-modal.html',
})
export class DestinationGuideModalComponent {
  /** Null = closed. Set by the parent when a card's guide link is clicked. */
  node = input<TaxonomyNode | null>(null);
  /** Booking's own checkin/checkout, if already resolved this session — see
   *  WizardComponent.prefillRecommendedDates for the identical compiledQuery read. Cover-only,
   *  never stored on the guide itself (see DestinationGuide's docblock on why). */
  checkin = input<string | null>(null);
  checkout = input<string | null>(null);
  homeCityLabel = input<string | null>(null);

  /** Fires when the dialog closes for ANY reason (Esc, backdrop click, close button) so the
   *  parent can null out `node` and keep the two in sync. */
  readonly closed = output<void>();

  @ViewChild('dialogEl') private dialogRef?: ElementRef<HTMLDialogElement>;

  readonly guide = signal<DestinationGuide | null>(null);
  readonly loading = signal(false);

  constructor(
    private wizard: WizardService,
    public i18n: I18nService
  ) {
    effect(() => {
      const node = this.node();
      if (node) {
        void this.openFor(node);
      } else {
        this.dialogRef?.nativeElement.close();
      }
    });
  }

  private async openFor(node: TaxonomyNode): Promise<void> {
    this.guide.set(null);
    this.loading.set(true);
    this.dialogRef?.nativeElement.showModal();

    try {
      this.guide.set(await this.wizard.loadDestinationGuide(node.id));
    } finally {
      this.loading.set(false);
    }
  }

  /** Bound to the native <dialog>'s (close) event — fires on Esc/backdrop-click too, not just
   *  our own close button, so this is the single source of truth for "the modal just closed". */
  onNativeClose(): void {
    this.closed.emit();
  }

  close(): void {
    this.dialogRef?.nativeElement.close();
  }
}
