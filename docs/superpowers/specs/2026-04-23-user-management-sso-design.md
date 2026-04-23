# User management UI + EntraID SSO

**Date:** 2026-04-23
**Status:** Approved for implementation planning

## Goal

Give admins a web UI to manage users (currently only seeded via fixtures or auto-created by CSV import), and add Microsoft Entra ID single sign-on as an additive login option alongside the existing password form.

## Non-goals (v1)

- Hard-deleting users (decisions reference them; placeholder merge already covers the common case)
- Web UI for the existing `app:users:merge` CLI command
- Full user-audit log (log password resets and role changes via Symfony logger only)
- Mapping EntraID group claims to app roles
- SSO-only mode (disabling password login)
- Multi-tenant EntraID, long-lived session token refresh, or federated sign-out

## User management

### Routing

New controller `App\Controller\Admin\UserController`, class-level `#[IsGranted('ROLE_ADMIN')]`.

| Route | Method | Action |
|---|---|---|
| `/admin/users` | GET | `index` — list + filters |
| `/admin/users/new` | GET/POST | `new` — create real user |
| `/admin/users/{id}` | GET | `show` — detail + decision references |
| `/admin/users/{id}/edit` | GET/POST | `edit` — name/email/roles |
| `/admin/users/{id}/password` | GET/POST | `resetPassword` — admin sets new password |
| `/admin/users/{id}/promote` | GET/POST | `promotePlaceholder` — placeholder → real |

UUID route requirement: `[0-9a-f-]{36}` (matches existing convention).

Navbar gets a new admin-only "Users" link next to "Import CSV".

### Forms

Three distinct form types — no shared `_form.html.twig`:

- **`UserCreateType`** — `email`, `fullName`, `password` (RepeatedType, min 8), `roles` (ChoiceType, expanded+multiple, default `[ROLE_SUBMITTER]`)
- **`UserEditType`** — `email`, `fullName`, `roles` (roles field disabled when editing self)
- **`PasswordResetType`** — `password` (RepeatedType, min 8)

Roles rendered as Bootstrap checkboxes over `['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER']`. Internally stored as array on `User::$roles` (unchanged from today). Form data submitted as array, persisted directly.

### List page behavior

- Columns: name, email, roles (colored badges), placeholder flag, decision count, actions dropdown
- Filters: search (name/email substring, case-insensitive), role dropdown, placeholder toggle
- `UserRepository::queryForAdminList(array $filters)` returns a `QueryBuilder`
- Sorted by `fullName ASC`
- Hard cap 500 rows for v1 (pagination deferred until needed)
- Decision count computed as a subquery summing rows where the user is `submittedBy`, `approvedBy`, or `followUpOwner`

### Create flow

1. Admin fills form
2. Validation: email unique (via `UniqueEntity` constraint added to `User` entity), password confirmation matches, min length
3. `UserPasswordHasherInterface::hashPassword($user, $plain)` → save
4. `placeholder = false`
5. Flash success, redirect to `/admin/users`

### Edit flow

- Fields: `email`, `fullName`, `roles`
- `UniqueEntity` enforces email uniqueness (excluding current user)
- **Self-protection:** when `$user === $this->getUser()`:
  - `roles` field disabled server-side (form option `disabled => true`) and omitted from template
  - Controller double-checks the loaded user's existing roles against submitted data and rejects tampering with a 400
- Does NOT modify password or placeholder flag

### Password reset flow

- Only for non-placeholder users: if target `isPlaceholder()`, redirect to `/admin/users/{id}/promote` with a flash message
- Hash new password, persist
- Log at `info` level: `{admin_email} reset password for {target_email}`

### Promote placeholder flow

- Only reachable when `user.placeholder === true`; 404 otherwise
- Fields: `email` (new real address, replaces `*@imported.local`), `password` (RepeatedType), `roles` (default `ROLE_SUBMITTER`)
- On submit:
  - Set email (must pass unique check)
  - Hash password, set
  - `placeholder = false`
  - Persist & flush
- All decision references preserved because the `Uuid` is unchanged
- Flash message: "Promoted {name} to a real user." → redirect to `/admin/users/{id}`

### Audit

- `DecisionAuditSubscriber` is not extended in v1
- Password resets and role changes logged at `info` via Symfony logger — sufficient for post-hoc investigation without new storage
- A future spec may add a `UserHistory` entity mirroring `DecisionHistory`

### Tests

`tests/Controller/Admin/UserControllerTest.php`:

- Non-admin (submitter, approver, anonymous) receives 403/302 on every route
- Create → new user can log in with submitted password
- Edit self: roles field absent from rendered form; POSTing tampered roles returns 400 and stored roles unchanged
- Password reset: old password rejected by authenticator after reset, new password accepted
- Password reset on placeholder → redirect to promote route
- Promote placeholder: UUID unchanged, `placeholder` becomes false, email replaced, decision references still resolve via the same UUID

## Microsoft Entra ID SSO

### Packages

Installed via `docker compose exec app composer require`:

- `knpuniversity/oauth2-client-bundle`
- `thenetworg/oauth2-azure`

### Configuration

Environment variables (documented in `.env`):

```
AZURE_TENANT_ID=
AZURE_CLIENT_ID=
AZURE_CLIENT_SECRET=
AZURE_REDIRECT_URI=http://localhost:8180/login/azure/check
SSO_ENABLED=false
```

`SSO_ENABLED=false` by default. When false:

- Login page hides the "Sign in with Microsoft" button
- `/login/azure` returns 404
- `/login/azure/check` returns 404

Secrets are never stored in the database and never rendered in any admin page.

### OAuth client

`config/packages/knpu_oauth2_client.yaml`:

- Type: `azure`
- URL authority: `https://login.microsoftonline.com/%env(AZURE_TENANT_ID)%`
- Scopes: `openid profile email User.Read`
- Redirect route: `app_azure_check`

### Routes & controller

New `App\Controller\AzureAuthController`:

- `GET /login/azure` (`app_azure_connect`) — if `SSO_ENABLED`, redirect to Microsoft via `ClientRegistry->getClient('azure')->redirect(...)`; else 404
- `GET /login/azure/check` (`app_azure_check`) — OAuth callback; body empty, work happens in the authenticator

### Authenticator

`App\Security\AzureAuthenticator extends AbstractAuthenticator`:

- `supports(Request $r)` → route is `app_azure_check` AND `SSO_ENABLED`
- `authenticate(Request $r)` → use the KnpU helper to fetch the access token + user info
- Extract `email`, `name` (displayName), `oid` claims
- User resolution (in order):
  1. `UserRepository::findOneByEmail($email)` matches any user (real or placeholder) → reuse. If that user is still flagged `placeholder`, it's promoted in place: `placeholder=false` (email already matches; password stays null).
  2. No email match: search placeholders by the SSO user's display name. If **exactly one** placeholder matches by `fullName`, promote it (replace the synthetic `@imported.local` email with the SSO email, `placeholder=false`, password stays null). The UUID is preserved so decision references are kept intact.
  3. Zero or multiple name matches: create a new real user with `ROLE_SUBMITTER`, `placeholder=false`, `password=null`.
- Return `SelfValidatingPassport(UserBadge)` keyed on email

Wired into `security.yaml` under the `main` firewall:

```yaml
main:
    form_login: ...            # unchanged
    custom_authenticators:
        - App\Security\AzureAuthenticator
    logout: ...                # unchanged (local logout only)
```

Password-nullable users are already supported by `User::$password` (nullable). The form authenticator rejects users with null password — unchanged.

### Login page

`templates/security/login.html.twig` extended:

- Existing email/password form unchanged
- Below the form, if `sso_enabled` is true (injected via Twig global from a service or env var), render a "Sign in with Microsoft" button linking to `{{ path('app_azure_connect') }}`

### Admin SSO status

`/admin/users` page header shows a small info banner:

- `SSO_ENABLED=true` and all env vars present: "SSO enabled (tenant ending in `...<last 4 chars>`)"
- `SSO_ENABLED=true` but missing any of client id/secret/tenant: "SSO misconfigured — check environment"
- `SSO_ENABLED=false`: "SSO not configured"

No secrets rendered. Status computed by a small `SsoStatusProvider` service reading env at runtime.

### Tests

- Unit: `AzureAuthenticatorTest` — given synthetic claims (email/name), verifies:
  - Existing real user → same UUID returned
  - Unknown email with no name match → new real user created with `ROLE_SUBMITTER`, `password=null`
  - Unknown email matching exactly one placeholder by name → that placeholder is promoted (UUID preserved, email replaced)
  - Unknown email matching multiple placeholders → new real user created (no ambiguous promote)
- Integration: with `SSO_ENABLED=false`, `/login/azure` and `/login/azure/check` both return 404
- Login page: SSO button present iff `SSO_ENABLED=true`

No live Microsoft calls in tests — the authenticator is refactored so user resolution is a pure function testable with a fake user-info array.

## Open questions

None as of 2026-04-23 — all flagged questions resolved during brainstorming.

## Impact summary

**New files (approx):**
- `src/Controller/Admin/UserController.php`
- `src/Controller/AzureAuthController.php`
- `src/Form/UserCreateType.php`, `UserEditType.php`, `PasswordResetType.php`, `PromotePlaceholderType.php`
- `src/Security/AzureAuthenticator.php`
- `src/Service/SsoStatusProvider.php`
- `templates/admin/user/{index,new,edit,show,password,promote}.html.twig`
- `tests/Controller/Admin/UserControllerTest.php`
- `tests/Security/AzureAuthenticatorTest.php`

**Modified:**
- `src/Entity/User.php` — add `UniqueEntity` constraint on email
- `src/Repository/UserRepository.php` — add `queryForAdminList(array $filters)`
- `templates/base.html.twig` — add "Users" nav link for admins
- `templates/security/login.html.twig` — add SSO button (conditional)
- `config/packages/security.yaml` — register `AzureAuthenticator`
- `config/packages/knpu_oauth2_client.yaml` — new file, azure client
- `.env` — document new env vars
- `composer.json` — two new dependencies
