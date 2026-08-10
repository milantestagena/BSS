import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

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
  constructor(private http: HttpClient) {}

  async request<T>(query: string, variables: Record<string, unknown> = {}): Promise<T> {
    // withCredentials: needed for the Google OAuth session cookie to reach the backend —
    // matters most locally, where frontend (4837) and backend (8000) are different origins.
    // See config/cors.php (backend) for the matching allowed_origins/supports_credentials.
    const response = await firstValueFrom(
      this.http.post<GraphQLResponse<T>>(GRAPHQL_ENDPOINT, { query, variables }, { withCredentials: true })
    );

    if (response.errors?.length) {
      throw new Error(response.errors.map((e) => e.message).join('; '));
    }

    return response.data as T;
  }
}
