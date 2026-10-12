---
name: tdd
description: Start a TDD cycle for a new Inertia bundle feature or bug fix. Write the test first, then implement.
argument-hint: <feature-or-bug-description>
allowed-tools: Bash(vendor/bin/phpunit:*), Bash(find:*), Bash(cat:*), Read, Write, Edit
---

# TDD Cycle — symfony-inertia-bundle

## Task
Implement **$ARGUMENTS** using strict Test-Driven Development.

## Rules
- NEVER write production code before a failing test exists
- Tests live in `tests/Unit/` or `tests/Functional/Protocol/`
- Use `declare(strict_types=1)` in every test file
- Test naming: `test<Behavior><Condition><ExpectedResult>` (camelCase, no underscores — enforced by php-cs-fixer `php_unit_method_casing`)

## Steps

### 1. Understand the requirement
Read the relevant section of `AGENTS.md` and `.claude/skills/inertia-protocol/SKILL.md`.
Identify which protocol behavior needs to be implemented.

Check how inertia-laravel tests the same behavior — it is the reference implementation:
```bash
find /home/tony/Documents/tony/inertia-laravel/tests -name "*.php" | xargs grep -l "<keyword>" 2>/dev/null
```
Read the relevant test(s) to understand what cases to cover and how assertions are structured.
Align test naming and coverage with the Laravel reference before writing anything.

### 2. Write the failing test
Create the test file. Run it and confirm it FAILS:
```bash
vendor/bin/phpunit tests/path/to/TheTest.php --testdox
```
Show the failure output. If it fails with a PHP error (missing class, etc.) that's fine for now.

### 3. Implement minimally
Write the smallest possible production code that makes the test pass.
Run again — must be GREEN.

### 4. No regressions
```bash
vendor/bin/phpunit --testdox
```
All tests must pass.

### 5. Refactor
Clean up, apply conventions from `AGENTS.md`. Run tests again after each change.

### 6. Static analysis
```bash
vendor/bin/phpstan analyse src/ tests/ --level=8 --no-progress
```

### 7. Code style
```bash
vendor/bin/php-cs-fixer fix --dry-run --diff src/ tests/
```