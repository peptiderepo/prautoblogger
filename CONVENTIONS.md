# AutoBlogger — Conventions

This document codifies the naming patterns, coding conventions, and step-by-step guides for extending AutoBlogger. Update this file whenever a new pattern is introduced.

---

## Naming Conventions

### PHP Classes
- **Format:** `Autoblogger_{PascalCase}` (e.g., `Autoblogger_Content_Generator`)
- **File naming:** `class-{kebab-case}.php` (e.g., `class-content-generator.php`)
- **Interfaces:** `interface-{kebab-case}.php` with `Autoblogger_{Name}_Interface` class name

### Hooks (Actions & Filters)
- **Prefix:** All hooks start with `autoblogger_`
- **Actions:** `autoblogger_{event}` (e.g., `autoblogger_before_content_generation`)
- **Filters:** `autoblogger_filter_{what}` (e.g., `autoblogger_filter_article_idea_score`)
- **Hook callbacks registered in:** `class-autoblogger.php` (central loader), never in the classes themselves

### Database
- **Table prefix:** `{$wpdb->prefix}autoblogger_` (e.g., `wp_autoblogger_source_data`)
- **Option prefix:** `autoblogger_` (e.g., `autoblogger_openrouter_api_key`)
- **Post meta prefix:** `_autoblogger_` (underscore prefix hides from custom fields UI)
- **Transient prefix:** `autoblogger_` (e.g., `autoblogger_reddit_token`)

### Constants
- **Format:** `AUTOBLOGGER_{UPPER_SNAKE}` (e.g., `AUTOBLOGGER_MAX_RETRIES`)
- **Defined in:** `autoblogger.php` (bootstrap file) for global constants

### Hook Callback Methods
- **Action callbacks:** Prefixed with `on_` (e.g., `on_daily_generation_triggered`)
- **Filter callbacks:** Prefixed with `filter_` (e.g., `filter_content_quality`)

---

## Error Handling & Logging

### Structured Logger
All logging uses `Autoblogger_Logger` (singleton) instead of raw `error_log()`. The logger writes to the `ab_event_log` custom table and forwards errors/warnings to PHP's `error_log()` for server-level monitoring. The verbosity threshold is user-configurable via Settings → Publishing → Log Level.

**Never call `error_log()` directly.** Always use the Logger:

```php
$logger = Autoblogger_Logger::instance();
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
- Generation failures → logged to `ab_generation_log` with `response_status = 'error'`
- Generation failures also shown in the admin dashboard widget
- All log entries visible in AutoBlogger → Activity Log (filterable by level)

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
2. Implement `Autoblogger_LLM_Provider_Interface` (see `interface-llm-provider.php`)
3. Required methods: `send_chat_completion()`, `get_available_models()`, `estimate_cost()`
4. Add provider option to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-autoblogger.php` loader
6. Update ARCHITECTURE.md external API integrations table

### Interface contract:
```php
interface Autoblogger_LLM_Provider_Interface {
    public function send_chat_completion(array $messages, string $model, array $options = []): array;
    public function get_available_models(): array;
    public function estimate_cost(string $model, int $prompt_tokens, int $completion_tokens): float;
    public function get_provider_name(): string;
}
```

---

## How To: Add a New Source Provider (Social Platform)

1. Create `includes/providers/class-{platform}-provider.php`
2. Implement `Autoblogger_Source_Provider_Interface` (see `interface-source-provider.php`)
3. Required methods: `collect_data()`, `get_source_type()`, `validate_credentials()`
4. Add API credentials fields to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-autoblogger.php` loader
6. Add the source type string to the `ab_source_data.source_type` allowed values
7. Update ARCHITECTURE.md file tree and external API integrations table

### Interface contract:
```php
interface Autoblogger_Source_Provider_Interface {
    public function collect_data(array $config): array; // Returns Autoblogger_Source_Data[]
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
       'id'          => 'autoblogger_new_setting',
       'label'       => __('Setting Label', 'autoblogger'),
       'type'        => 'text', // text, number, select, textarea, checkbox, password
       'default'     => '',
       'description' => __('Help text shown below the field.', 'autoblogger'),
       'sanitize'    => 'sanitize_text_field', // sanitization callback
   ]
   ```
3. The settings page renderer handles registration, rendering, and sanitization automatically from this array.
4. Access the setting anywhere with: `get_option('autoblogger_new_setting', $default)`
5. For encrypted settings (API keys): use `Autoblogger_Encryption::encrypt()` / `decrypt()`

---

## How To: Add a New Pipeline Stage

The writing pipeline is an ordered array of stages. Each stage is a class method on `Content_Generator`.

1. Add the stage method to `class-content-generator.php`: `protected function stage_{name}(Autoblogger_Content_Request $request): string`
2. Register the stage in the `get_pipeline_stages()` method for the appropriate mode (single_pass or multi_step)
3. Each stage receives the accumulated context and returns its output
4. Each stage logs its cost via `Cost_Tracker::log_api_call()`
5. If a stage fails after retries, the entire pipeline fails (no partial publishes)

---

## Code Quality Rules

1. **`declare(strict_types=1);`** in every PHP file
2. **Type declarations** on all method parameters, return types, and class properties
3. **No file exceeds 300 lines.** Split when approaching this limit
4. **Every class has a 3-question preamble docblock:** What / Who triggers / Dependencies
5. **Every public method has a docblock** with `@param`, `@return`, and side effects
6. **`@see` references** at the top of files that participate in multi-class flows
7. **All strings translatable** via `__()` / `_e()` with `autoblogger` text domain
8. **No magic methods** without explicit justification documented in code
9. **No `echo` of raw data** — always escape with `esc_html()`, `esc_attr()`, etc.
10. **Nonce on every form/AJAX** — `wp_nonce_field()` / `check_ajax_referer()`
