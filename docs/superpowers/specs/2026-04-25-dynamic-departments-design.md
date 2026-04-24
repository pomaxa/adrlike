# Dynamic Departments Design

**Date:** 2026-04-25
**Scope:** Replace the hardcoded `Department` PHP enum with a DB-backed `Department` entity; add admin CRUD; propagate to all existing consumers.

---

## Goal

Admins can create, rename, and delete departments through the UI. Departments are stored in a `departments` table and referenced by FK from both `decisions` and `users`.

---

## Data model

### New entity — `Department`

```php
// src/Entity/Department.php
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'departments')]
#[UniqueEntity(fields: ['name'])]
class Department {
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;               // UUID v7, set in constructor

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    private string $name;

    public function __construct(string $name) { ... }
    public function getId(): Uuid
    public function getName(): string
    public function setName(string $name): void
    public function label(): string { return $this->name; }   // backward-compat with templates
    public function __toString(): string { return $this->name; }
}
```

### `DepartmentRepository`

Methods needed:
- `findAllOrderedByName(): array` — all departments, `ORDER BY name ASC`
- `findOneByName(string $name): ?Department` — case-sensitive lookup for CSV importer
- `countDecisionReferences(Department $d): int` — `COUNT(decisions where department = d)`
- `countUserReferences(Department $d): int` — `COUNT(users where department = d)`

### Changes to `Decision`

```php
// Before:
#[ORM\Column(type: Types::STRING, length: 16, enumType: Department::class)]
private Department $department;

// After:
#[ORM\ManyToOne(targetEntity: Department::class)]
#[ORM\JoinColumn(nullable: false)]
private Department $department;
```

The `Decision` constructor gains a required `Department $department` parameter (replacing the `Department::Risk` default). All call sites (factory methods, tests, importer) must pass a `Department` entity explicitly. `DecisionType` form `required: true` so the form always provides a value.

### Changes to `User`

```php
// Before:
#[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: Department::class)]
private ?Department $department = null;

// After:
#[ORM\ManyToOne(targetEntity: Department::class)]
#[ORM\JoinColumn(nullable: true)]
private ?Department $department = null;
```

---

## Migration

One migration performs the full transition. Steps in `up()`:

```sql
-- 1. Create departments table
CREATE TABLE departments (
    id UUID NOT NULL PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    CONSTRAINT departments_name_unique UNIQUE (name)
);

-- 2. Seed the three existing departments (fixed UUIDs)
INSERT INTO departments (id, name) VALUES
    (gen_random_uuid(), 'Risk'),
    (gen_random_uuid(), 'Manual'),
    (gen_random_uuid(), 'Other');

-- 3. Add department_id FK columns (nullable while we migrate data)
ALTER TABLE decisions ADD COLUMN department_id UUID NULL;
ALTER TABLE users     ADD COLUMN department_id UUID NULL;

-- 4. Backfill decisions
UPDATE decisions SET department_id = (
    SELECT id FROM departments WHERE name = decisions.department
);

-- 5. Backfill users
UPDATE users SET department_id = (
    SELECT id FROM departments WHERE name = users.department
);

-- 6. Enforce NOT NULL on decisions (all rows should now have a value)
ALTER TABLE decisions ALTER COLUMN department_id SET NOT NULL;

-- 7. Add FK constraints
ALTER TABLE decisions ADD CONSTRAINT fk_decisions_department
    FOREIGN KEY (department_id) REFERENCES departments(id);
ALTER TABLE users ADD CONSTRAINT fk_users_department
    FOREIGN KEY (department_id) REFERENCES departments(id);

-- 8. Drop old VARCHAR columns
ALTER TABLE decisions DROP COLUMN department;
ALTER TABLE users     DROP COLUMN department;
```

`down()` reverses: re-adds VARCHAR columns, backfills from `departments.name`, drops FK columns and the `departments` table.

---

## Admin CRUD

**Controller:** `src/Controller/Admin/DepartmentController.php`
Route prefix: `/admin/departments`, annotation `#[IsGranted('ROLE_ADMIN')]`.

| Route | Method | Action |
|-------|--------|--------|
| `/admin/departments` | GET | List all with decision+user counts |
| `/admin/departments/new` | GET/POST | Create |
| `/admin/departments/{id}/edit` | GET/POST | Rename |
| `/admin/departments/{id}/delete` | POST | Delete (safety-checked, CSRF) |

**Delete safety:** If `countDecisionReferences + countUserReferences > 0`, flash `"Cannot delete — %d decisions and %d users use this department."` and redirect to list. Otherwise delete and redirect.

**Form:** `src/Form/DepartmentType.php` — single `name` field, `TextType`, `NotBlank` constraint.

**Templates:**
- `templates/admin/department/index.html.twig` — table: Name | Decisions | Users | Actions. Styled consistently with the user admin list.
- `templates/admin/department/new.html.twig` — card with name field + submit.
- `templates/admin/department/edit.html.twig` — same card, pre-filled.

---

## Existing code changes

### Form types

All three swap `EnumType` for `EntityType`:

```php
// DecisionType — required, no placeholder
->add('department', EntityType::class, [
    'class'         => Department::class,
    'choice_label'  => 'name',
    'query_builder' => fn(DepartmentRepository $r) => $r->createQueryBuilder('d')->orderBy('d.name'),
])

// UserCreateType and UserEditType — optional
->add('department', EntityType::class, [
    'class'         => Department::class,
    'choice_label'  => 'name',
    'required'      => false,
    'placeholder'   => '— select —',
    'query_builder' => fn(DepartmentRepository $r) => $r->createQueryBuilder('d')->orderBy('d.name'),
])
```

### `CsvImporter`

- Inject `DepartmentRepository` as a constructor dependency (add `__construct`).
- Change `resolveDepartment` from `private static` to `private`.
- New logic: look up by name (case-insensitive), fall back to the "Other" row.

```php
private function resolveDepartment(string $raw): Department
{
    $name = ucfirst(strtolower(trim($raw)));
    return $this->departments->findOneByName($name)
        ?? $this->departments->findOneByName('Other')
        ?? throw new \RuntimeException('Seed department "Other" not found');
}
```

`findOneByName` does a case-sensitive lookup by the canonical name (capitalized). The importer normalises with `ucfirst(strtolower(...))` to match `Risk`, `Manual`, `Other` reliably.

### `DashboardController`

Inject `DepartmentRepository`. Replace the `byDepartment` query and `normalizeCounts` call:

```php
// Query using JOIN to get entity + count
$byDepartment = $this->decisions->createQueryBuilder('d')
    ->select('dept.id AS deptId, dept.name AS deptName, COUNT(d.id) AS cnt')
    ->join('d.department', 'dept')
    ->groupBy('dept.id')
    ->getQuery()
    ->getArrayResult();

// New private helper (replaces normalizeCounts for departments)
'by_department' => $this->normalizeDepartmentCounts($byDepartment),
```

`normalizeDepartmentCounts` returns `list<array{label: string, count: int}>` — simpler than the enum-based version since we don't need to pad with zeros for unseen departments (empty departments just don't appear).

Remove `use App\Enum\Department` import.

### `DecisionRepository::queryByFilters`

The `department` filter criterion changes from matching a string column to matching by entity UUID:

```php
if (!empty($criteria['department'])) {
    $qb->andWhere('d.department = :dept')
       ->setParameter('dept', $criteria['department']); // UUID string, Doctrine resolves to entity
}
```

Doctrine resolves a UUID string to the FK entity automatically when the parameter matches `department` (ManyToOne).

### `DecisionController::index`

Inject `DepartmentRepository`. Pass departments to template:

```php
'departments' => $this->departments->findAllOrderedByName(),
```

### `templates/decision/index.html.twig`

Replace the enum-based filter dropdown:

```twig
{# Before #}
{% for d in enum('App\\Enum\\Department').cases %}
    <option value="{{ d.value }}" {{ filters.department == d.value ? 'selected' }}>{{ d.label }}</option>
{% endfor %}

{# After #}
{% for d in departments %}
    <option value="{{ d.id }}" {{ filters.department == d.id.toRfc4122() ? 'selected' }}>{{ d.name }}</option>
{% endfor %}
```

### `DecisionAuditSubscriber`

No changes needed. `stringify()` already handles objects with `__toString()` (line 108–109). The `Department` entity's `__toString()` returns `$this->name`, so audit history entries will show the department name.

### `templates/base.html.twig`

Add "Departments" nav link for admins, between "Users" and "Import CSV":

```twig
<li class="nav-item">
    <a class="nav-link" href="{{ path('app_admin_department_index') }}">
        <i class="bi bi-diagram-3"></i> Departments
    </a>
</li>
```

### `src/Enum/Department.php`

Deleted once all references are removed.

---

## What does NOT change

- `DecisionAuditSubscriber` — `__toString()` on the entity handles it.
- All templates that call `department.label` or `department.label()` — the entity's `label()` method returns `$this->name`, so these keep working.
- `templates/decision/show.html.twig`, `templates/dashboard/index.html.twig`, `templates/admin/user/show.html.twig` — no changes.
- `Decision` constructor default: removed; `DecisionType` is `required: true` so the form always provides a value. For programmatic creation (tests, importer), callers must pass a `Department` entity explicitly.

---

## Files changed

| File | Action |
|------|--------|
| `src/Enum/Department.php` | **Delete** |
| `src/Entity/Department.php` | Create |
| `src/Repository/DepartmentRepository.php` | Create |
| `src/Form/DepartmentType.php` | Create |
| `src/Controller/Admin/DepartmentController.php` | Create |
| `templates/admin/department/index.html.twig` | Create |
| `templates/admin/department/new.html.twig` | Create |
| `templates/admin/department/edit.html.twig` | Create |
| `migrations/VersionXXX.php` | Create (data migration) |
| `src/Entity/Decision.php` | Modify |
| `src/Entity/User.php` | Modify |
| `src/Form/DecisionType.php` | Modify |
| `src/Form/UserCreateType.php` | Modify |
| `src/Form/UserEditType.php` | Modify |
| `src/Service/CsvImporter.php` | Modify |
| `src/Controller/DashboardController.php` | Modify |
| `src/Controller/DecisionController.php` | Modify |
| `src/Repository/DecisionRepository.php` | Modify |
| `templates/base.html.twig` | Modify |
| `templates/decision/index.html.twig` | Modify |
| `tests/Controller/Admin/DepartmentControllerTest.php` | Create |
| `tests/Controller/Admin/UserControllerTest.php` | Modify (makeUser needs Department entity) |
| `tests/Controller/DecisionControllerTest.php` | Modify (Decision constructor, dept filter test) |
