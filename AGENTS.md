# AGENTS.md — symfony-inertia-bundle

> **Ce fichier est la source de vérité pour tous les agents IA travaillant sur ce projet.**
> Il définit les conventions, contraintes et outils à respecter **sans exception**.

---

## Contexte du projet

Ce projet est un **Symfony Bundle** qui implémente le protocole Inertia.js v2 (avec chemin de migration v3)
côté serveur, l'équivalent de `inertiajs/inertia-laravel` mais pour Symfony.

- **Type** : Symfony Bundle réutilisable (pas une application)
- **Inertia.js** : v2 en priorité, architecture extensible vers v3
- **Symfony** : 6.4 LTS minimum → 7.4 LTS → 8.0
- **PHP** : ^8.1
- **Pattern** : `AbstractBundle` (Symfony 6.1+), **jamais** `Bundle` + `Extension` séparée
- **Packagist** : `nytodev/inertia-bundle` (type `symfony-bundle`)

---

## Structure du projet

```
symfony-inertia-bundle/
├── AGENTS.md               ← ce fichier
├── composer.json
├── LICENSE
├── README.md
├── config/
│   ├── definition.php      ← schéma de configuration (DefinitionConfigurator)
│   └── services.yaml       ← définitions de services explicites (pas d'autowire)
├── docs/
│   └── index.md
├── src/
│   ├── InertiaBundle.php   ← AbstractBundle : configure() + loadExtension()
│   ├── Service/
│   │   └── Inertia.php     ← service principal : render(), share(), defer(), once()
│   ├── Response/
│   │   └── InertiaResponse.php
│   ├── EventListener/
│   │   └── InertiaListener.php  ← kernel.request + kernel.response
│   ├── Props/
│   │   ├── OptionalProp.php
│   │   ├── DeferProp.php
│   │   ├── OnceProp.php
│   │   └── MergeProp.php
│   ├── Twig/
│   │   └── InertiaTwigExtension.php
│   └── Controller/
│       └── AbstractInertiaController.php
└── tests/
    ├── Functional/
    └── Unit/
```

---

## Conventions PHP — OBLIGATOIRES

### Style de code
- **PHP 8.1+ uniquement** — utiliser les features modernes : `readonly`, `enum`, `fibers` si pertinent
- **Strict typing** : `declare(strict_types=1)` dans **chaque** fichier PHP
- **Types complets** sur toutes les méthodes (paramètres + retour), jamais `mixed` sans raison
- **Constructor promotion** pour la DI : `public function __construct(private readonly Foo $foo)`
- **`readonly`** sur toutes les propriétés qui ne mutent pas
- **`final`** sur toutes les classes sauf si l'extension est intentionnellement supportée
- **Comparaisons strictes** : toujours `===` et `!==`, jamais `==` ou `!=`
- **Yoda conditions** : `if (null === $value)`, pas `if ($value === null)`
- **`match`** plutôt que `switch` pour les expressions
- Nommage **camelCase** pour variables et méthodes, **PascalCase** pour classes

### Organisation des fichiers
- 200–400 lignes par fichier, **800 max**
- Une classe par fichier, toujours
- Pas de logique dans les constructeurs (sauf assignation de propriétés)
- Les exceptions dans un sous-namespace `Exception/`

### Interdit
- `var_dump()`, `print_r()`, `dd()` dans le code de production
- `@` pour supprimer les erreurs PHP
- Closures récursives non documentées
- Magic strings non-constantes répétées (utiliser des constantes de classe)

---

## Conventions Symfony — SPÉCIFIQUES AU BUNDLE

### DI et services
- **`AbstractBundle`** obligatoire — jamais `Bundle` + `Extension` séparée dans `DependencyInjection/`
- `configure()` → schéma importé depuis `config/definition.php`
- `loadExtension()` → charge `config/services.yaml` + injecte la config dans les services
- Services **préfixés** `inertia.*` (alias DI du bundle = `inertia`)
- Tous les services **`public: false`** par défaut
- Alias publics pour l'autowiring : `Nytodev\InertiaBundle\Service\Inertia: { alias: inertia.service, public: true }`
- **Zéro autowiring, zéro autoconfigure** dans les définitions de services du bundle
- Chemins **physiques** `__DIR__` dans `loadExtension()`, jamais de chemins logiques `@Bundle`

### Event Listeners
- Nommage : suffixe `Listener` (ex: `InertiaListener`), jamais `Subscriber` dans les classes
- Tag explicite : `{ name: kernel.event_subscriber }` dans services.yaml
- Listener unique pour `kernel.request` + `kernel.response`

### Configuration du bundle (config/packages/inertia.yaml)
```yaml
inertia:
    root_view: 'base.html.twig'   # template Twig racine
    version: null                  # version des assets (null = désactivé)
    ssr_enabled: false             # SSR via serveur Node.js
    ssr_url: 'http://127.0.0.1:13714'
```

---

## Protocole Inertia.js v2 — Ce que le bundle doit implémenter

### Page Object v2 (structure exacte)
```json
{
    "component": "User/Edit",
    "props": { "errors": {}, "user": {} },
    "url": "/user/123",
    "version": "abc123",
    "clearHistory": false,
    "encryptHistory": false,
    "deferredProps": {},
    "mergeProps": [],
    "prependProps": [],
    "deepMergeProps": [],
    "matchPropsOn": [],
    "onceProps": {},
    "scrollProps": {}
}
```

### Comportements critiques du protocole
1. **Première requête** → HTML avec `<div id="app" data-page='...'></div>`
2. **Requête XHR** (header `X-Inertia: true`) → JSON page object + header `X-Inertia: true` + `Vary: X-Inertia`
3. **Version mismatch** → `409 Conflict` + header `X-Inertia-Location: <url>` (GET uniquement)
4. **302 → 303** : convertir automatiquement après PUT/PATCH/DELETE
5. **Partial reload** : `X-Inertia-Partial-Data` (inclusion) ou `X-Inertia-Partial-Except` (exclusion), `errors` toujours inclus
6. **OptionalProp** (`optional()`) : closures résolues uniquement si la prop est demandée
7. **DeferProp** : exclu du premier rendu, chargé par le client en XHR séparé

### Headers de requête à détecter
| Header | Action |
|--------|--------|
| `X-Inertia: true` | Mode Inertia XHR |
| `X-Inertia-Version` | Comparer avec version serveur |
| `X-Inertia-Partial-Component` | Nom du composant pour partial reload |
| `X-Inertia-Partial-Data` | Props à inclure (CSV) |
| `X-Inertia-Partial-Except` | Props à exclure (CSV) |
| `X-Inertia-Except-Once-Props` | OnceProp déjà chargés côté client |

---

## Tests — Standards obligatoires

### Outils
- **PHPUnit 10.5+ ou 11+** pour tous les tests
- **symfony/test-pack** pour les tests fonctionnels
- **symfony/browser-kit** pour simuler les requêtes HTTP

### Couverture exigée
- **95% minimum** sur `src/`
- Chaque feature du protocole Inertia doit avoir un test dédié

### Comment lancer les tests
```bash
vendor/bin/phpunit                                    # tous les tests
vendor/bin/phpunit tests/Unit/                        # tests unitaires
vendor/bin/phpunit tests/Functional/                  # tests fonctionnels
vendor/bin/phpunit --coverage-text                    # avec couverture
```

### Structure des tests
```
tests/
├── Unit/
│   ├── Service/InertiaTest.php
│   ├── Response/InertiaResponseTest.php
│   └── Props/OptionalPropTest.php
└── Functional/
    ├── Protocol/
    │   ├── FirstVisitTest.php       ← HTML + data-page
    │   ├── XhrVisitTest.php         ← JSON response + headers
    │   ├── AssetVersionTest.php     ← 409 Conflict
    │   ├── PartialReloadTest.php    ← X-Inertia-Partial-*
    │   ├── RedirectTest.php         ← 302→303 conversion
    │   └── DeferredPropsTest.php
    └── Bundle/
        └── ConfigurationTest.php
```

### Règles de test
- Un test = un comportement précis du protocole
- Nommer les tests : `test<Comportement><Condition><Résultat>` en camelCase sans underscore (ex: `testXhrVisitWithVersionMismatchReturns409`) — imposé par php-cs-fixer (`php_unit_method_casing`)
- Utiliser `WebTestCase` pour les tests fonctionnels
- Toujours tester les deux chemins : Inertia XHR ET requête HTML normale
- Vérifier les headers HTTP précisément avec `assertResponseHasHeader()`

---

## Qualité du code — Outils et commandes

### Commandes de vérification (à lancer après chaque modification)
```bash
# 1. Style de code (PHP CS Fixer)
vendor/bin/php-cs-fixer fix --dry-run --diff src/ tests/

# 2. Analyse statique (PHPStan - niveau 8 minimum)
vendor/bin/phpstan analyse src/ tests/ --level=8

# 3. Tests complets
vendor/bin/phpunit

# 4. Vérification du container DI (dans une app de test)
php bin/console lint:container
php bin/console debug:container inertia

# 5. Validation YAML des services
php bin/console lint:yaml config/services.yaml
```

### PHP CS Fixer (.php-cs-fixer.php)
- PSR-12 + Symfony style
- `declare(strict_types=1)` automatique
- Import des classes (pas de FQN inline)
- `final` sur toutes les classes

### PHPStan
- Niveau **8** minimum pour `src/`
- Niveau **6** acceptable pour `tests/`
- Pas d'`@phpstan-ignore` sans commentaire explicatif

---

## Git — Workflow

### Format des commits (Conventional Commits)
```
feat(protocol): implement partial reload support
fix(listener): correct 302→303 redirect conversion after DELETE
test(functional): add asset versioning 409 conflict test
docs(readme): add installation instructions for Symfony Flex
refactor(response): extract page object builder to dedicated class
chore(ci): add Symfony 8.0 to test matrix
```

### Branches
- `main` → stable, toujours verte en CI
- `feat/<name>` → nouvelles features
- `fix/<name>` → corrections de bugs
- `docs/<name>` → documentation uniquement

### PR checklist
- [ ] Tests passent (`vendor/bin/phpunit`)
- [ ] PHPStan niveau 8 sans erreurs (`vendor/bin/phpstan analyse`)
- [ ] CS Fixer sans diff (`vendor/bin/php-cs-fixer fix --dry-run`)
- [ ] CHANGELOG.md mis à jour
- [ ] Pas de `var_dump` ou `dd()` oublié

---

## Sécurité

- **Jamais** de secrets (clés API, mots de passe) dans le code ou les tests
- Utiliser `%env(VAR)%` pour toute valeur sensible
- Les headers HTTP doivent être escapés avant sortie
- Le JSON du `data-page` doit être encodé avec `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT` pour éviter les XSS
- Si une vulnérabilité est détectée : STOP → corriger → tester → PR séparée

---

## Ce que l'agent NE doit PAS faire

- ❌ Créer `src/DependencyInjection/InertiaExtension.php` (ancienne approche, non supportée)
- ❌ Utiliser `autowire: true` ou `autoconfigure: true` dans `config/services.yaml`
- ❌ Utiliser des chemins logiques `@InertiaBundle/config/...`
- ❌ Modifier `composer.lock` directement
- ❌ Ajouter des dépendances non listées dans `require` sans discussion
- ❌ Utiliser `Bundle` au lieu de `AbstractBundle`
- ❌ Hardcoder des valeurs de configuration dans les classes PHP (toujours passer par DI)
- ❌ Créer des fichiers > 800 lignes
- ❌ Utiliser `public: true` sur des services internes
- ❌ Sortir les variables de `$_ENV` ou `$_SERVER` directement (utiliser `getenv()` ou le composant Config)

---

## Inertia v2 → v3 : Points de migration anticipée

Pour faciliter la migration future vers v3, marquer avec `// TODO: Inertia v3` :
- Le `data-page` attr (v2) → `<script type="application/json">` (v3)
- `clearHistory: false` toujours présent en v2 → omis si `false` en v3
- `encryptHistory: false` idem
- `sharedProps` array → absent en v2, présent en v3
- `preserveFragment` → absent en v2, présent en v3
- `X-Inertia-Redirect` header → absent en v2, présent en v3

---

## Agents disponibles dans `.claude/agents/`

| Agent | Quand l'utiliser |
|-------|-----------------|
| `protocol-validator` | Vérifier la conformité d'une réponse au protocole Inertia v2 |
| `bundle-reviewer` | Review des conventions Symfony Bundle (AbstractBundle, services, etc.) |
| `php-tdd` | Cycle TDD : test rouge → implémentation → test vert → refactor |
| `ci-matrix` | Vérifier/générer la matrice GitHub Actions PHP × Symfony |

---

*Dernière mise à jour : 2025 — symfony-inertia-bundle*
