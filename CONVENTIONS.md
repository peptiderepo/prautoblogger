# PRAutoBlogger — Conventions

This document codifies the naming patterns, coding conventions, and step-by-step guides for extending PRAutoBlogger. Update this file whenever a new pattern is introduced.

---

## Naming Conventions

### PHP Classes
- **Format:** `PRAutoBlogger_{PascalCase}` (e.g., `PRAutoBlogger_Content_Generator`)
- **File naming:** `class-{kebab-case}.php` (e.g., `class-content-generator.php`)
- **Interfaces:** `interface-{kebab-case}.php` with `PRAutoBlogger_{Name}_Interface` class name

### Hooks (Actions & Filters)
- **Prefix:** All hooks start with `prautoblogger_`
- **Actions:** `prautoblogger_{event}` (e.g., `prautoblogger_before_content_generation`)
- **Filters:** `prautoblogger_filter_{what}` (e.g., `prautoblogger_filter_article_idea_score`)
- **Hook callbacks registered in:** `class-prautoblogger.php` (central loader), never in the classes themselves

### Database
- **Table prefix:** `{$wpdb->prefix}prautoblogger_` (e.g., `wp_prautoblogger_source_data`)
- **Option prefix:** `prautoblogger_` (e.g., `prautoblogger_openrouter_api_key`)
- **Post meta prefix:** `_prautoblogger_` (underscore prefix hides from custom fields UI)
- **Transient prefix:** `prautoblogger_` (e.g., `prautoblogger_reddit_token`)

### Constants
- **Format:** `PRAUTOBLOGGER_{UPPER_SNAKE}` (e.g., `PRAUTOBLOGGER_MAX_RETRIES`)
- **Defined in:** `prautoblogger.php` (bootstrap file) for global constants

### Hook Callback Methods
- **Action callbacks:** Prefixed with `on_` (e.g., `on_daily_generation_triggered`)
- **Filter callbacks:** Prefixed with `filter_` (e.g., `filter_content_quality`)

---

## Error Handling & Logging

### Structured Logger
All logging uses `PRAutoBlogger_Logger` (singleton) instead of raw `error_log()`. The logger writes to the `prab_event_log` custom table and forwards errors/warnings to PHP's `error_log()` for server-level monitoring. The verbosity threshold is user-configurable via Settings → Publishing → Log Level.

**Never call `error_log()` directly.** Always use the Logger:

```php
$logger = PRAutoBlogger_Logger::instance();
$logger->error( 'API call failed: ' . $error_message, 'openrouter' );
$logger->warning( 'Budget nearing limit.', 'cost-tracker' );
$logger->info( 'Pipeline complete: 3 posts generated.', 'pipeline' );
$logger->debug( 'Token counts: prompt=1200, completion=800', 'openrouter' );
```

### Log levels (ascending verbosity)
| Level   | Numeric | Use for |
|---------|---------|---------|
| error   | 0       | Failures: API errors, parse failures, DB write failures |
| warning | 1       | Degraded behavior: budget approaching, missing providers, fallback pricing |
| info    | 2       | Key events: pipeline start/stop, articles generated, metrics collected |
| debug   | 3       | Verbose detail: token counts, timing, skipped posts, intermediate values |

### Context strings
The second argument to every log call is a short context tag identifying the origin:
`pipeline`, `scheduler`, `collector`, `analyzer`, `editor`, `openrouter`, `reddit`, `ga4`, `metrics`, `cost-tracker`, `encryption`.

### What gets shown to the user
- API key issues → admin notice (persistent until resolved)
- Budget exceeded → admin notice + email to site admin
- Generation failures → logged to `prab_generation_log` with `response_status = 'error'`
- Generation failures also shown in the admin dashboard widget
- All log entries visible in PRAutoBlogger → Activity Log (filterable by level)

### What gets retried
- LLM API calls: exponential backoff, max 3 retries, then fail loudly
- Reddit API calls: exponential backoff, max 3 retries, then skip this collection cycle
- **Never retry silently.** Every retry is logged. Every final failure is surfaced.

### Error severity levels
1. **Fatal:** Plugin cannot function (missing API key, budget exhausted) → admin notice, stop all scheduled jobs
2. **Retriable:** Transient API failure → retry with backoff, log each attempt
3. **Skippable:** Single post generation failed → log, skip this post, continue with others

---

## How To: Add a New LLM Provider

1. Create `includes/providers/class-{name}-provider.php`
2. Implement `PRAutoBlogger_LLM_Provider_Interface` (see `interface-llm-provider.php`)
3. Required methods: `send_chat_completion()`, `get_available_models()`, `estimate_cost()`
4. Add provider option to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-prautoblogger.php` loader
6. Update ARCHITECTURE.md external API integrations table

### Interface contract:
```php
interface PRAutoBlogger_LLM_Provider_Interface {
    public function send_chat_completion(array $messages, string $model, array $options = []): array;
    public function get_available_models(): array;
    public function estimate_cost(string $model, int $prompt_tokens, int $completion_tokens): float;
    public function get_provider_name(): string;
}
```

---

## How To: Add a New Source Provider (Social Platform)

1. Create `includes/providers/class-{platform}-provider.php`
2. Implement `PRAutoBlogger_Source_Provider_Interface` (see `interface-source-provider.php`)
3. Required methods: `collect_data()`, `get_source_type()`, `validate_credentials()`
4. Add API credentials fields to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-prautoblogger.php` loader
6. Add the source type string to the `ab_source_data.source_type` allowed values
7. Update ARCHITECTURE.md file tree and external API integrations table

### Interface contract:
```php
interface PRAutoBlogger_Source_Provider_Interface {
    public function collect_data(array $config): array; // Returns PRAutoBlogger_Source_Data[]
    public function get_source_type(): string;           // e.g., 'reddit', 'tiktok'
    public function validate_credentials(): bool;
    public function get_rate_limit_status(): array;
}
```

---

## How To: Add a New Admin Setting

1. Define the option key in the settings array in `admin/class-admin-page.php` → `get_settings_fields()` method
2. Format: add an entry to the appropriate section array:
   ```php
   [
       'id'          => 'prautoblogger_new_setting',
       'label'       => __('Setting Label', 'prautoblogger'),
       'type'        => 'text', // text, number, select, textarea, checkbox, password
       'default'     => '',
       'description' => __('Help text shown below the field.', 'prautoblogger'),
       'sanitize'    => 'sanitize_text_field', // sanitization callback
   ]
   ```
3. The settings page renderer handles registration, rendering, and sanitization automatically from this array.
4. Access the setting anywhere with: `get_option('prautoblogger_new_setting', $default)`
5. For encrypted settings (API keys): use `PRAutoBlogger_Encryption::encrypt()` / `decrypt()`

---

## How To: Add a New Pipeline Stage

The writing pipeline is an ordered array of stages. Each stage is a class method on `Content_Generator`.

1. Add the stage method to `class-content-generator.php`: `protected function stage_{name}(PRAutoBlogger_Content_Request $request): string`
2. Register the stage in the `get_pipeline_stages()` method for the appropriate mode (single_pass or multi_step)
3. Each stage receives the accumulated context and returns its output
4. Each stage logs its cost via `Cost_Tracker::log_api_call()`
5. If a stage fails after retries, the entire pipeline fails (no partial publishes)

---

## How To: Add a New Frontend Component

Frontend components use `wp.element` (WordPress-bundled React) — no JSX, no build step.

1. Create the PHP class in `includes/frontend/class-{name}.php`:
   - Register a shortcode via `add_shortcode()` in an `on_register_shortcode()` method
   - Register a REST endpoint if the component needs async data (in `on_register_rest_route()`)
   - Enqueue JS (`wp-element` dependency) and CSS via `wp_enqueue_script/style()`
   - Pass config to JS via `wp_localize_script()`

2. Create JS in `assets/js/{name}.js`:
   - Use `wp.element.createElement` (aliased as `el`) for rendering
   - Import hooks: `var useState = wp.element.useState;`
   - Read config from `window.{localizedObjectName}`
   - Handle all states: loading, loaded, empty, error
   - Mount into the shortcode's `<div id="...">` mount point

3. Create CSS in `assets/css/{name}.css`:
   - Use Peptide Starter theme CSS custom properties with fallbacks: `var(--color-text-primary, #f9fafb)`
   - Prefix all classes with `prab-` to avoid collisions
   - Include responsive breakpoints, focus-visible states, and loading skeletons

4. Wire hooks in `class-prautoblogger.php`:
   - Instantiate the PHP class in `register_frontend_hooks()`
   - Register shortcode on `init`, REST routes on `rest_api_init`

5. Add `'frontend/'` to the autoloader's `$directories` array (already done for the first component)

6. Update ARCHITECTURE.md file tree and add a data flow section

### REST endpoint conventions:
- Namespace: `prautoblogger/v1`
- Permission callback: use a filter hook for extensibility (e.g., `prautoblogger_rest_{name}_public`)
- Sanitize all params with `sanitize_callback` in arg registration
- Return only the fields the frontend needs — no unnecessary data
- Set `Cache-Control` header for cacheable responses

---

## Code Quality Rules

1. **`declare(strict_types=1);`** in every PHP file
2. **Type declarations** on all method parameters, return types, and class properties
3. **No file exceeds 300 lines.** Split when approaching this limit
4. **Every class has a 3-question preamble docblock:** What / Who triggers / Dependencies
5. **Every public method has a docblock** with `@param`, `@return`, and side effects
6. **`@see` references** at the top of files that participate in multi-class flows
7. **All strings translatable** via `__()` / `_e()` with `prautoblogger` text domain
8. **No magic methods** without explicit justification documented in code
9. **No `echo` of raw data** — always escape with `esc_html()`, `esc_attr()`, etc.
10. **Nonce on every form/AJAX** — `wp_nonce_field()` / `check_ajax_referer()`

## Git Workflow — PR-Gated, Soft-Enforced

This repo is private on GitHub's free plan, which does not support branch protection or rulesets. The review gate is enforced at the agent layer, not server-side. Every agent and human contributor follows these rules; the `.github/workflows/main-push-audit.yml` tripwire opens an audit issue on any direct push to `main` that did not come from a merged PR.

### Rules

1. **Never push to `main` directly.** Every change lands via a pull request that the maintainer merges. No self-merging. No force-pushing to `main`.

2. **Branch naming**: `claude/<scope>-<YYYYMMDD>` for agent-authored work, `fix/<scope>` or `feat/<scope>` for human-authored. The scope is a 1–3 word kebab-case description.

3. **Commit trailer**: every commit authored by an agent must end with an `Agent-Session:` trailer so commits can be correlated back to the conversation that produced them, even though all commits share the `peptiderepo` bot identity.

   ```
   feat: add per-request cost tracking

   Agent-Session: cowork-2026-04-14-cost-audit
   ```

4. **PR description template**: every PR description covers
   - **What changed** (one paragraph)
   - **Why** (motivation or incident link)
   - **Risk flags** (schema changes, API contract changes, cost impact, compatibility)
   - **Test plan** (what was run locally, what to smoke-test after merge)

5. **Emergency push exception**: if a situation genuinely requires pushing to `main` without a PR (site down, CI broken, one-line hotfix), surface it in the chat before doing it. Every emergency push gets a follow-up PR that commits the same changes through the normal flow so git stays the source of truth. The tripwire will open an audit issue — close it with a comment explaining why.

### Opening a PR from an agent session

The `gh` CLI is not installed in the Cowork sandbox. Use `curl` with the PAT from the workspace `.env.credentials`:

```bash
GH_TOKEN=$(grep "^GITHUB_PAT=" "$WORKSPACE/.env.credentials" | cut -d= -f2)

git push -u origin HEAD

curl -s -X POST -H "Authorization: token $GH_TOKEN" \
  -H "Content-Type: application/json" \
  "https://api.github.com/repos/peptiderepo/<repo>/pulls" \
  -d "$(jq -cn --arg title "<title>" --arg head "<branch>" --arg body "<body>" \
      '{title:$title, head:$head, base:"main", body:$body}')"
```

### When this changes

If the peptiderepo GitHub account is ever upgraded to Pro (or the repo goes public), replace this soft-enforcement section with a note pointing to the real branch protection rules in repo settings.