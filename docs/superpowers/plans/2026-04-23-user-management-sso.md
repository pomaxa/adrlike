# User Management UI + EntraID SSO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship an admin user-management UI and additive Microsoft Entra ID SSO login for the Symfony 8 decision-recording app.

**Architecture:** Admin-only controller under `/admin/users` with CRUD-ish flows (list, create, edit, reset password, promote placeholder). Entra ID SSO via `knpuniversity/oauth2-client-bundle` + `thenetworg/oauth2-azure`, wired as a custom authenticator alongside the existing form login. SSO is feature-flagged by `SSO_ENABLED`; first-login auto-provisions `ROLE_SUBMITTER` and promotes placeholders when there's exactly one name match.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3, Twig 3, Bootstrap 5, PHPUnit 11, `knpuniversity/oauth2-client-bundle`, `thenetworg/oauth2-azure`, PostgreSQL 17. Everything inside `docker compose`.

**Spec:** `docs/superpowers/specs/2026-04-23-user-management-sso-design.md`

## Conventions used in this plan

- All shell commands assume the stack is up. If not: `docker compose up -d --build`.
- Tests must run with `APP_ENV=test` — the compose file pins the container to `dev`, so every test command overrides with `-e APP_ENV=test`.
- Test DB must exist: `docker compose exec -T database psql -U app -d postgres -c "CREATE DATABASE app_test OWNER app ENCODING 'UTF8';" || true` (run once; idempotent).
- After any entity/migration change in test, run `docker compose exec -T -e APP_ENV=test app php bin/console doctrine:migrations:migrate -n`.
- We commit after every task completes green.

---

## Task 1: Prep — ensure test DB and baseline is green

**Files:**
- No changes; verification only.

- [ ] **Step 1: Bring stack up and load fixtures in dev**

Run:
```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate -n
docker compose exec app php bin/console doctrine:fixtures:load -n
```
Expected: all three succeed; dev DB has `admin@example.com` / `admin`.

- [ ] **Step 2: Create/verify test DB and run migrations in test env**

Run:
```bash
docker compose exec -T database psql -U app -d postgres -c "CREATE DATABASE app_test OWNER app ENCODING 'UTF8';" || true
docker compose exec -T -e APP_ENV=test app php bin/console doctrine:migrations:migrate -n
docker compose exec -T -e APP_ENV=test app php bin/console cache:clear
```
Expected: succeed (idempotent if already created).

- [ ] **Step 3: Run existing test suite to confirm green baseline**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: all tests pass. If any fail, stop and repair the baseline before continuing.

- [ ] **Step 4: Commit the baseline-verification marker (empty commit)**

```bash
git commit --allow-empty -m "chore: baseline verified before user-management work"
```

---

## Task 2: Add UniqueEntity constraint + admin list query to User / UserRepository

**Files:**
- Modify: `src/Entity/User.php`
- Modify: `src/Repository/UserRepository.php`
- Create: `tests/Repository/UserRepositoryTest.php`

- [ ] **Step 1: Write failing repository test for `queryForAdminList`**

Create `tests/Repository/UserRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(User::class);

        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        $alice = new User('alice@example.com', 'Alice Real');
        $alice->setRoles(['ROLE_ADMIN']);
        $this->em->persist($alice);

        $bob = new User('bob@example.com', 'Bob Submitter');
        $bob->setRoles(['ROLE_SUBMITTER']);
        $this->em->persist($bob);

        $ghost = new User('ghost-one@imported.local', 'Ghost One');
        $ghost->setPlaceholder(true);
        $this->em->persist($ghost);

        $this->em->flush();
    }

    public function testQueryForAdminListReturnsAllUsersSortedByName(): void
    {
        $users = $this->repo->queryForAdminList([])->getQuery()->getResult();
        $names = array_map(fn (User $u) => $u->getFullName(), $users);

        self::assertSame(['Alice Real', 'Bob Submitter', 'Ghost One'], $names);
    }

    public function testQueryForAdminListFiltersBySearchOnNameAndEmail(): void
    {
        $users = $this->repo->queryForAdminList(['search' => 'bob'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Bob Submitter', $users[0]->getFullName());

        $users = $this->repo->queryForAdminList(['search' => 'ghost-one@imported'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Ghost One', $users[0]->getFullName());
    }

    public function testQueryForAdminListFiltersByRole(): void
    {
        $users = $this->repo->queryForAdminList(['role' => 'ROLE_ADMIN'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Alice Real', $users[0]->getFullName());
    }

    public function testQueryForAdminListFiltersPlaceholders(): void
    {
        $users = $this->repo->queryForAdminList(['placeholder' => true])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Ghost One', $users[0]->getFullName());

        $users = $this->repo->queryForAdminList(['placeholder' => false])->getQuery()->getResult();
        self::assertCount(2, $users);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Repository/UserRepositoryTest.php --testdox`
Expected: FAIL — `queryForAdminList` does not exist.

- [ ] **Step 3: Add `queryForAdminList` to `UserRepository`**

Add to `src/Repository/UserRepository.php` (inside the class, keep existing methods):

```php
    /**
     * @param array{search?: string|null, role?: string|null, placeholder?: bool|null} $filters
     */
    public function queryForAdminList(array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.fullName', 'ASC');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :s OR LOWER(u.email) LIKE :s')
               ->setParameter('s', '%' . strtolower($search) . '%');
        }

        $role = $filters['role'] ?? null;
        if (is_string($role) && $role !== '') {
            $qb->andWhere('CAST(u.roles AS TEXT) LIKE :role')
               ->setParameter('role', '%"' . $role . '"%');
        }

        if (array_key_exists('placeholder', $filters) && is_bool($filters['placeholder'])) {
            $qb->andWhere('u.placeholder = :ph')->setParameter('ph', $filters['placeholder']);
        }

        return $qb;
    }
```

- [ ] **Step 4: Add `UniqueEntity` constraint to `User`**

Modify `src/Entity/User.php` — add `use` and attribute:

```php
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
```

Add class-level attribute just before `class User`:

```php
#[UniqueEntity(fields: ['email'], message: 'A user with this email already exists.')]
```

Update the email column with validation:

```php
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $fullName;
```

- [ ] **Step 5: Run the new test — should pass; run full suite**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Repository/UserRepositoryTest.php --testdox`
Expected: PASS.

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: all existing tests still pass.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/User.php src/Repository/UserRepository.php tests/Repository/UserRepositoryTest.php
git commit -m "feat(user): add UniqueEntity + queryForAdminList"
```

---

## Task 3: Admin user controller skeleton + routing + navbar link + 403 test

**Files:**
- Create: `src/Controller/Admin/UserController.php`
- Modify: `templates/base.html.twig`
- Create: `templates/admin/user/index.html.twig` (placeholder)
- Create: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Write failing access-control test**

Create `tests/Controller/Admin/UserControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    protected function makeUser(string $email, string $name, array $roles = ['ROLE_SUBMITTER'], bool $placeholder = false, ?string $password = 'test'): User
    {
        $u = new User($email, $name);
        $u->setRoles($roles);
        if ($placeholder) {
            $u->setPlaceholder(true);
        }
        if ($password !== null) {
            $u->setPassword($this->hasher->hashPassword($u, $password));
        }
        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }

    public function testIndexForbiddenForSubmitter(): void
    {
        $sub = $this->makeUser('sub@example.com', 'Sub', ['ROLE_SUBMITTER']);
        $this->client->loginUser($sub);
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexForbiddenForApprover(): void
    {
        $app = $this->makeUser('app@example.com', 'App', ['ROLE_APPROVER']);
        $this->client->loginUser($app);
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexAccessibleForAdmin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 2: Run test — expect failures**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: 3 failures / 404s (route not registered yet).

- [ ] **Step 3: Create the controller skeleton**

Create `src/Controller/Admin/UserController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->query->get('q'),
            'role' => $request->query->get('role'),
            'placeholder' => match ($request->query->get('placeholder')) {
                'yes' => true,
                'no' => false,
                default => null,
            },
        ];

        $users = $this->users->queryForAdminList($filters)
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }
}
```

- [ ] **Step 4: Create minimal template**

Create `templates/admin/user/index.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Users · Admin{% endblock %}
{% block body %}
<div class="container">
  <h1 class="mb-3"><i class="bi bi-people"></i> Users</h1>
  <p class="text-muted">User management (placeholder — content comes in later tasks).</p>
</div>
{% endblock %}
```

- [ ] **Step 5: Add "Users" link to navbar**

Modify `templates/base.html.twig`. Locate the admin-only section:

```twig
{% if is_granted('ROLE_ADMIN') %}
    <li class="nav-item"><a class="nav-link" href="{{ path('app_decision_import') }}"><i class="bi bi-cloud-upload"></i> Import CSV</a></li>
{% endif %}
```

Replace with:

```twig
{% if is_granted('ROLE_ADMIN') %}
    <li class="nav-item"><a class="nav-link" href="{{ path('app_decision_import') }}"><i class="bi bi-cloud-upload"></i> Import CSV</a></li>
    <li class="nav-item"><a class="nav-link" href="{{ path('app_admin_user_index') }}"><i class="bi bi-people"></i> Users</a></li>
{% endif %}
```

- [ ] **Step 6: Run the tests — all three pass**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: 3 passing.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/index.html.twig templates/base.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): scaffold admin user controller + navbar link"
```

---

## Task 4: User list page — full template with filters + decision count

**Files:**
- Modify: `templates/admin/user/index.html.twig`
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add a failing test for list content and filters**

Append these methods to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testIndexListsUsersAndAppliesSearchFilter(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->makeUser('zoe@example.com', 'Zoe Zebra', ['ROLE_SUBMITTER']);
        $this->makeUser('p@imported.local', 'Placeholder Pat', ['ROLE_SUBMITTER'], placeholder: true, password: null);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zoe Zebra');
        self::assertSelectorTextContains('body', 'Placeholder Pat');

        $this->client->request('GET', '/admin/users?q=zoe');
        self::assertSelectorTextContains('body', 'Zoe Zebra');
        self::assertSelectorTextNotContains('body', 'Placeholder Pat');

        $this->client->request('GET', '/admin/users?placeholder=yes');
        self::assertSelectorTextContains('body', 'Placeholder Pat');
        self::assertSelectorTextNotContains('body', 'Zoe Zebra');
    }
```

- [ ] **Step 2: Run the test — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testIndexListsUsersAndAppliesSearchFilter`
Expected: FAIL — placeholder template doesn't render names.

- [ ] **Step 3: Replace the placeholder template with the real list view**

Replace `templates/admin/user/index.html.twig` entirely with:

```twig
{% extends 'base.html.twig' %}
{% block title %}Users · Admin{% endblock %}
{% block body %}
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0"><i class="bi bi-people"></i> Users</h1>
    <a class="btn btn-primary" href="{{ path('app_admin_user_new') }}"><i class="bi bi-person-plus"></i> New user</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4">
      <input type="search" class="form-control" name="q" value="{{ filters.search ?? '' }}" placeholder="Search name or email">
    </div>
    <div class="col-md-3">
      <select class="form-select" name="role">
        <option value="">Any role</option>
        {% for r in ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER'] %}
          <option value="{{ r }}" {{ filters.role == r ? 'selected' : '' }}>{{ r }}</option>
        {% endfor %}
      </select>
    </div>
    <div class="col-md-3">
      <select class="form-select" name="placeholder">
        <option value="">Real + placeholder</option>
        <option value="no" {{ filters.placeholder is same as(false) ? 'selected' : '' }}>Real users only</option>
        <option value="yes" {{ filters.placeholder is same as(true) ? 'selected' : '' }}>Placeholders only</option>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr>
        <th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      {% for user in users %}
        <tr>
          <td>{{ user.fullName }}</td>
          <td><code>{{ user.email }}</code></td>
          <td>
            {% for r in user.roles|filter(r => r != 'ROLE_USER') %}
              <span class="badge text-bg-{{ r == 'ROLE_ADMIN' ? 'danger' : (r == 'ROLE_APPROVER' ? 'warning' : 'secondary') }}">{{ r }}</span>
            {% endfor %}
          </td>
          <td>
            {% if user.placeholder %}
              <span class="badge text-bg-info">placeholder</span>
            {% else %}
              <span class="badge text-bg-success">active</span>
            {% endif %}
          </td>
          <td class="text-end">
            <div class="btn-group btn-group-sm">
              <a class="btn btn-outline-secondary" href="{{ path('app_admin_user_show', {id: user.id}) }}">View</a>
              <a class="btn btn-outline-secondary" href="{{ path('app_admin_user_edit', {id: user.id}) }}">Edit</a>
              {% if user.placeholder %}
                <a class="btn btn-outline-primary" href="{{ path('app_admin_user_promote', {id: user.id}) }}">Promote</a>
              {% else %}
                <a class="btn btn-outline-warning" href="{{ path('app_admin_user_password', {id: user.id}) }}">Reset pw</a>
              {% endif %}
            </div>
          </td>
        </tr>
      {% else %}
        <tr><td colspan="5" class="text-center text-muted">No users match.</td></tr>
      {% endfor %}
      </tbody>
    </table>
  </div>
</div>
{% endblock %}
```

Note: this template references routes (`app_admin_user_new`, `_show`, `_edit`, `_password`, `_promote`) that will be added in later tasks. Twig renders them as URL generation at request time; tests that don't hit those pages won't fail, but trying to render the page will error because `path()` fails for unknown routes. To unblock this task, we add all route stubs now in Step 4.

- [ ] **Step 4: Add route stubs to the controller**

Add these methods to `src/Controller/Admin/UserController.php` (below `index`, keep `index` intact):

```php
    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return new Response('stub', 501);
    }

    #[Route('/{id}', name: 'app_admin_user_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        return new Response('stub', 501);
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(Request $request, string $id): Response
    {
        return new Response('stub', 501);
    }

    #[Route('/{id}/password', name: 'app_admin_user_password', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function resetPassword(Request $request, string $id): Response
    {
        return new Response('stub', 501);
    }

    #[Route('/{id}/promote', name: 'app_admin_user_promote', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function promotePlaceholder(Request $request, string $id): Response
    {
        return new Response('stub', 501);
    }
```

- [ ] **Step 5: Run the tests — all pass**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all passing.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/Admin/UserController.php templates/admin/user/index.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): index list with filters and action buttons"
```

---

## Task 5: Create user flow

**Files:**
- Create: `src/Form/UserCreateType.php`
- Modify: `src/Controller/Admin/UserController.php` (replace `new` stub)
- Create: `templates/admin/user/new.html.twig`
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add failing test for the create flow**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testCreateUserFlowPersistsAndAllowsLogin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create user')->form([
            'user_create[email]' => 'new@example.com',
            'user_create[fullName]' => 'New User',
            'user_create[password][first]' => 'secretpass',
            'user_create[password][second]' => 'secretpass',
            'user_create[roles]' => ['ROLE_APPROVER'],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertNotNull($created);
        self::assertSame('New User', $created->getFullName());
        self::assertContains('ROLE_APPROVER', $created->getRoles());
        self::assertFalse($created->isPlaceholder());
        self::assertTrue($this->hasher->isPasswordValid($created, 'secretpass'));
    }

    public function testCreateUserRejectsDuplicateEmail(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->makeUser('dup@example.com', 'Dup', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Create user')->form([
            'user_create[email]' => 'dup@example.com',
            'user_create[fullName]' => 'Second Dup',
            'user_create[password][first]' => 'secretpass',
            'user_create[password][second]' => 'secretpass',
            'user_create[roles]' => ['ROLE_SUBMITTER'],
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
    }
```

- [ ] **Step 2: Run tests — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testCreateUser`
Expected: FAIL (route returns 501 stub).

- [ ] **Step 3: Create `UserCreateType`**

Create `src/Form/UserCreateType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('fullName', TextType::class, ['label' => 'Full name'])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(min: 8, minMessage: 'Password must be at least 8 characters.')],
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
                'invalid_message' => 'The passwords must match.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Approver' => 'ROLE_APPROVER',
                    'Submitter' => 'ROLE_SUBMITTER',
                ],
                'data' => ['ROLE_SUBMITTER'],
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'empty_data' => fn () => new User('', ''),
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'user_create';
    }
}
```

Note: because `User` has no default constructor-less instantiation, we override `empty_data` to build a placeholder instance; the controller overwrites email/fullName from form data.

- [ ] **Step 4: Replace `new` controller action**

Replace the `new` stub in `src/Controller/Admin/UserController.php`. First add imports at the top of the file:

```php
use App\Entity\User;
use App\Form\UserCreateType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
```

Update the constructor:

```php
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }
```

Replace the `new` method body:

```php
    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User('', '');
        $form = $this->createForm(UserCreateType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = (string) $form->get('password')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->setRoles($form->get('roles')->getData());
            $user->setPlaceholder(false);

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('Created user %s.', $user->getEmail()));
            return $this->redirectToRoute('app_admin_user_index');
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/new.html.twig', ['form' => $form->createView()], new Response(null, $status));
    }
```

- [ ] **Step 5: Create the `new` template**

Create `templates/admin/user/new.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}New user · Admin{% endblock %}
{% block body %}
<div class="container" style="max-width: 640px;">
  <h1 class="mb-3"><i class="bi bi-person-plus"></i> New user</h1>
  {{ form_start(form) }}
    {{ form_row(form.email) }}
    {{ form_row(form.fullName) }}
    {{ form_row(form.password.first) }}
    {{ form_row(form.password.second) }}
    {{ form_row(form.roles) }}
    <button class="btn btn-primary" type="submit">Create user</button>
    <a class="btn btn-link" href="{{ path('app_admin_user_index') }}">Cancel</a>
  {{ form_end(form) }}
</div>
{% endblock %}
```

- [ ] **Step 6: Run tests**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all pass, including the two new create-flow tests.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/UserController.php src/Form/UserCreateType.php templates/admin/user/new.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): create-user flow with password + role selection"
```

---

## Task 6: Show user detail page with decision counts

**Files:**
- Create: `templates/admin/user/show.html.twig`
- Modify: `src/Repository/UserRepository.php` (add `countDecisionReferences`)
- Modify: `src/Controller/Admin/UserController.php` (replace `show` stub)
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add failing test**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testShowUserPage(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target User', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Target User');
        self::assertSelectorTextContains('body', 'target@example.com');
    }
```

- [ ] **Step 2: Run — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testShowUserPage`
Expected: FAIL (501 stub).

- [ ] **Step 3: Add `countDecisionReferences` to `UserRepository`**

Add to `src/Repository/UserRepository.php`:

```php
    public function countDecisionReferences(User $user): int
    {
        $dql = 'SELECT COUNT(d.id) FROM App\Entity\Decision d
                WHERE d.submittedBy = :u OR d.approvedBy = :u OR d.followUpOwner = :u';
        return (int) $this->getEntityManager()->createQuery($dql)
            ->setParameter('u', $user)
            ->getSingleScalarResult();
    }
```

- [ ] **Step 4: Replace `show` action**

Replace `show` in `src/Controller/Admin/UserController.php`:

```php
    #[Route('/{id}', name: 'app_admin_user_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'decisionCount' => $this->users->countDecisionReferences($user),
        ]);
    }
```

- [ ] **Step 5: Create show template**

Create `templates/admin/user/show.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ user.fullName }} · Admin{% endblock %}
{% block body %}
<div class="container" style="max-width: 720px;">
  <h1 class="mb-3"><i class="bi bi-person"></i> {{ user.fullName }}</h1>
  <dl class="row">
    <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><code>{{ user.email }}</code></dd>
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

- [ ] **Step 6: Run tests**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/UserController.php src/Repository/UserRepository.php templates/admin/user/show.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): show page with decision reference count"
```

---

## Task 7: Edit user flow with self-protection on roles

**Files:**
- Create: `src/Form/UserEditType.php`
- Modify: `src/Controller/Admin/UserController.php` (replace `edit` stub)
- Create: `templates/admin/user/edit.html.twig`
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testEditUserUpdatesFields(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target Old', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save')->form([
            'user_edit[email]' => 'target-new@example.com',
            'user_edit[fullName]' => 'Target New',
            'user_edit[roles]' => ['ROLE_APPROVER'],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertSame('target-new@example.com', $reloaded->getEmail());
        self::assertSame('Target New', $reloaded->getFullName());
        self::assertContains('ROLE_APPROVER', $reloaded->getRoles());
        self::assertNotContains('ROLE_SUBMITTER', $reloaded->getRoles());
    }

    public function testEditSelfHidesRolesField(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $admin->getId()->toRfc4122() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="user_edit[roles][]"]'));
    }

    public function testEditSelfCannotTamperWithRoles(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('POST', '/admin/users/' . $admin->getId()->toRfc4122() . '/edit', [
            'user_edit' => [
                'email' => 'adm@example.com',
                'fullName' => 'Adm',
                'roles' => ['ROLE_SUBMITTER'],
                '_token' => 'anything',
            ],
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($admin->getId());
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());
    }
```

- [ ] **Step 2: Run tests — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testEdit`
Expected: FAIL (501 stub).

- [ ] **Step 3: Create `UserEditType`**

Create `src/Form/UserEditType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('fullName', TextType::class, ['label' => 'Full name']);

        if (!$options['is_self']) {
            $builder->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Approver' => 'ROLE_APPROVER',
                    'Submitter' => 'ROLE_SUBMITTER',
                ],
                'data' => array_values(array_intersect(
                    $options['current_roles'],
                    ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER']
                )),
                'constraints' => [new NotBlank()],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_self' => false,
            'current_roles' => [],
        ]);
        $resolver->setAllowedTypes('is_self', 'bool');
        $resolver->setAllowedTypes('current_roles', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'user_edit';
    }
}
```

- [ ] **Step 4: Replace `edit` action**

Add import in `src/Controller/Admin/UserController.php`:

```php
use App\Form\UserEditType;
```

Replace `edit`:

```php
    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        $isSelf = $user === $this->getUser();

        $form = $this->createForm(UserEditType::class, $user, [
            'is_self' => $isSelf,
            'current_roles' => $user->getRoles(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$isSelf) {
                $user->setRoles($form->get('roles')->getData());
            }
            $this->em->flush();
            $this->addFlash('success', 'User updated.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'isSelf' => $isSelf,
        ], new Response(null, $status));
    }
```

- [ ] **Step 5: Create edit template**

Create `templates/admin/user/edit.html.twig`:

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
    {% if form.roles is defined %}{{ form_row(form.roles) }}{% endif %}
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-link" href="{{ path('app_admin_user_show', {id: user.id}) }}">Cancel</a>
  {{ form_end(form) }}
</div>
{% endblock %}
```

- [ ] **Step 6: Run tests**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/UserController.php src/Form/UserEditType.php templates/admin/user/edit.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): edit flow with self-protection on role changes"
```

---

## Task 8: Password reset flow (and placeholder redirect)

**Files:**
- Create: `src/Form/PasswordResetType.php`
- Modify: `src/Controller/Admin/UserController.php` (replace `resetPassword` stub)
- Create: `templates/admin/user/password.html.twig`
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testResetPasswordChangesHash(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target', ['ROLE_SUBMITTER'], password: 'old-pw-123');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/password');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Set new password')->form([
            'password_reset[password][first]' => 'brandnewpw',
            'password_reset[password][second]' => 'brandnewpw',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertFalse($this->hasher->isPasswordValid($reloaded, 'old-pw-123'));
        self::assertTrue($this->hasher->isPasswordValid($reloaded, 'brandnewpw'));
    }

    public function testResetPasswordOnPlaceholderRedirectsToPromote(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $ph = $this->makeUser('ghost-a@imported.local', 'Ghost A', ['ROLE_SUBMITTER'], placeholder: true, password: null);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $ph->getId()->toRfc4122() . '/password');
        self::assertResponseRedirects('/admin/users/' . $ph->getId()->toRfc4122() . '/promote');
    }
```

- [ ] **Step 2: Run tests — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testResetPassword`
Expected: FAIL.

- [ ] **Step 3: Create `PasswordResetType`**

Create `src/Form/PasswordResetType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PasswordResetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'constraints' => [new NotBlank(), new Length(min: 8, minMessage: 'Password must be at least 8 characters.')],
            'first_options' => ['label' => 'New password'],
            'second_options' => ['label' => 'Repeat new password'],
            'invalid_message' => 'The passwords must match.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }

    public function getBlockPrefix(): string
    {
        return 'password_reset';
    }
}
```

- [ ] **Step 4: Replace `resetPassword` action**

Add imports to `src/Controller/Admin/UserController.php`:

```php
use App\Form\PasswordResetType;
use Psr\Log\LoggerInterface;
```

Extend constructor:

```php
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }
```

Replace `resetPassword`:

```php
    #[Route('/{id}/password', name: 'app_admin_user_password', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function resetPassword(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();

        if ($user->isPlaceholder()) {
            $this->addFlash('warning', 'Placeholder users must be promoted before a password can be set.');
            return $this->redirectToRoute('app_admin_user_promote', ['id' => $user->getId()]);
        }

        $form = $this->createForm(PasswordResetType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = (string) $form->get('password')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $this->em->flush();

            $actor = $this->getUser();
            $this->logger->info('Admin password reset', [
                'target_email' => $user->getEmail(),
                'actor_email' => $actor?->getUserIdentifier(),
            ]);

            $this->addFlash('success', sprintf('Password reset for %s.', $user->getEmail()));
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/password.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }
```

- [ ] **Step 5: Create template**

Create `templates/admin/user/password.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Reset password · {{ user.fullName }}{% endblock %}
{% block body %}
<div class="container" style="max-width: 520px;">
  <h1 class="mb-3"><i class="bi bi-key"></i> Reset password</h1>
  <p class="text-muted">Setting a new password for <strong>{{ user.fullName }}</strong> (<code>{{ user.email }}</code>).</p>
  {{ form_start(form) }}
    {{ form_row(form.password.first) }}
    {{ form_row(form.password.second) }}
    <button class="btn btn-warning" type="submit">Set new password</button>
    <a class="btn btn-link" href="{{ path('app_admin_user_show', {id: user.id}) }}">Cancel</a>
  {{ form_end(form) }}
</div>
{% endblock %}
```

- [ ] **Step 6: Run tests**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/Admin/UserController.php src/Form/PasswordResetType.php templates/admin/user/password.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): admin password reset with placeholder guard"
```

---

## Task 9: Promote placeholder flow

**Files:**
- Create: `src/Form/PromotePlaceholderType.php`
- Modify: `src/Controller/Admin/UserController.php` (replace `promotePlaceholder` stub)
- Create: `templates/admin/user/promote.html.twig`
- Modify: `tests/Controller/Admin/UserControllerTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testPromotePlaceholderPreservesUuidAndAllowsLogin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $ph = $this->makeUser('ghost-b@imported.local', 'Ghost B', ['ROLE_SUBMITTER'], placeholder: true, password: null);
        $originalId = $ph->getId()->toRfc4122();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $originalId . '/promote');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Promote user')->form([
            'promote_placeholder[email]' => 'ghost-b@real.example.com',
            'promote_placeholder[password][first]' => 'realpass1',
            'promote_placeholder[password][second]' => 'realpass1',
            'promote_placeholder[roles]' => ['ROLE_SUBMITTER'],
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($ph->getId());
        self::assertSame($originalId, $reloaded->getId()->toRfc4122());
        self::assertSame('ghost-b@real.example.com', $reloaded->getEmail());
        self::assertFalse($reloaded->isPlaceholder());
        self::assertTrue($this->hasher->isPasswordValid($reloaded, 'realpass1'));
    }

    public function testPromoteNonPlaceholderReturns404(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $real = $this->makeUser('real@example.com', 'Real', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $real->getId()->toRfc4122() . '/promote');
        self::assertResponseStatusCodeSame(404);
    }
```

- [ ] **Step 2: Run — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --filter testPromote`
Expected: FAIL.

- [ ] **Step 3: Create `PromotePlaceholderType`**

Create `src/Form/PromotePlaceholderType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PromotePlaceholderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Real email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'constraints' => [new NotBlank(), new Length(min: 8, minMessage: 'Password must be at least 8 characters.')],
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
                'invalid_message' => 'The passwords must match.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Approver' => 'ROLE_APPROVER',
                    'Submitter' => 'ROLE_SUBMITTER',
                ],
                'data' => ['ROLE_SUBMITTER'],
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }

    public function getBlockPrefix(): string
    {
        return 'promote_placeholder';
    }
}
```

- [ ] **Step 4: Replace `promotePlaceholder` action**

Add import in `src/Controller/Admin/UserController.php`:

```php
use App\Form\PromotePlaceholderType;
```

Replace the action:

```php
    #[Route('/{id}/promote', name: 'app_admin_user_promote', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function promotePlaceholder(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        if (!$user->isPlaceholder()) {
            throw $this->createNotFoundException('User is not a placeholder.');
        }

        $form = $this->createForm(PromotePlaceholderType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();

            $existing = $this->users->findOneByEmail($email);
            if ($existing !== null && $existing !== $user) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError('A user with this email already exists.'));
            } else {
                $user->setEmail($email);
                $user->setPassword($this->hasher->hashPassword($user, (string) $form->get('password')->getData()));
                $user->setRoles($form->get('roles')->getData());
                $user->setPlaceholder(false);
                $this->em->flush();

                $this->addFlash('success', sprintf('Promoted %s.', $user->getFullName()));
                return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
            }
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/promote.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }
```

- [ ] **Step 5: Create template**

Create `templates/admin/user/promote.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}Promote {{ user.fullName }} · Admin{% endblock %}
{% block body %}
<div class="container" style="max-width: 620px;">
  <h1 class="mb-3"><i class="bi bi-person-up"></i> Promote placeholder</h1>
  <div class="alert alert-info">
    Converting placeholder <strong>{{ user.fullName }}</strong> (<code>{{ user.email }}</code>) into a real user.
    The internal ID is preserved, so all existing decision references remain intact.
  </div>
  {{ form_start(form) }}
    {{ form_row(form.email) }}
    {{ form_row(form.password.first) }}
    {{ form_row(form.password.second) }}
    {{ form_row(form.roles) }}
    <button class="btn btn-primary" type="submit">Promote user</button>
    <a class="btn btn-link" href="{{ path('app_admin_user_show', {id: user.id}) }}">Cancel</a>
  {{ form_end(form) }}
</div>
{% endblock %}
```

- [ ] **Step 6: Run tests**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/Admin/UserControllerTest.php --testdox`
Expected: all pass.

- [ ] **Step 7: Run the full suite to make sure no regressions**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add src/Controller/Admin/UserController.php src/Form/PromotePlaceholderType.php templates/admin/user/promote.html.twig tests/Controller/Admin/UserControllerTest.php
git commit -m "feat(admin/users): promote placeholder flow preserving UUID"
```

---

## Task 10: Install Entra ID / OAuth2 packages

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `config/bundles.php` (if recipe doesn't add it automatically)

- [ ] **Step 1: Install packages**

Run: `docker compose exec app composer require knpuniversity/oauth2-client-bundle thenetworg/oauth2-azure`
Expected: both packages added. Accept the recipe if prompted. The recipe typically creates `config/packages/knpu_oauth2_client.yaml` (we will overwrite it in the next task) and registers the bundle in `config/bundles.php`.

- [ ] **Step 2: Verify the bundle is registered**

Run: `docker compose exec app php bin/console debug:container oauth2 | head -30`
Expected: shows services like `knpu.oauth2.registry` — confirms bundle is loaded.

- [ ] **Step 3: Run test suite — should still be green**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: pass. Tests don't yet touch the new packages.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/bundles.php config/packages/knpu_oauth2_client.yaml 2>/dev/null || true
git add composer.json composer.lock config/
git commit -m "chore: add knpuniversity/oauth2-client-bundle + thenetworg/oauth2-azure"
```

---

## Task 11: SSO env vars + config + SsoStatusProvider

**Files:**
- Modify: `.env`
- Modify: `compose.yaml` (pass env to the `app` service)
- Overwrite: `config/packages/knpu_oauth2_client.yaml`
- Create: `src/Service/SsoStatusProvider.php`
- Create: `tests/Service/SsoStatusProviderTest.php`

- [ ] **Step 1: Write failing test for `SsoStatusProvider`**

Create `tests/Service/SsoStatusProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SsoStatusProvider;
use PHPUnit\Framework\TestCase;

final class SsoStatusProviderTest extends TestCase
{
    public function testDisabledWhenFlagOff(): void
    {
        $p = new SsoStatusProvider(false, 'tenant-abcd-1234', 'client', 'secret');
        self::assertFalse($p->isEnabled());
        self::assertSame('not_configured', $p->statusCode());
    }

    public function testMisconfiguredWhenFlagOnButMissingVars(): void
    {
        $p = new SsoStatusProvider(true, 'tenant', '', 'secret');
        self::assertTrue($p->isEnabled());
        self::assertSame('misconfigured', $p->statusCode());
    }

    public function testEnabledShowsMaskedTenantSuffix(): void
    {
        $p = new SsoStatusProvider(true, 'abcdef-1234-5678-9abc', 'client', 'secret');
        self::assertSame('enabled', $p->statusCode());
        self::assertSame('9abc', $p->tenantSuffix());
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Service/SsoStatusProviderTest.php --testdox`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `SsoStatusProvider`**

Create `src/Service/SsoStatusProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SsoStatusProvider
{
    public function __construct(
        #[Autowire(env: 'bool:SSO_ENABLED')]
        private readonly bool $enabled,
        #[Autowire(env: 'AZURE_TENANT_ID')]
        private readonly string $tenantId,
        #[Autowire(env: 'AZURE_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'AZURE_CLIENT_SECRET')]
        private readonly string $clientSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function statusCode(): string
    {
        if (!$this->enabled) {
            return 'not_configured';
        }
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '') {
            return 'misconfigured';
        }
        return 'enabled';
    }

    public function tenantSuffix(): string
    {
        return substr($this->tenantId, -4);
    }
}
```

- [ ] **Step 4: Run unit test — passes**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Service/SsoStatusProviderTest.php --testdox`
Expected: 3 passing.

- [ ] **Step 5: Add env vars to `.env`**

Open `.env` and append:

```
###> app/sso ###
SSO_ENABLED=false
AZURE_TENANT_ID=
AZURE_CLIENT_ID=
AZURE_CLIENT_SECRET=
AZURE_REDIRECT_URI=http://localhost:8180/login/azure/check
###< app/sso ###
```

- [ ] **Step 6: Ensure `compose.yaml` passes the new env vars to the `app` service**

Open `compose.yaml`. Locate the `app` service's `environment:` block. Ensure these keys are present (add only ones that are missing):

```yaml
      SSO_ENABLED: "${SSO_ENABLED:-false}"
      AZURE_TENANT_ID: "${AZURE_TENANT_ID:-}"
      AZURE_CLIENT_ID: "${AZURE_CLIENT_ID:-}"
      AZURE_CLIENT_SECRET: "${AZURE_CLIENT_SECRET:-}"
      AZURE_REDIRECT_URI: "${AZURE_REDIRECT_URI:-http://localhost:8180/login/azure/check}"
```

- [ ] **Step 7: Overwrite `config/packages/knpu_oauth2_client.yaml`**

Write `config/packages/knpu_oauth2_client.yaml`:

```yaml
knpu_oauth2_client:
    clients:
        azure:
            type: azure
            client_id: '%env(AZURE_CLIENT_ID)%'
            client_secret: '%env(AZURE_CLIENT_SECRET)%'
            redirect_route: app_azure_check
            redirect_params: {}
            tenant: '%env(AZURE_TENANT_ID)%'
            url_api_version: '2.0'
            default_end_point_version: '2.0'
            scope: 'openid profile email User.Read'
```

- [ ] **Step 8: Rebuild containers so new env vars propagate**

Run:
```bash
docker compose up -d
docker compose exec app php bin/console cache:clear
docker compose exec -T -e APP_ENV=test app php bin/console cache:clear
```

- [ ] **Step 9: Run full suite**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: all pass.

- [ ] **Step 10: Commit**

```bash
git add .env compose.yaml config/packages/knpu_oauth2_client.yaml src/Service/SsoStatusProvider.php tests/Service/SsoStatusProviderTest.php
git commit -m "feat(sso): SSO env flags + knpu azure client config + status provider"
```

---

## Task 12: AzureAuthController + Authenticator with user resolution

**Files:**
- Create: `src/Controller/AzureAuthController.php`
- Create: `src/Security/AzureAuthenticator.php`
- Create: `src/Security/AzureUserResolver.php` (pure user-matching logic, testable)
- Create: `tests/Security/AzureUserResolverTest.php`

- [ ] **Step 1: Write failing unit tests for `AzureUserResolver`**

Create `tests/Security/AzureUserResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AzureUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AzureUserResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;
    private AzureUserResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(User::class);
        $this->resolver = self::getContainer()->get(AzureUserResolver::class);
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    public function testReusesRealUserByEmail(): void
    {
        $existing = new User('alice@example.com', 'Alice');
        $existing->setRoles(['ROLE_APPROVER']);
        $this->em->persist($existing);
        $this->em->flush();
        $originalId = $existing->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice@example.com', 'Alice From SSO');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertFalse($u->isPlaceholder());
    }

    public function testPromotesPlaceholderMatchingOnEmail(): void
    {
        $ph = new User('alice@example.com', 'Alice');
        $ph->setPlaceholder(true);
        $this->em->persist($ph);
        $this->em->flush();
        $originalId = $ph->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice@example.com', 'Alice');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertFalse($u->isPlaceholder());
    }

    public function testPromotesPlaceholderByExactNameWhenEmailDoesNotMatch(): void
    {
        $ph = new User('alice-slug@imported.local', 'Alice Wonderland');
        $ph->setPlaceholder(true);
        $this->em->persist($ph);
        $this->em->flush();
        $originalId = $ph->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice.w@corp.example.com', 'Alice Wonderland');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertSame('alice.w@corp.example.com', $u->getEmail());
        self::assertFalse($u->isPlaceholder());
    }

    public function testCreatesNewUserWhenNoMatches(): void
    {
        $u = $this->resolver->resolve('brand.new@corp.example.com', 'Brand New');
        self::assertNotNull($u->getId());
        self::assertSame('Brand New', $u->getFullName());
        self::assertContains('ROLE_SUBMITTER', $u->getRoles());
        self::assertFalse($u->isPlaceholder());
        self::assertNull($u->getPassword());
    }

    public function testCreatesNewUserWhenMultiplePlaceholdersShareName(): void
    {
        $a = new User('one@imported.local', 'John Doe');
        $a->setPlaceholder(true);
        $b = new User('two@imported.local', 'John Doe');
        $b->setPlaceholder(true);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $u = $this->resolver->resolve('john.doe@corp.example.com', 'John Doe');

        self::assertNotSame($a->getId()->toRfc4122(), $u->getId()->toRfc4122());
        self::assertNotSame($b->getId()->toRfc4122(), $u->getId()->toRfc4122());
    }
}
```

- [ ] **Step 2: Run test — expect failure (class missing)**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Security/AzureUserResolverTest.php --testdox`
Expected: FAIL — service not wired.

- [ ] **Step 3: Implement `AzureUserResolver`**

Create `src/Security/AzureUserResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AzureUserResolver
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolve(string $email, string $displayName): User
    {
        $existing = $this->users->findOneByEmail($email);
        if ($existing !== null) {
            if ($existing->isPlaceholder()) {
                $existing->setPlaceholder(false);
                $this->em->flush();
            }
            return $existing;
        }

        $placeholderMatches = $this->users->createQueryBuilder('u')
            ->where('u.placeholder = true')
            ->andWhere('u.fullName = :n')
            ->setParameter('n', $displayName)
            ->getQuery()
            ->getResult();

        if (count($placeholderMatches) === 1) {
            /** @var User $match */
            $match = $placeholderMatches[0];
            $match->setEmail($email);
            $match->setPlaceholder(false);
            $this->em->flush();
            return $match;
        }

        $user = new User($email, $displayName);
        $user->setRoles(['ROLE_SUBMITTER']);
        $user->setPlaceholder(false);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
```

- [ ] **Step 4: Run unit tests — all pass**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Security/AzureUserResolverTest.php --testdox`
Expected: 5 passing.

- [ ] **Step 5: Create `AzureAuthController`**

Create `src/Controller/AzureAuthController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SsoStatusProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AzureAuthController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $registry,
        private readonly SsoStatusProvider $sso,
    ) {
    }

    #[Route('/login/azure', name: 'app_azure_connect', methods: ['GET'])]
    public function connect(): Response
    {
        if (!$this->sso->isEnabled()) {
            throw $this->createNotFoundException();
        }
        return $this->registry->getClient('azure')->redirect(['openid', 'profile', 'email', 'User.Read']);
    }

    #[Route('/login/azure/check', name: 'app_azure_check', methods: ['GET'])]
    public function check(): Response
    {
        if (!$this->sso->isEnabled()) {
            throw $this->createNotFoundException();
        }
        // Real response is produced by the authenticator via onAuthenticationSuccess redirect.
        return new Response('', 204);
    }
}
```

- [ ] **Step 6: Create `AzureAuthenticator`**

Create `src/Security/AzureAuthenticator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\SsoStatusProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class AzureAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ClientRegistry $registry,
        private readonly AzureUserResolver $resolver,
        private readonly UrlGeneratorInterface $router,
        private readonly SsoStatusProvider $sso,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->sso->isEnabled() && $request->attributes->get('_route') === 'app_azure_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->registry->getClient('azure');
        $token = $client->getAccessToken();
        /** @var \TheNetworg\OAuth2\Client\Provider\AzureResourceOwner $owner */
        $owner = $client->fetchUserFromToken($token);
        $raw = $owner->toArray();

        $email = $raw['mail'] ?? $raw['userPrincipalName'] ?? $raw['email'] ?? null;
        $name = $raw['displayName'] ?? $raw['name'] ?? $email;
        if (!is_string($email) || $email === '') {
            throw new AuthenticationException('Azure did not return a usable email claim.');
        }

        $user = $this->resolver->resolve($email, is_string($name) ? $name : $email);
        $this->logger->info('SSO login', ['email' => $email, 'userId' => $user->getId()->toRfc4122()]);

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier()));
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?Response
    {
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('SSO failure', ['message' => $exception->getMessage()]);
        return new RedirectResponse($this->router->generate('app_login') . '?sso_error=1');
    }
}
```

- [ ] **Step 7: Run full suite**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: all pass (the authenticator isn't wired into the firewall yet, but its classes compile and the resolver tests pass).

- [ ] **Step 8: Commit**

```bash
git add src/Controller/AzureAuthController.php src/Security/AzureAuthenticator.php src/Security/AzureUserResolver.php tests/Security/AzureUserResolverTest.php
git commit -m "feat(sso): azure auth controller, authenticator, and user resolver"
```

---

## Task 13: Wire authenticator into security.yaml + login page button + status banner + integration tests

**Files:**
- Modify: `config/packages/security.yaml`
- Modify: `templates/security/login.html.twig`
- Modify: `templates/admin/user/index.html.twig`
- Modify: `src/Controller/Admin/UserController.php` (pass SSO status to list template)
- Modify: `tests/Controller/Admin/UserControllerTest.php`
- Create: `tests/Controller/AzureAuthControllerTest.php`

- [ ] **Step 1: Add failing tests for SSO route 404 when disabled + login page button visibility**

Create `tests/Controller/AzureAuthControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AzureAuthControllerTest extends WebTestCase
{
    public function testConnectReturns404WhenSsoDisabled(): void
    {
        $client = static::createClient();
        // Test env has SSO_ENABLED unset/false by default.
        $client->request('GET', '/login/azure');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCheckReturns404WhenSsoDisabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login/azure/check');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLoginPageHidesSsoButtonWhenDisabled(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/login/azure"]');
    }
}
```

- [ ] **Step 2: Run — expect failures (no routes yet in firewall; login template doesn't render anything about SSO but the test still passes the negative check; run to confirm baseline)**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit tests/Controller/AzureAuthControllerTest.php --testdox`
Expected: the 404 tests will PASS if the controller throws when SSO is off (which it does — `SSO_ENABLED` defaults to false in `.env` and the test environment). If `testLoginPageHidesSsoButtonWhenDisabled` already passes because the button is not there at all, that's fine for this step.

If tests already pass, that is the verification — move on. If any fail, continue to Step 3.

- [ ] **Step 3: Wire `AzureAuthenticator` into `security.yaml`**

Modify `config/packages/security.yaml`. Replace the `main:` firewall block:

```yaml
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
                default_target_path: app_dashboard
            custom_authenticators:
                - App\Security\AzureAuthenticator
            logout:
                path: app_logout
                target: app_login
```

Also add `/login/azure` to access_control so unauthenticated users can hit it:

Under `access_control:` replace with:

```yaml
    access_control:
        - { path: ^/login/azure, roles: PUBLIC_ACCESS }
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/, roles: ROLE_SUBMITTER }
```

- [ ] **Step 4: Add SSO button to login page**

Modify `templates/security/login.html.twig`. Locate the end of the password form. Inside the same container add a small separator + SSO button, conditional on `sso_enabled` (we'll inject this as a Twig variable).

Open `src/Controller/SecurityController.php` and update the `login` action to pass the flag:

Replace the `login` method:

```php
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $utils, \App\Service\SsoStatusProvider $sso): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'sso_enabled' => $sso->isEnabled(),
        ]);
    }
```

In `templates/security/login.html.twig`, inside the existing login form container, append (before `{% endblock %}`):

```twig
{% if sso_enabled %}
  <div class="text-center my-3 text-muted">— or —</div>
  <a class="btn btn-outline-primary w-100" href="{{ path('app_azure_connect') }}">
    <i class="bi bi-microsoft"></i> Sign in with Microsoft
  </a>
{% endif %}
```

Exact placement: append inside the `{% block body %}` block, after whatever card/form wrapper already contains the password form. If there is a card structure, place it after `</form>` but before `</div>` of the card body.

- [ ] **Step 5: Pass SSO status to admin user list and render banner**

Modify `src/Controller/Admin/UserController.php`. Extend constructor with `SsoStatusProvider`:

```php
use App\Service\SsoStatusProvider;
```

```php
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
        private readonly SsoStatusProvider $sso,
    ) {
    }
```

Update the `index` action to pass status:

```php
        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'filters' => $filters,
            'sso' => [
                'status' => $this->sso->statusCode(),
                'tenant_suffix' => $this->sso->tenantSuffix(),
            ],
        ]);
```

Modify `templates/admin/user/index.html.twig`. Below the `<h1>` row (inside the outer container, before the filter form), insert:

```twig
  <div class="alert alert-{{ sso.status == 'enabled' ? 'success' : (sso.status == 'misconfigured' ? 'danger' : 'secondary') }} py-2">
    <strong>SSO:</strong>
    {% if sso.status == 'enabled' %}
      enabled (tenant <code>…{{ sso.tenant_suffix }}</code>)
    {% elseif sso.status == 'misconfigured' %}
      misconfigured — check environment (AZURE_TENANT_ID / AZURE_CLIENT_ID / AZURE_CLIENT_SECRET)
    {% else %}
      not configured
    {% endif %}
  </div>
```

- [ ] **Step 6: Add test that the admin list renders the SSO banner**

Append to `tests/Controller/Admin/UserControllerTest.php`:

```php
    public function testIndexShowsSsoStatusBanner(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'SSO');
    }
```

- [ ] **Step 7: Clear cache + run full suite**

Run:
```bash
docker compose exec -T -e APP_ENV=test app php bin/console cache:clear
docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox
```
Expected: every test passes.

- [ ] **Step 8: Smoke-test the dev UI**

Run: `docker compose up -d` then browse to `http://localhost:8180/login` — confirm the page renders without the SSO button (default `SSO_ENABLED=false`).

Set `SSO_ENABLED=true` in `.env.local` (don't commit), restart `docker compose up -d app`, visit `/login` again — the button must appear. Restore `.env.local` afterward (or delete it).

- [ ] **Step 9: Commit**

```bash
git add config/packages/security.yaml src/Controller/SecurityController.php src/Controller/Admin/UserController.php templates/security/login.html.twig templates/admin/user/index.html.twig tests/Controller/Admin/UserControllerTest.php tests/Controller/AzureAuthControllerTest.php
git commit -m "feat(sso): wire azure authenticator, login button, and admin status banner"
```

---

## Task 14: Final regression + documentation updates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md` (if present; optional)

- [ ] **Step 1: Run full test suite as final check**

Run: `docker compose exec -T -e APP_ENV=test app php bin/phpunit --testdox`
Expected: 100% green.

- [ ] **Step 2: Update `CLAUDE.md` with new admin/SSO context**

Modify `CLAUDE.md`. Under the "Common commands" section, append a new subsection:

```markdown
### User management & SSO

Admin UI lives at `/admin/users` (requires `ROLE_ADMIN`). It exposes list/search, create, edit, password reset, and placeholder promotion.

Entra ID SSO is additive — the password form keeps working. Configure via `.env.local`:

```
SSO_ENABLED=true
AZURE_TENANT_ID=...
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
AZURE_REDIRECT_URI=http://localhost:8180/login/azure/check
```

On first SSO login, unknown users are auto-created as `ROLE_SUBMITTER` with no local password. If an existing placeholder (CSV-imported) matches by `fullName` exactly (and there is only one match), it is promoted in place, preserving all decision references.

Self-protection: admins cannot change their own roles from `/admin/users/{id}/edit` — the field is hidden and server-side-rejected.
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document admin user UI and Entra ID SSO setup"
```

---

## Acceptance checklist (spec coverage)

At the end, these spec requirements are covered:

- [x] `/admin/users` list + filters (Task 3, 4)
- [x] Create real user with password and roles (Task 5)
- [x] Edit user (name, email, roles) with `UniqueEntity` email check (Task 2, 7)
- [x] Admin cannot change own role (Task 7)
- [x] Admin password reset, with placeholder redirect (Task 8)
- [x] Promote placeholder preserving UUID (Task 9)
- [x] `knpuniversity/oauth2-client-bundle` + `thenetworg/oauth2-azure` installed (Task 10)
- [x] SSO env vars + `SsoStatusProvider` (Task 11)
- [x] `AzureAuthController`, `AzureAuthenticator`, user resolution with placeholder promote + role defaults (Task 12)
- [x] Login page button + admin status banner + 404-when-disabled (Task 13)
- [x] Tests for all flows (Tasks 2–13)
- [x] Docs update (Task 14)
