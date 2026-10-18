# Handoff — Team Subscriptions

- **Date**: 2026-06-15 20:35 CEST
- **Branch**: `feature/teams-subscription`
- **Feature flag**: `TEAMS_ENABLED=true` (in local `.env`; `.env.example` leaves it as `false`)
- **Status**: feature implemented, committed and green. Minor polish and real E2E verification with Stripe remain.

---

## 1. What this is

Subscription for **teams**: a *manager/payer* creates a team with a name, pays for **N seats** (volume pricing defined in Stripe, we don't compute it ourselves) and invites members who get **full access** without paying themselves. The guiding principle: the member is a fully-fledged subscriber, but their access is **derived from belonging to a team with an active subscription**, reusing `User::isSubscribed()` as the single source of truth.

Original plan: `docs/plans/team-subscriptions.md` (committed). Read it for the full design context.

---

## 2. Where the code lives

Feature in `app/Features/Teams/` (28 PHP files). Key pieces:

| Piece | File | Note |
|---|---|---|
| **Derived access** | `app/Features/Subscriptions/Traits/Subscriptable.php` | `isSubscribed()` adds `\|\| belongsToActiveTeam()` (4 documented sources) |
| Team predicate | `app/Features/Teams/Traits/HasTeams.php` | `belongsToActiveTeam()`, `currentTeam()`, `isTeamManager()`, `ownedTeams()`, `teams()` — lives on `User` |
| Team model | `app/Features/Teams/Models/Team.php` | `ACCESS_STATUSES = ['active','past_due']`, `grantsAccess()`, `seatsUsed/Available()` |
| Invitation | `app/Features/Teams/Models/TeamInvitation.php` | token, `scopePending()`, `isExpired()` |
| Role tier | `app/Features/Teams/Services/TeamMembershipService.php` | `syncTeamRole()` idempotent/multi-team; `addConfirmedMember()`, `removeMember()` (admin notices) |
| Checkout | `app/Features/Subscriptions/Services/CheckoutService.php` | `createTeamCheckout()` (auth) + `createPublicTeamCheckout()` (guest) |
| Webhook provisioning | `app/Features/Teams/Services/TeamProvisioningService.php` | reconciles by `metadata.team_id` → fallback `owner_id` → creates team if public checkout; re-syncs `seats` from Stripe |
| Webhook hook | `app/Features/Subscriptions/Listeners/SubscriptionEventListener.php` | branches on `team`-type subscription; respects `tier-team` when reassigning individual roles |
| Stripe tiers | `app/Features/Teams/Services/StripeTierService.php` | `tiersFor()` (cached) + `seatOptionsFor()` (closed seat options) |
| Invitations | `app/Features/Teams/Services/TeamInvitationService.php` | `invite()` with `lockForUpdate` (anti-oversell), `confirm()` double opt-in, signed `acceptUrl()` |
| Roster/stats | `app/Features/Teams/AQC/GetTeamMemberActivity.php` | `overview\|roster\|member-detail` scenarios + **privacy whitelist** |
| Policy | `app/Features/Teams/Policies/TeamPolicy.php` | `view`/`manage` manager-only (admin bypass) |
| Safety-net command | `app/Features/Teams/Commands/SyncTeamSeatsCommand.php` | `teams:sync-seats` (NOT scheduled yet) |
| Last activity | `app/Features/Teams/Listeners/UpdateLastActiveAt.php` | listens to `Login`, writes `users.last_active_at` without touching `updated_at` |

Frontend in `resources/js/pages/teams/` (`create`, `show`, `accept-invitation`, `invitation-expired`, `member-magic-login`) + `resources/js/pages/checkout/team.tsx`. "My team" link in `resources/js/components/app-sidebar.tsx` (footer, above "Start here", visible to manager/admin). `TeamSummary` type and `auth.team` in `resources/js/types/index.d.ts`, populated by `app/Http/Middleware/HandleInertiaRequests.php`.

Config: `config/teams.php` (flag, `plan_slug`, `plan_stripe_price_id`, `role_slug=tier-team`, seat bounds, cache TTL, `roster_fields`). Email subjects: `config/content.php` → `email_subjects` (`admin_team_member_joined`, `admin_team_member_removed`, `team_invitation`).

Migrations: `database/migrations/2026_05_29_0000{01..05}_*` (teams, team_user, team_invitations, users.last_active_at, team columns on subscription_plans). Seeder: `database/seeders/TeamPlanSeeder.php`.

---

## 3. Routes

**Public checkout (guest)** — `routes/web.php` ~L89-97:
- `GET /checkout/teams` → `teams.checkout.public`
- `POST /checkout/teams` → `teams.checkout.public.store`
- `GET /checkout/teams/success` → `teams.checkout.public.success` (creates/links user, provisions, logs in)

**Teams (auth + teams.enabled)** — `routes/web.php` ~L210-242:
- `GET /teams/create` → `teams.create` · `POST /teams` → `teams.store`
- `GET /teams` → `teams.index` (redirects to the user's team; admin → latest; no team → create)
- `GET /teams/{team}` → `teams.show` (`can:view,team`) · `PATCH /teams/{team}` → `teams.update`
- `POST /teams/{team}/invitations` → `teams.invitations.store` (+ `resend`, `revoke`)
- `DELETE /teams/{team}/members/{member:uuid}` → `teams.members.remove`
- `POST /teams/{team}/members/{member:uuid}/magic-link` → `teams.members.magic-link`

**Invitation acceptance (signed, any session)** — `routes/auth.php`:
- `GET /teams/invitation/{invitation}` → `teams.invitation.show`
- `POST /teams/invitation/{invitation}` → `teams.invitation.accept`

> ⚠️ After touching routes: `herd php artisan route:clear` (a stale route cache causes 404s in the suite and browser).

---

## 4. How to run / test

```bash
# flag + caches
# .env: TEAMS_ENABLED=true
herd php artisan config:clear && herd php artisan route:clear

# migrations + team plan
herd php artisan migrate
herd php artisan db:seed --class=Database\\Seeders\\TeamPlanSeeder

# team tests (54, all green)
herd php artisan route:clear
herd php vendor/bin/pest tests/Feature/Features/Teams tests/Unit/Features/Teams

# quality
herd php vendor/bin/pint --dirty
npm run types
```

**Existing test data** (created in a previous session, password `password`):
- `team-manager@example.com` — manager, `manual_active`, team "Test Team" (5 seats, active)
- `team-member1@example.com`, `team-member2@example.com` — active members

Stripe TEST: product `prod_Ubg9D7WzaJKRWi`, tiered price `price_1TcSn89ZmHQ96oouQ5ISRHYM` (1–5: €15 · 6–20: €12 · 21+: €9).

---

## 5. Test status

- **Teams**: 54/54 green (5 Feature + 4 Unit). The full suite was at ~1718 with 0 relevant failures last time.
- `TeamNotificationSubjectTest` renders `toMail()` for real (regression test for the subjects bug, already resolved).

---

## 6. Pending / next steps

1. **Real E2E verification with Stripe test mode** (not done, only unit/feature with simulated webhooks):
   - Complete a team checkout with a test card → confirm the webhook leaves `Team.status=active` with correct `seats`.
   - Invite + accept (new and existing user) → check real premium access and admin emails.
   - Remove a member → seat freed, no access, admin email.
   - Simulate `past_due` (test clock) → members keep access.
   - Cancel subscription → members lose access.
2. **Schedule `teams:sync-seats`** in `routes/console.php` (the command exists today but isn't in the scheduler).
3. **Playwright E2E tests** for the team flow (test-ids exist: `T.team.page/membersList/memberCard/inviteForm/seatsControl`).
4. **Review public vs. authenticated checkout**: the public flow (`/checkout/teams`) was added alongside the authenticated one (`/teams/create`). Confirm with product which one is linked from pricing/landing and whether both should coexist.
5. **Plan in production**: recreate the tiered product+price in *live* mode and set the live `price_id` in `TEAMS_STRIPE_PRICE_ID`.
6. Consider moving the plan to its own commit for cleaner history (currently it's inside `262ab9a`).

---

## 7. Notes / gotchas learned

- **Pint removes "unused" imports**: add the `use` AND its usage in the SAME edit, or the formatter strips it between edits.
- **`config(...)` can return `null`** if the key is missing → in PHP 8.4 `strtr(null,...)` blows up. Team notifications already cast to `(string)`. After editing `config/content.php`: `config:clear`.
- **`{user:uuid}` nested under `{team}`** makes Laravel look up `Team::users()`; that's why the member binding is named `{member:uuid}` (uses `Team::members()` and auto-scope).
- **`Inertia::location()` responds 409** on Inertia requests, but **302** on a normal test request — when testing checkout redirects use `assertRedirect`, not 409.
- The feature is **behind the flag**: with `TEAMS_ENABLED=false` all routes 404 (middleware `teams.enabled`) and the sidebar link disappears.

---

## 8. Relevant commits

```
ac225ef fix: skill format
262ab9a feat(teams): ✨ add team subscriptions with derived member access  ← includes the plan
c96107b Merge branch 'staging'
```

Current uncommitted changes are NOT team-related (skills-lock.json, other loose docs). No `git push` has been done.
