# New User Form Redesign + Department Field

**Date:** 2026-04-24  
**Scope:** `/admin/users/new` redesign, department field on User entity, propagation to edit/show

---

## What we're building

Redesign the New User admin form from a plain stacked layout into a polished two-card layout with better password UX, a styled role picker, and a new optional Department field on the User entity.

---

## Data model change

**`User` entity** — add one nullable column:

```php
#[ORM\Column(nullable: true, enumType: Department::class)]
private ?Department $department = null;
```

- Nullable — department is optional for all users (new and existing).
- Uses the existing `Department` string-backed enum (`Risk`, `Manual`, `Other`).
- Migration: `ALTER TABLE users ADD COLUMN department VARCHAR(20) NULL`.

---

## Form changes

### `UserCreateType`

Add after `fullName`:

```php
->add('department', EnumType::class, [
    'class'        => Department::class,
    'label'        => 'Department',
    'required'     => false,
    'placeholder'  => '— select —',
    'choice_label' => fn(Department $d) => $d->label(),
])
```

Password and roles fields stay as-is (server-side validation unchanged).

### `UserEditType`

Same `department` field addition (mapped directly to entity, nullable).

---

## Template redesign — `new.html.twig`

Replace the flat `form_row` list with two Bootstrap cards and custom JS for password UX and role picker.

### Card 1 — Identity

Fields: Full name, Email, Department — each with an icon prefix (`person`, `envelope`, `building`). Department renders as a `<select>` with "— select —" placeholder (optional).

### Card 2 — Access

**Password section:**
- Password field with show/hide eye-button toggle.
- Live strength bar (4 segments, colour ramps weak→strong based on length + uppercase + digit + special char).
- Live requirements checklist (8+ chars, uppercase letter, number) — icons flip from ✗ to ✓ as conditions are met.
- Repeat-password field with its own show/hide toggle.

**Role section:**
- Three styled clickable cards (Submitter, Approver, Admin) with a one-line permission description each.
- Visually single-select (JS enforces one selection at a time); the underlying `roles` checkbox group still posts a proper array — JS keeps them in sync.
- Default selection: Submitter.
- Badge colours match the rest of the admin UI (secondary / warning / danger).

### Actions

"Create user" primary button + "Cancel" link, below the second card.

---

## Other templates

### `edit.html.twig`

Add `{{ form_row(form.department) }}` after `fullName`. No layout restructure — edit form stays as a simple stacked form.

### `show.html.twig`

Add a Department row to the `<dl>`:

```twig
<dt class="col-sm-3">Department</dt>
<dd class="col-sm-9">{{ user.department ? user.department.label() : '—' }}</dd>
```

---

## What is NOT in scope

- Department on placeholder promotion (`promote.html.twig`) — placeholder users don't get a department until they're promoted via edit.
- Department filtering on the user list — out of scope.
- Department on the CSV importer — out of scope.

---

## Files changed

| File | Change |
|------|--------|
| `src/Entity/User.php` | Add `department` property + getter/setter |
| `migrations/VersionXXX.php` | ADD COLUMN migration |
| `src/Form/UserCreateType.php` | Add department field |
| `src/Form/UserEditType.php` | Add department field |
| `templates/admin/user/new.html.twig` | Full redesign |
| `templates/admin/user/edit.html.twig` | Add department row |
| `templates/admin/user/show.html.twig` | Add department row |
