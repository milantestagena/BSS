import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom, timeout } from 'rxjs';
import { LocaleService } from './locale.service';

// Owner's ask, 2026-09-05 ("proceed da ne moze da zakove... da ima neki mehanizam oporavka") —
// caught live on a real phone on 4G: a request that hangs (dropped mobile connection, never
// errors, never resolves) left goNext()'s `submitting` signal stuck true forever, since nothing
// downstream ever got the chance to run its `finally`. Without a timeout, a hung HTTP call is
// indistinguishable from one that's just slow — this bounds the wait so the try/finally callers
// throughout this app (goNext, startWizard, ...) always eventually get control back one way or
// another, even on a bad connection.
const REQUEST_TIMEOUT_MS = 15000;

// 127.0.0.1, not "localhost": WSL2's automatic port-forwarding only binds IPv4,
// so "localhost" makes the browser try [::1] first, wait for it to time out, then
// fall back to IPv4 — several extra seconds per request for nothing.
// In production (any other hostname), frontend and backend share one origin behind
// nginx, so a relative path is correct and needs no per-environment config file —
// deploy note, 2026-08-06.
const GRAPHQL_ENDPOINT =
  location.hostname === 'localhost' || location.hostname === '127.0.0.1'
    ? 'http://127.0.0.1:8000/graphql'
    : '/graphql';

interface GraphQLResponse<T> {
  data?: T;
  errors?: { message: string }[];
}

@Injectable({ providedIn: 'root' })
export class GraphqlService {
  constructor(
    private http: HttpClient,
    private locale: LocaleService
  ) {}

  async request<T>(query: string, variables: Record<string, unknown> = {}): Promise<T> {
    // withCredentials: needed for the Google OAuth session cookie to reach the backend —
    // matters most locally, where frontend (4837) and backend (8000) are different origins.
    // See config/cors.php (backend) for the matching allowed_origins/supports_credentials.
    // X-Locale drives every translated label/step/question server-side (see TranslateDirective,
    // backend) — one shared header, no per-query locale argument needed anywhere.
    const response = await firstValueFrom(
      this.http
        .post<GraphQLResponse<T>>(
          GRAPHQL_ENDPOINT,
          { query, variables },
          { withCredentials: true, headers: { 'X-Locale': this.locale.locale() } }
        )
        .pipe(timeout(REQUEST_TIMEOUT_MS))
    );

    if (response.errors?.length) {
      throw new Error(response.errors.map((e) => e.message).join('; '));
    }

    return response.data as T;
  }
}
