import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TaxonomyNode, WizardQuestion } from '../../core/wizard.types';
import { ChoiceComponent } from '../../ui/choice';
import { TextFieldComponent } from '../../ui/text-field';
import { DateFieldComponent } from '../../ui/date-field';
import { I18nService } from '../../core/i18n.service';

@Component({
  selector: 'app-question-input',
  standalone: true,
  imports: [CommonModule, ChoiceComponent, TextFieldComponent, DateFieldComponent],
  templateUrl: './question-input.html',
})
export class QuestionInputComponent {
  @Input({ required: true }) question!: WizardQuestion;
  @Input() options: TaxonomyNode[] | null = null;
  @Input() value: unknown;
  /** Only meaningful for input_type 'text' (smestaj_preference) — see wizard.html's
   *  aiSearchDisabled/aiSearchGateReason. Structured taxonomy questions are never gated. */
  @Input() disabled = false;
  @Output() valueChange = new EventEmitter<unknown>();

  constructor(public i18n: I18nService) {}

  get resolvedOptions(): TaxonomyNode[] {
    return this.options ?? this.question.options ?? [];
  }

  /** Splits a trailing "(...)" hint off the main label so it can be styled apart from it —
   *  owner's ask, 2026-08-06: the "(...will get its own checklist soon)" aside on
   *  smestaj_preference should read as a lighter side-note, not part of the question itself. */
  get mainLabel(): string {
    return this.question.label.replace(/\s*\([^)]*\)\s*$/, '');
  }

  get hintLabel(): string | null {
    const match = this.question.label.match(/\(([^)]*)\)\s*$/);
    return match ? match[1] : null;
  }

  /** Only show the match-score badge when it actually discriminates between
   *  options — a flat "(10)" on every choice is noise, not a signal. */
  get showMatchScores(): boolean {
    const scores = this.resolvedOptions.map((n) => n.matchScore ?? 0);
    return scores.some((s) => s !== 0) && new Set(scores).size > 1;
  }

  get numberAsString(): string {
    return this.value == null ? '' : String(this.value);
  }

  /** Owner's ask, 2026-08-04: total_budget's native spinner should jump by 100, not 1 — nobody
   *  budgets a trip in single-euro increments. Every other 'number' question (adults_count,
   *  number_of_rooms) stays at the default 1. */
  get numberStep(): string {
    return this.question.key === 'total_budget' ? '100' : '1';
  }

  get textValue(): string {
    return (this.value as string) ?? '';
  }

  get childrenAgesText(): string {
    return ((this.value as number[]) || []).join(', ');
  }

  get dateFromValue(): string {
    return ((this.value as [string, string]) || ['', ''])[0];
  }

  get dateToValue(): string {
    return ((this.value as [string, string]) || ['', ''])[1];
  }

  onNumberChange(raw: string): void {
    this.valueChange.emit(raw === '' ? null : Number(raw));
  }

  onChildrenAgesChange(raw: string): void {
    const ages = raw
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s !== '')
      .map((s) => Number(s))
      .filter((n) => !Number.isNaN(n));
    this.valueChange.emit(ages);
  }

  onBooleanChange(checked: boolean): void {
    this.valueChange.emit(checked);
  }

  onTextChange(raw: string): void {
    this.valueChange.emit(raw);
  }

  /** Fields that aren't a taxonomy_nodes FK (session_field ending `_id`) store the slug;
   *  everything else stores the node id. Bug fixed 2026-08-06: the `free_text_answers.`
   *  prefix used to also be excluded from slug storage, on the theory those fields hold plain
   *  text — but relationship_type/region_theme are taxonomy_choice fields living under
   *  free_text_answers too, and got a raw numeric id ("14") instead of a readable slug as a
   *  result (caught live: honestReportSignals.relationship_type showed "14", not "couple").
   *  Multi-choice questions never had this problem — onMultiChoiceToggle always used slug. */
  private get usesSlugValue(): boolean {
    const field = this.question.sessionField;
    return !!field && !field.endsWith('_id');
  }

  onSingleChoiceChange(node: TaxonomyNode): void {
    this.valueChange.emit(this.usesSlugValue ? node.slug : node.id);
  }

  isSelected(node: TaxonomyNode): boolean {
    return this.value === (this.usesSlugValue ? node.slug : node.id);
  }

  isMultiSelected(node: TaxonomyNode): boolean {
    return ((this.value as string[]) || []).includes(node.slug);
  }

  onMultiChoiceToggle(node: TaxonomyNode): void {
    const current = ((this.value as string[]) || []).slice();
    const idx = current.indexOf(node.slug);
    if (idx >= 0) {
      current.splice(idx, 1);
    } else {
      current.push(node.slug);
    }
    this.valueChange.emit(current);
  }

  onDateFromChange(raw: string): void {
    const current = (this.value as [string, string]) || ['', ''];
    this.valueChange.emit([raw, current[1]]);
  }

  onDateToChange(raw: string): void {
    const current = (this.value as [string, string]) || ['', ''];
    this.valueChange.emit([current[0], raw]);
  }
}
