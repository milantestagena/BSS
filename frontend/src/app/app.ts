import { Component, OnInit, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { AccountBadgeComponent } from './features/account/account-badge';
import { AuthService } from './core/auth.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, AccountBadgeComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss'
})
export class App implements OnInit {
  protected readonly title = signal('frontend');

  constructor(private auth: AuthService) {}

  // Fire-and-forget, app-wide — same "never block the visible page" convention as
  // WizardService.detectHomeCity. See AuthService/AccountBadgeComponent, 2026-08-10.
  ngOnInit(): void {
    void this.auth.loadCurrentUser();
  }
}
