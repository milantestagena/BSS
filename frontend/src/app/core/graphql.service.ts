import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

// 127.0.0.1, not "localhost": WSL2's automatic port-forwarding only binds IPv4,
// so "localhost" makes the browser try [::1] first, wait for it to time out, then
// fall back to IPv4 — several extra seconds per request for nothing.
const GRAPHQL_ENDPOINT = 'http://127.0.0.1:8000/graphql';

interface GraphQLResponse<T> {
  data?: T;
  errors?: { message: string }[];
}

@Injectable({ providedIn: 'root' })
export class GraphqlService {
  constructor(private http: HttpClient) {}

  async request<T>(query: string, variables: Record<string, unknown> = {}): Promise<T> {
    const response = await firstValueFrom(
      this.http.post<GraphQLResponse<T>>(GRAPHQL_ENDPOINT, { query, variables })
    );

    if (response.errors?.length) {
      throw new Error(response.errors.map((e) => e.message).join('; '));
    }

    return response.data as T;
  }
}
