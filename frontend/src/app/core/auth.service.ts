import { Injectable, signal } from '@angular/core';
import { GraphqlService } from './graphql.service';

export interface CurrentUser {
  id: string;
  name: string;
  email: string;
  avatarUrl: string | null;
  wallet: { balance: number } | null;
}

const ME_QUERY = `
  query Me {
    me {
      id name email avatarUrl wallet { balance }
    }
  }
`;

/**
 * Tracks the logged-in visitor (Google OAuth, see GoogleAuthController on the backend) — see
 * CLAUDE.md section 5/8. Login is a full-page redirect flow (not a JS popup), since frontend
 * and backend share one origin behind nginx — see wizard_architecture, 2026-08-07 deploy notes.
 * Deliberately NOT wired into the wizard flow's login/credit gate yet (CLAUDE.md section 3:
 * "samo na koraku konkretnog smeštaja") — today this only surfaces account status/credits,
 * 2026-08-10.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  readonly currentUser = signal<CurrentUser | null>(null);
  readonly loaded = signal(false);

  constructor(private gql: GraphqlService) {}

  async loadCurrentUser(): Promise<void> {
    try {
      const data = await this.gql.request<{ me: CurrentUser | null }>(ME_QUERY);
      this.currentUser.set(data.me);
    } catch {
      this.currentUser.set(null);
    } finally {
      this.loaded.set(true);
    }
  }

  get signInUrl(): string {
    return this.backendOrigin() + '/auth/google/redirect';
  }

  get signOutUrl(): string {
    return this.backendOrigin() + '/auth/logout';
  }

  /** Mirrors the 127.0.0.1-vs-relative split already used by graphql.service.ts — dev hits the
   *  backend on a different port, production shares one origin. */
  private backendOrigin(): string {
    return location.hostname === 'localhost' || location.hostname === '127.0.0.1'
      ? 'http://127.0.0.1:8000'
      : '';
  }
}
