# New User Form Redesign + Department Field Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign `/admin/users/new` into a polished two-card layout with password UX improvements and a styled role picker, and add an optional nullable `Department` field to the `User` entity surfaced across create, edit, and show pages.

**Architecture:** The `Department` enum already exists; we add a nullable `department` column to `users`, wire it into both form types with Symfony's `EnumType`, then rewrite `new.html.twig` field-by-field (not `form_widget` for the whole form) so we control layout, icons, and JS behaviour. The role picker keeps the existing `expanded+multiple` checkboxes hidden for form submission correctness but overlays them with clickable styled cards enforcing single-selection. Edit and show pages get minimal additions only.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM, Twig, Bootstrap 5.3, Bootstrap Icons 1.11, vanilla JS (no bundler)

---

## File Map

| File | Action |
|------|--------|
| `src/Entity/User.php` | Add `department` property, getter, setter |
| `migrations/VersionXXX.php` | Generated — adds `department` nullable column |
| `src/Form/UserCreateType.php` | Add `department` EnumType field |
| `src/Form/UserEditType.php` | Add `department` EnumType field |
| `templates/admin/user/new.html.twig` | Full redesign — two-card layout |
| `templates/admin/user/edit.html.twig` | Add department row |
| `templates/admin/user/show.html.twig` | Add department row |
| `tests/Controller/Admin/UserControllerTest.php` | Add department assertions + new test |

---

## Task 1: Add `department` to User entity

**Files:**
- Modify: `src/Entity/User.php`

- [ ] **Step 1: Add the property and accessor methods to User**

  Open `src/Entity/User.php`. Add the import at the top with the other imports:

  ```php
  use App\Enum\Department;
  ```

  Add the property after the `$placeholder` property (line 43):

  ```php
  #[ORM\Column(nullable: true, enumType: Department::class)]
  private ?Department $department = null;
  ```

  Add the getter and setter before `eraseCredentials()`:

  ```php
  public function getDepartment(): ?Department
  {
      return $this->department;
  }

  public function setDepartment(?Department $department): void
  {
      $this->department = $department;
  }
  ```

- [ ] **Step 2: Verify the entity compiles**

  ```bash
  docker compose exec app php bin/console debug:container --no-debug 2>&1 | grep -c "error" || echo "OK"
  ```

  Expected: prints `OK` (zero errors).

---

## Task 2: Generate and run the migration

**Files:**
- Create: `migrations/VersionXXX.php` (generated)

- [ ] **Step 1: Generate the migration diff**

  ```bash
  docker compose exec app php bin/console doctrine:migrations:diff
  ```

  Expected output ends with: `Generated new migration class to "migrations/VersionXXXXXX.php"`

- [ ] **Step 2: Verify the generated SQL is correct**

  Open the generated migration file. The `up()` method must contain:

  ```sql
  ALTER TABLE users ADD department VARCHAR(20) DEFAULT NULL
  ```

  And the `down()` method must contain:

  ```sql
  ALTER TABLE users DROP COLUMN department
  ```

  If the diff added anything else, something is wrong — do not proceed.

- [ ] **Step 3: Run the migration**

  ```bash
  docker compose exec app php bin/console doctrine:migrations:migrate -n
  ```

  Expected: `[OK] Successfully executed 1 migrations.`

- [ ] **Step 4: Run migration against the test database**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/console doctrine:migrations:migrate -n
  docker compose exec -T -e APP_ENV=test app php bin/console cache:clear
  ```

  Expected: both commands exit 0.

- [ ] **Step 5: Commit**

  ```bash
  git add src/Entity/User.php migrations/
  git commit -m "feat: add nullable department column to User entity"
  ```

---

## Task 3: Add `department` to form types

**Files:**
- Modify: `src/Form/UserCreateType.php`
- Modify: `src/Form/UserEditType.php`

- [ ] **Step 1: Write the failing test for department persistence**

  In `tests/Controller/Admin/UserControllerTest.php`, add this method after `testCreateUserRejectsDuplicateEmail`:

  ```php
  public function testCreateUserWithDepartmentSavesDepartment(): void
  {
      $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
      $this->client->loginUser($admin);

      $crawler = $this->client->request('GET', '/admin/users/new');
      self::assertResponseIsSuccessful();

      $form = $crawler->selectButton('Create user')->form([
          'user_create[email]'             => 'dept@example.com',
          'user_create[fullName]'          => 'Dept User',
          'user_create[password][first]'   => 'secretpass',
          'user_create[password][second]'  => 'secretpass',
          'user_create[department]'        => 'Risk',
      ]);
      $this->setRoleCheckboxes($form, 'ROLE_SUBMITTER');
      $this->client->submit($form);
      self::assertResponseRedirects('/admin/users');

      $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'dept@example.com']);
      self::assertNotNull($created);
      self::assertSame(\App\Enum\Department::Risk, $created->getDepartment());
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --filter testCreateUserWithDepartmentSavesDepartment --testdox
  ```

  Expected: FAIL — either 500 (field not in form) or an assertion error.

- [ ] **Step 3: Add `department` to UserCreateType**

  In `src/Form/UserCreateType.php`, add these imports:

  ```php
  use App\Enum\Department;
  use Symfony\Component\Form\Extension\Core\Type\EnumType;
  ```

  In `buildForm()`, add after the `fullName` field:

  ```php
  ->add('department', EnumType::class, [
      'class'        => Department::class,
      'label'        => 'Department',
      'required'     => false,
      'placeholder'  => '— select —',
      'choice_label' => fn(Department $d) => $d->label(),
  ])
  ```

- [ ] **Step 4: Add `department` to UserEditType**

  In `src/Form/UserEditType.php`, add the same imports:

  ```php
  use App\Enum\Department;
  use Symfony\Component\Form\Extension\Core\Type\EnumType;
  ```

  In `buildForm()`, add after the `fullName` field:

  ```php
  ->add('department', EnumType::class, [
      'class'        => Department::class,
      'label'        => 'Department',
      'required'     => false,
      'placeholder'  => '— select —',
      'choice_label' => fn(Department $d) => $d->label(),
  ])
  ```

- [ ] **Step 5: Run the failing test — it should now pass**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --filter testCreateUserWithDepartmentSavesDepartment --testdox
  ```

  Expected: PASS.

- [ ] **Step 6: Run the full test suite to catch regressions**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox
  ```

  Expected: all tests pass.

- [ ] **Step 7: Commit**

  ```bash
  git add src/Form/UserCreateType.php src/Form/UserEditType.php tests/Controller/Admin/UserControllerTest.php
  git commit -m "feat: add department field to UserCreateType and UserEditType"
  ```

---

## Task 4: Redesign new.html.twig

**Files:**
- Modify: `templates/admin/user/new.html.twig`

The new template renders all fields manually (no full-form `form_widget`) for layout control. Key decisions:
- The Symfony role checkboxes render inside a `d-none` wrapper so they participate in form submission and CSRF but aren't visible.
- A visual role picker (styled cards) sits above the hidden checkboxes. JS syncs clicks on cards → checkbox state.
- `form_row` is used for department, email, and fullName since they each need a custom `input-group` icon wrapper.

- [ ] **Step 1: Replace new.html.twig entirely**

  Replace the full contents of `templates/admin/user/new.html.twig` with:

  ```twig
  {% extends 'base.html.twig' %}
  {% block title %}New user · Admin{% endblock %}

  {% block stylesheets %}
  <style>
  .role-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    user-select: none;
  }
  .role-card:hover { border-color: #0d6efd; background: #f0f6ff; }
  .role-card.selected { border-color: #0d6efd; background: #e8f0fe; }
  .strength-bar { height: 4px; border-radius: 2px; transition: width .3s, background .3s; }
  .section-label { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #6c757d; margin-bottom: 14px; }
  .pw-req { font-size: .82rem; color: #6c757d; }
  .pw-req.ok { color: #198754; }
  </style>
  {% endblock %}

  {% block body %}
  <div class="container" style="max-width: 680px;">

    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path('app_admin_user_index') }}">Users</a></li>
        <li class="breadcrumb-item active">New user</li>
      </ol>
    </nav>

    <h4 class="fw-semibold mb-4"><i class="bi bi-person-plus me-2 text-primary"></i>New user</h4>

    {{ form_start(form) }}

    {# ── Card 1: Identity ── #}
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body p-4">
        <p class="section-label"><i class="bi bi-person me-1"></i>Identity</p>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="{{ form.fullName.vars.id }}">Full name</label>
          <div class="input-group {% if form.fullName.vars.errors|length %}has-validation{% endif %}">
            <span class="input-group-text bg-white text-muted"><i class="bi bi-person"></i></span>
            {{ form_widget(form.fullName, {attr: {class: 'form-control' ~ (form.fullName.vars.errors|length ? ' is-invalid' : '')}}) }}
            {% for error in form.fullName.vars.errors %}
              <div class="invalid-feedback">{{ error.message }}</div>
            {% endfor %}
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="{{ form.email.vars.id }}">Email</label>
          <div class="input-group {% if form.email.vars.errors|length %}has-validation{% endif %}">
            <span class="input-group-text bg-white text-muted"><i class="bi bi-envelope"></i></span>
            {{ form_widget(form.email, {attr: {class: 'form-control' ~ (form.email.vars.errors|length ? ' is-invalid' : '')}}) }}
            {% for error in form.email.vars.errors %}
              <div class="invalid-feedback">{{ error.message }}</div>
            {% endfor %}
          </div>
          <div class="form-text">Used to log in and receive follow-up reminders.</div>
        </div>

        <div class="mb-1">
          <label class="form-label fw-semibold small" for="{{ form.department.vars.id }}">Department <span class="text-muted fw-normal">(optional)</span></label>
          <div class="input-group">
            <span class="input-group-text bg-white text-muted"><i class="bi bi-building"></i></span>
            {{ form_widget(form.department, {attr: {class: 'form-select'}}) }}
          </div>
          {{ form_errors(form.department) }}
        </div>
      </div>
    </div>

    {# ── Card 2: Access ── #}
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body p-4">
        <p class="section-label"><i class="bi bi-shield-lock me-1"></i>Access</p>

        {# Password #}
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="pw_first">Password</label>
          <div class="input-group {% if form.password.vars.errors|length %}has-validation{% endif %}">
            <span class="input-group-text bg-white text-muted"><i class="bi bi-lock"></i></span>
            {{ form_widget(form.password.first, {attr: {
              class: 'form-control' ~ (form.password.vars.errors|length ? ' is-invalid' : ''),
              id: 'pw_first',
              oninput: 'updateStrength(this.value)'
            }}) }}
            <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw_first', this)" tabindex="-1" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
            {% for error in form.password.vars.errors %}
              <div class="invalid-feedback">{{ error.message }}</div>
            {% endfor %}
          </div>

          <div class="mt-2" id="strengthWrap" style="display:none">
            <div class="d-flex gap-1 mb-1">
              <div class="strength-bar flex-fill bg-secondary" id="s1"></div>
              <div class="strength-bar flex-fill bg-secondary" id="s2"></div>
              <div class="strength-bar flex-fill bg-secondary" id="s3"></div>
              <div class="strength-bar flex-fill bg-secondary" id="s4"></div>
            </div>
            <span id="strengthLabel" class="form-text"></span>
          </div>

          <div class="mt-2 d-flex gap-3 flex-wrap">
            <span class="pw-req" id="req-len"><i class="bi bi-x-circle me-1"></i>8+ characters</span>
            <span class="pw-req" id="req-upper"><i class="bi bi-x-circle me-1"></i>Uppercase letter</span>
            <span class="pw-req" id="req-digit"><i class="bi bi-x-circle me-1"></i>Number</span>
          </div>
        </div>

        {# Repeat password #}
        <div class="mb-4">
          <label class="form-label fw-semibold small" for="pw_second">Repeat password</label>
          <div class="input-group">
            <span class="input-group-text bg-white text-muted"><i class="bi bi-lock-fill"></i></span>
            {{ form_widget(form.password.second, {attr: {class: 'form-control', id: 'pw_second'}}) }}
            <button class="btn btn-outline-secondary" type="button" onclick="togglePw('pw_second', this)" tabindex="-1" aria-label="Show password">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        {# Role picker — visual cards; hidden checkboxes do the actual submitting #}
        <p class="section-label"><i class="bi bi-shield me-1"></i>Role</p>

        <div class="d-none">
          {{ form_row(form.roles) }}
        </div>

        <div class="d-flex flex-column gap-2" id="roleGroup">
          <div class="role-card" data-role="ROLE_SUBMITTER" onclick="selectRole(this)">
            <div class="d-flex align-items-start gap-2">
              <span class="badge text-bg-secondary mt-1" style="font-size:.7rem">Submitter</span>
              <div>
                <div class="fw-semibold small">Submitter</div>
                <div class="text-muted" style="font-size:.82rem">Can create and edit their own decisions.</div>
              </div>
            </div>
          </div>
          <div class="role-card" data-role="ROLE_APPROVER" onclick="selectRole(this)">
            <div class="d-flex align-items-start gap-2">
              <span class="badge text-bg-warning mt-1" style="font-size:.7rem">Approver</span>
              <div>
                <div class="fw-semibold small">Approver</div>
                <div class="text-muted" style="font-size:.82rem">Can approve or reject any decision. Includes Submitter permissions.</div>
              </div>
            </div>
          </div>
          <div class="role-card" data-role="ROLE_ADMIN" onclick="selectRole(this)">
            <div class="d-flex align-items-start gap-2">
              <span class="badge text-bg-danger mt-1" style="font-size:.7rem">Admin</span>
              <div>
                <div class="fw-semibold small">Admin</div>
                <div class="text-muted" style="font-size:.82rem">Full access — manage users, import CSV, and all Approver permissions.</div>
              </div>
            </div>
          </div>
        </div>

        {% if form.roles.vars.errors|length %}
          <div class="text-danger small mt-2">
            {% for error in form.roles.vars.errors %}{{ error.message }}{% endfor %}
          </div>
        {% endif %}

      </div>
    </div>

    <div class="d-flex gap-2 mb-5">
      <button class="btn btn-primary px-4" type="submit"><i class="bi bi-person-check me-1"></i>Create user</button>
      <a class="btn btn-outline-secondary" href="{{ path('app_admin_user_index') }}">Cancel</a>
    </div>

    {{ form_end(form) }}
  </div>
  {% endblock %}

  {% block javascripts %}
  <script>
  // ── Password show/hide ──
  function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
  }

  // ── Password strength ──
  function updateStrength(v) {
    const wrap = document.getElementById('strengthWrap');
    wrap.style.display = v.length ? 'block' : 'none';
    const bars = ['s1','s2','s3','s4'].map(id => document.getElementById(id));
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    const colors = ['#dc3545','#fd7e14','#ffc107','#198754'];
    const labels = ['Too weak','Weak','Good','Strong'];
    bars.forEach((b, i) => {
      b.style.background = i < score ? colors[score - 1] : '#dee2e6';
    });
    const lbl = document.getElementById('strengthLabel');
    lbl.textContent = score ? labels[score - 1] : '';
    lbl.style.color   = score ? colors[score - 1] : '';
    setReq('req-len',   v.length >= 8);
    setReq('req-upper', /[A-Z]/.test(v));
    setReq('req-digit', /[0-9]/.test(v));
  }
  function setReq(id, ok) {
    const el = document.getElementById(id);
    el.classList.toggle('ok', ok);
    el.querySelector('i').className = ok ? 'bi bi-check-circle-fill me-1' : 'bi bi-x-circle me-1';
  }

  // ── Role picker ──
  const roleOrder = ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER'];

  function selectRole(card) {
    document.querySelectorAll('#roleGroup .role-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    const chosen = card.dataset.role;
    roleOrder.forEach((r, i) => {
      const cb = document.querySelector('[name="user_create[roles][' + i + ']"]');
      if (cb) cb.checked = (r === chosen);
    });
  }

  // Initialise visual state from checked checkboxes (handles re-render after validation error)
  document.addEventListener('DOMContentLoaded', () => {
    let initialised = false;
    roleOrder.forEach((r, i) => {
      const cb = document.querySelector('[name="user_create[roles][' + i + ']"]');
      if (cb && cb.checked) {
        const card = document.querySelector('[data-role="' + r + '"]');
        if (card) { card.classList.add('selected'); initialised = true; }
      }
    });
    if (!initialised) {
      const def = document.querySelector('[data-role="ROLE_SUBMITTER"]');
      if (def) def.classList.add('selected');
    }
  });
  </script>
  {% endblock %}
  ```

- [ ] **Step 2: Smoke-test the page manually**

  Open http://localhost:8180/admin/users/new in a browser. Verify:
  - Two cards render correctly (Identity / Access)
  - Typing in the Password field updates the strength bar and requirement ticks
  - Clicking a role card highlights it and deselects the others
  - Eye buttons toggle password visibility
  - Department select shows the three options plus blank

- [ ] **Step 3: Run existing controller tests — they must still pass**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox
  ```

  Expected: all tests green. The hidden checkboxes keep `user_create[roles][0/1/2]` in the DOM, so `setRoleCheckboxes()` still works.

- [ ] **Step 4: Commit**

  ```bash
  git add templates/admin/user/new.html.twig
  git commit -m "feat: redesign new user form — two-card layout with password UX and role picker"
  ```

---

## Task 5: Update edit.html.twig and show.html.twig

**Files:**
- Modify: `templates/admin/user/edit.html.twig`
- Modify: `templates/admin/user/show.html.twig`

- [ ] **Step 1: Write a failing test for department on edit**

  In `tests/Controller/Admin/UserControllerTest.php`, add after `testEditUserUpdatesFields`:

  ```php
  public function testEditUserUpdatesDepartment(): void
  {
      $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
      $target = $this->makeUser('target@example.com', 'Target', ['ROLE_SUBMITTER']);
      $this->client->loginUser($admin);

      $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/edit');
      self::assertResponseIsSuccessful();

      $form = $crawler->selectButton('Save')->form([
          'user_edit[email]'      => 'target@example.com',
          'user_edit[fullName]'   => 'Target',
          'user_edit[department]' => 'Manual',
      ]);
      $this->setEditRoleCheckboxes($form, 'ROLE_SUBMITTER');
      $this->client->submit($form);
      self::assertResponseRedirects();

      $this->em->clear();
      $reloaded = $this->em->getRepository(User::class)->find($target->getId());
      self::assertSame(\App\Enum\Department::Manual, $reloaded->getDepartment());
  }
  ```

- [ ] **Step 2: Run test to confirm it fails**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --filter testEditUserUpdatesDepartment --testdox
  ```

  Expected: FAIL — field not yet in the template.

- [ ] **Step 3: Add department to edit.html.twig**

  In `templates/admin/user/edit.html.twig`, add `{{ form_row(form.department) }}` after the fullName row. The full file becomes:

  ```twig
  {% extends 'base.html.twig' %}
  {% block title %}Edit {{ user.fullName }} · Admin{% endblock %}
  {% block body %}
  <div class="container" style="max-width: 640px;">
    <h1 class="mb-3"><i class="bi bi-pencil"></i> Edit user</h1>
    {% if isSelf %}
      <div class="alert alert-info">You are editing your own account. Role changes are not allowed from this form.</div>
    {% endif %}
    {{ form_start(form) }}
      {{ form_row(form.email) }}
      {{ form_row(form.fullName) }}
      {{ form_row(form.department) }}
      {% if form.roles is defined %}{{ form_row(form.roles) }}{% endif %}
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn btn-link" href="{{ path('app_admin_user_show', {id: user.id}) }}">Cancel</a>
    {{ form_end(form) }}
  </div>
  {% endblock %}
  ```

- [ ] **Step 4: Add department to show.html.twig**

  In `templates/admin/user/show.html.twig`, add a Department row to the `<dl>` after the Email row:

  ```twig
  <dt class="col-sm-3">Department</dt>
  <dd class="col-sm-9">{{ user.department ? user.department.label() : '—' }}</dd>
  ```

  The full file becomes:

  ```twig
  {% extends 'base.html.twig' %}
  {% block title %}{{ user.fullName }} · Admin{% endblock %}
  {% block body %}
  <div class="container" style="max-width: 720px;">
    <h1 class="mb-3"><i class="bi bi-person"></i> {{ user.fullName }}</h1>
    <dl class="row">
      <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><code>{{ user.email }}</code></dd>
      <dt class="col-sm-3">Department</dt>
      <dd class="col-sm-9">{{ user.department ? user.department.label() : '—' }}</dd>
      <dt class="col-sm-3">Roles</dt>
      <dd class="col-sm-9">
        {% for r in user.roles|filter(r => r != 'ROLE_USER') %}
          <span class="badge text-bg-{{ r == 'ROLE_ADMIN' ? 'danger' : (r == 'ROLE_APPROVER' ? 'warning' : 'secondary') }}">{{ r }}</span>
        {% else %}—{% endfor %}
      </dd>
      <dt class="col-sm-3">Status</dt>
      <dd class="col-sm-9">
        {% if user.placeholder %}<span class="badge text-bg-info">placeholder (CSV-imported stub)</span>
        {% else %}<span class="badge text-bg-success">active</span>{% endif %}
      </dd>
      <dt class="col-sm-3">Decisions</dt>
      <dd class="col-sm-9">{{ decisionCount }} (as submitter, approver, or follow-up owner)</dd>
    </dl>

    <div class="d-flex gap-2 mt-4">
      <a class="btn btn-outline-secondary" href="{{ path('app_admin_user_edit', {id: user.id}) }}">Edit</a>
      {% if user.placeholder %}
        <a class="btn btn-primary" href="{{ path('app_admin_user_promote', {id: user.id}) }}">Promote to real user</a>
      {% else %}
        <a class="btn btn-outline-warning" href="{{ path('app_admin_user_password', {id: user.id}) }}">Reset password</a>
      {% endif %}
      <a class="btn btn-link ms-auto" href="{{ path('app_admin_user_index') }}">Back</a>
    </div>
  </div>
  {% endblock %}
  ```

- [ ] **Step 5: Run new test — it should pass**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --filter testEditUserUpdatesDepartment --testdox
  ```

  Expected: PASS.

- [ ] **Step 6: Run the full test suite**

  ```bash
  docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox
  ```

  Expected: all tests pass.

- [ ] **Step 7: Verify the show page renders department**

  Open a user's show page in the browser (e.g. click into any user from `/admin/users`). Confirm the "Department" row appears — showing `—` for users without one.

- [ ] **Step 8: Commit**

  ```bash
  git add templates/admin/user/edit.html.twig templates/admin/user/show.html.twig tests/Controller/Admin/UserControllerTest.php
  git commit -m "feat: surface department field on edit and show user pages"
  ```

- [ ] **Step 9: Push**

  ```bash
  git push
  ```
