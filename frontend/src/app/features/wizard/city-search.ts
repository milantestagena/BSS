import { Component, ElementRef, input, output, signal, ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { GraphqlService } from '../../core/graphql.service';

export interface WorldCityResult {
  id: string;
  name: string;
  countryCode: string;
}

const SEARCH_QUERY = `
  query HomeCitySearch($query: String!) {
    homeCitySearch(query: $query) { id name countryCode }
  }
`;

/**
 * Typeahead over the GeoNames world_cities catalog (~34k cities) for the home_city question —
 * owner's explicit ask, 2026-07-30/08-03: "moramo da dodamo bazu sa gradovima i long lat, gde
 * ce ajaksom, posle 3 karaktera da nudi 10 lokacija... u formatu grad, drzava". Debounced
 * 300ms, backend enforces the 3-character floor too (see WorldCityResolver::search) so a
 * fast typist hitting the API early just gets an empty result, not an error.
 */
@Component({
  selector: 'app-city-search',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './city-search.html',
})
export class CitySearchComponent {
  @ViewChild('input') inputRef?: ElementRef<HTMLInputElement>;

  // English default (canonical, matches the rest of the app) — wizard.html always overrides
  // this explicitly with the locale-aware i18n string, this only matters if some future caller
  // forgets to pass one. Was hardcoded Serbian until 2026-08-11 German-language work surfaced it.
  placeholder = input('Start typing a city name...');
  citySelected = output<WorldCityResult>();

  query = signal('');
  results = signal<WorldCityResult[]>([]);
  loading = signal(false);
  selectedLabel = signal<string | null>(null);

  private debounceHandle?: ReturnType<typeof setTimeout>;

  constructor(private gql: GraphqlService) {}

  onInput(value: string): void {
    this.query.set(value);
    this.selectedLabel.set(null);

    if (this.debounceHandle) {
      clearTimeout(this.debounceHandle);
    }

    if (value.trim().length < 3) {
      this.results.set([]);
      return;
    }

    this.debounceHandle = setTimeout(() => this.runSearch(value.trim()), 300);
  }

  private async runSearch(query: string): Promise<void> {
    this.loading.set(true);
    try {
      const data = await this.gql.request<{ homeCitySearch: WorldCityResult[] }>(SEARCH_QUERY, { query });
      this.results.set(data.homeCitySearch);
    } finally {
      this.loading.set(false);
    }
  }

  select(city: WorldCityResult): void {
    this.selectedLabel.set(`${city.name}, ${city.countryCode}`);
    this.results.set([]);
    this.citySelected.emit(city);
  }
}
