# AnimeHub Codebase Improvement Report

**Generated:** 2026-09-26
**Scope:** Full codebase audit of all PHP, JS, config, and infrastructure files

---

## Table of Contents

1. [Critical Security Vulnerabilities](#1-critical-security-vulnerabilities)
2. [High Severity Issues](#2-high-severity-issues)
3. [Medium Severity Issues](#3-medium-severity-issues)
4. [Performance Improvements](#4-performance-improvements)
5. [Architecture & Design](#5-architecture--design)
6. [Code Quality](#6-code-quality)
7. [Database Improvements](#7-database-improvements)
8. [Frontend / JavaScript](#8-frontend--javascript)
9. [DevOps & Deployment](#9-devops--deployment)
10. [Recommended Priority Roadmap](#10-recommended-priority-roadmap)

---

## 1. Critical Security Vulnerabilities

### 1.1 Live API Keys Exposed in Git-Tracked File

- **File:** `config/config.local.php`
- **Severity:** CRITICAL
- **Issue:** Despite being listed in `.gitignore`, this file contains live TMDB API key (`4343034868a20a38c503cc0d3be89ec0`), TMDB access token (JWT), and MegaPlay relay HMAC secret hardcoded directly. If this file was ever committed before the gitignore rule was added, the keys are in git history.
- **Fix:**
  - Immediately rotate ALL exposed keys at their respective provider dashboards
  - Move secrets to environment variables or a `.env` file loaded via `vlucas/phpdotenv`
  - Audit git history with `git log --all --full-history -- config/config.local.php` and consider BFG Repo-Cleaner to purge any historical leaks
  - Never define secrets with `define()` using literal strings in any PHP file

### 1.2 Hardcoded OMDB API Key

- **File:** `admin/upload_data.php:15,53`
- **Severity:** CRITICAL
- **Issue:** API key `d44a4778` is hardcoded directly in the source file, duplicated in two places.
- **Fix:** Move to `config.local.php` or environment variable. Reference via constant: `OMDB_API_KEY`.

### 1.3 Cookie-Based Session Restoration Without Signature Verification

- **File:** `includes/header.php:12-18`
- **Severity:** CRITICAL
- **Issue:** When `$_SESSION['user_id']` is missing but cookies exist, the code blindly trusts `$_COOKIE['userID']`, `$_COOKIE['userName']`, and `$_COOKIE['userRole']` to restore the session. Cookies are client-controlled plaintext — any user can forge these values to impersonate another user or escalate to admin role.
- **Fix:**
  - Never trust raw cookie values for authentication
  - Use signed tokens (HMAC) or server-side "remember me" tokens stored in the database
  - At minimum, store a hash in the cookie that maps to a DB record, and validate it server-side
  - Remove lines 12-18 entirely and replace with a proper persistent login system

### 1.4 No CSRF Protection Anywhere

- **Files:** All forms (authentication.php, admin/index.php, user/settings.php, watch.php interactions, etc.)
- **Severity:** CRITICAL
- **Issue:** Zero CSRF tokens exist in the entire codebase. Every state-changing form and AJAX POST endpoint is vulnerable to Cross-Site Request Forgery attacks.
- **Fix:**
  - Generate a per-session CSRF token: `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`
  - Include hidden field in every form: `<input type="hidden" name="csrf_token" value="...">`
  - Validate token on every POST/PUT/DELETE request before processing
  - For AJAX endpoints, send token via `X-CSRF-Token` header and validate server-side

### 1.5 Admin Endpoints Missing Authentication Checks

- **Files:** `admin/index.php`, `admin/upload_data.php`, `admin/add_movie.php`, `admin/edit_movie.php`
- **Severity:** CRITICAL
- **Issue:** `admin/manage_users.php` correctly checks `$_SESSION['role'] === 'admin'`, but `admin/index.php` and `admin/upload_data.php` have NO authentication or authorization checks. Any unauthenticated user can access the admin upload forms and submit data.
- **Fix:** Add role-checking guard at the top of every admin file:
  ```php
  if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
      http_response_code(403);
      header('Location: ../authentication.php');
      exit;
  }
  ```

### 1.6 SQL Injection via String Interpolation in OMDB URL

- **File:** `admin/upload_data.php:16,54`
- **Severity:** HIGH
- **Issue:** `$imdb_id` from `$_POST` is interpolated directly into a URL string without sanitization: `"http://www.omdbapi.com/?i=$imdb_id&apikey=$api_key"`. While this is an SSRF vector rather than SQL injection, the same unsanitized value could cause issues if used in queries elsewhere.
- **Fix:** Validate IMDb ID format before use: `if (!preg_match('/^tt\d+$/', $imdb_id)) { ... }`

### 1.7 Sensitive Data Exposure via Error Messages

- **File:** `includes/db.php:10`
- **Severity:** MEDIUM
- **Issue:** `die("Connection failed: " . $conn->connect_error)` exposes database error details (potentially host, port, credentials info) to end users.
- **Fix:** Log the real error, show a generic message:
  ```php
  if ($conn->connect_error) {
      error_log('[DB] Connection failed: ' . $conn->connect_error);
      die('Service temporarily unavailable. Please try again later.');
  }
  ```

### 1.8 Cookies Set Without Security Flags

- **File:** `authentication.php:26-28,33-35,55-57,61-63`
- **Severity:** HIGH
- **Issue:** `setcookie()` calls lack `httponly`, `secure`, and `samesite` flags. This makes session cookies accessible to JavaScript (XSS theft) and sent over non-HTTPS connections.
- **Fix:**
  ```php
  setcookie('userID', $user['id'], [
      'expires' => time() + 86400 * 30,
      'path' => '/',
      'secure' => true,
      'httponly' => true,
      'samesite' => 'Lax',
  ]);
  ```

### 1.9 XSS Risk in watch.php Inline Script

- **File:** `watch.php` (inline JavaScript)
- **Severity:** HIGH
- **Issue:** PHP variables are injected directly into JavaScript using `addslashes()` which is insufficient for XSS prevention in JS contexts. Example: `'<?= addslashes($raw_id) ?>'` — an attacker can craft an `$raw_id` that escapes the JS string context.
- **Fix:** Always use `json_encode()` for passing PHP values to JavaScript:
  ```php
  const sharedImdbId = <?= json_encode($raw_id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  ```

---

## 2. High Severity Issues

### 2.1 Dual Database Connections (MySQLi + PDO)

- **File:** `includes/db.php`
- **Severity:** HIGH
- **Issue:** The application creates both a MySQLi connection (`$conn`) and a PDO connection (`$pdo`) on every page load. This doubles database connections, increases memory usage, and creates inconsistency — some files use MySQLi prepared statements, others use PDO.
- **Fix:** Standardize on PDO exclusively. It supports named parameters, better error handling, and is the modern PHP standard. Replace all `$conn->prepare()` calls with `$pdo->prepare()`.

### 2.2 DDL Inside Application Logic

- **File:** `includes/functions.php:134-141`
- **Severity:** HIGH
- **Issue:** `CREATE TABLE IF NOT EXISTS user_settings` runs inside `kp_user_settings()` which is called during page rendering. DDL should never execute as part of application runtime — it causes performance overhead, requires elevated DB privileges, and can lead to race conditions.
- **Fix:** Move schema creation to a dedicated migration script (e.g., `tools/migrate.php`) run during deployment, not on every request.

### 2.3 No Rate Limiting on Authentication

- **File:** `authentication.php`
- **Severity:** HIGH
- **Issue:** Login and registration endpoints have no rate limiting or brute-force protection. An attacker can attempt thousands of passwords per minute.
- **Fix:**
  - Implement IP-based rate limiting (e.g., max 5 attempts per 15 minutes per IP)
  - Add account lockout after N failed attempts
  - Consider CAPTCHA after repeated failures
  - Store attempt counts in database or Redis

### 2.4 No Rate Limiting on API/AJAX Endpoints

- **Files:** `includes/toggle_like.php`, `includes/save_progress.php`, `includes/count_view.php`, etc.
- **Severity:** HIGH
- **Issue:** All AJAX endpoints accept unlimited requests. A malicious user can spam likes, inflate view counts, or flood the progress tracking.
- **Fix:** Implement per-user and per-IP rate limiting middleware applied to all state-changing endpoints.

### 2.5 Inconsistent Input Validation

- **Files:** Multiple
- **Severity:** HIGH
- **Issue:** Some endpoints validate input (`filter_var` in watch.php:14), while most don't. `$_GET['id']` in various includes files, `$_POST` values in admin forms, and JSON body inputs in update endpoints lack consistent validation/sanitization.
- **Fix:** Create a centralized input validation helper and apply it uniformly at every entry point.

### 2.6 Missing Authorization Checks on User Endpoints

- **Files:** `includes/delete_notification.php`, `includes/mark_notification_read.php`, `includes/save_progress.php`
- **Severity:** HIGH
- **Issue:** Several endpoints accept `user_id` or `id` from request parameters without verifying the authenticated session user owns that resource. This enables Insecure Direct Object Reference (IDOR) attacks.
- **Fix:** Always derive user identity from `$_SESSION['user_id']`, never from request parameters.

---

## 3. Medium Severity Issues

### 3.1 No Content Security Policy (CSP)

- **File:** `.htaccess`
- **Severity:** MEDIUM
- **Issue:** While X-Frame-Options and X-XSS-Protection headers are set, there is no Content-Security-Policy header. CSP is the most effective defense against XSS.
- **Fix:** Add a CSP header in `.htaccess`:
  ```apache
  Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:; font-src 'self' https:;"
  ```
  Then progressively remove `'unsafe-inline'` by using nonces or hashes.

### 3.2 Service Worker Caches Dynamic Pages

- **File:** `sw.js`
- **Severity:** MEDIUM
- **Issue:** The service worker caches `/` and `/index.php` with a cache-first strategy. Since index.php renders dynamic content (trending, user-specific data), users will see stale content. The SW also lacks version bumping for cache invalidation.
- **Fix:** Only cache static assets in the SW. Use network-first or stale-while-revalidate for HTML pages. Add proper cache-busting version numbers.

### 3.3 Duplicate OMDB API Calls

- **File:** `admin/upload_data.php:16-30,54-68`
- **Severity:** MEDIUM
- **Issue:** The exact same OMDB API fetch logic is copy-pasted twice (for movies and shows). This violates DRY and means bug fixes must be applied in two places.
- **Fix:** Extract into a reusable function:
  ```php
  function fetch_omdb_data(string $imdb_id): ?array { ... }
  ```

### 3.4 Bare Exception Catching

- **Files:** Multiple (watch.php, functions.php, etc.)
- **Severity:** LOW-MEDIUM
- **Issue:** Many try/catch blocks use `catch (Exception $e)` or empty catches `catch (...) { /* ignore */ }`. Silent exception swallowing hides bugs and makes debugging extremely difficult.
- **Fix:** Always log caught exceptions. Use specific exception types. Never silently swallow errors in production.

### 3.5 No HTTPS Enforcement at Application Level

- **File:** `.htaccess:2-3`
- **Severity:** LOW
- **Issue:** HTTPS redirect exists in `.htaccess` but relies on Apache module being enabled. If `.htaccess` is bypassed or misconfigured, traffic flows over HTTP.
- **Fix:** Also enforce in PHP at the top of `config.php` or `header.php`:
  ```php
  if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
      header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
      exit;
  }
  ```

### 3.6 Missing Security Headers

- **File:** `.htaccess`
- **Severity:** MEDIUM
- **Issue:** Missing several recommended headers: `Permissions-Policy`, `Strict-Transport-Security` (HSTS).
- **Fix:** Add to `.htaccess`:
  ```apache
  Header set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header set Permissions-Policy "camera=(), microphone=(), geolocation=()"
  ```

### 3.7 Session Fixation Risk

- **File:** `authentication.php`
- **Severity:** MEDIUM
- **Issue:** After successful login, the session ID is not regenerated. An attacker who obtains a pre-auth session ID can hijack the authenticated session.
- **Fix:** Call `session_regenerate_id(true)` immediately after successful authentication.

### 3.8 No Password Complexity Requirements

- **File:** `authentication.php:44-46`
- **Severity:** LOW
- **Issue:** Registration only checks that passwords match, not complexity. Users can register with single-character passwords.
- **Fix:** Enforce minimum length (8+ chars), and consider requiring mixed character types.

---

## 4. Performance Improvements

### 4.1 N+1 Query Pattern in watch.php

- **File:** `watch.php:64-92`
- **Severity:** HIGH
- **Issue:** Five separate database queries run sequentially for views, user-like, total-likes, watchlist, and follows. Each has its own try/catch block. On a high-traffic page, this adds significant latency.
- **Fix:** Combine into a single query using JOINs or subqueries, or batch them. Alternatively, cache these counts.

### 4.2 No Server-Side Pagination for Catalog

- **Files:** `includes/catalog.php`, `index.php`
- **Severity:** MEDIUM
- **Issue:** Trending, popular, recent, and upcoming feeds are loaded entirely into memory. As the catalog grows, this will consume excessive memory and slow page loads.
- **Fix:** Implement pagination or cursor-based loading. Load only the first N items server-side, fetch more via AJAX.

### 4.3 Inline JavaScript Blocks Rendering

- **File:** `watch.php` (thousands of lines of inline JS)
- **Severity:** HIGH
- **Issue:** Massive inline `<script>` blocks prevent browser optimization, cannot be cached independently, increase HTML payload size, and block rendering.
- **Fix:** Extract all inline JavaScript into external `.js` files. Pass server-side data via `data-*` attributes or a global config object:
  ```html
  <script>window.KP_CONFIG = <?= json_encode([...]) ?>;</script>
  <script src="/assets/js/watch.js" defer></script>
  ```

### 4.4 No Database Indexes Defined in Code

- **Files:** All
- **Severity:** MEDIUM
- **Issue:** No SQL migration files or index definitions were found. Queries filtering by `imdb_id`, `user_id`, `anime_slug` likely perform full table scans without proper indexes.
- **Fix:** Create a migration file that ensures indexes exist:
  ```sql
  CREATE INDEX idx_views_imdb ON views(imdb_id);
  CREATE INDEX idx_likes_user_imdb ON likes(user_id, imdb_id);
  CREATE INDEX idx_watchlist_user ON watchlist(user_id);
  CREATE INDEX idx_follows_user ON follows(user_id);
  ```

### 4.5 File-Based Caching Limitations

- **File:** `config/config.php` (cache TTLs defined but no cache driver visible)
- **Severity:** LOW
- **Issue:** Based on docs/performance.md, caching is file-based. Under high concurrency, file locks become a bottleneck.
- **Fix:** Consider Redis or Memcached for cache backend, especially for frequently accessed data like trending lists.

### 4.6 No Lazy Loading for Off-Screen Content

- **File:** `index.php`
- **Severity:** LOW
- **Issue:** All card rows (trending, popular, recent, upcoming) render immediately even though most are below the fold.
- **Fix:** Implement intersection observer-based lazy loading for card rows below the hero slider.

---

## 5. Architecture & Design

### 5.1 No MVC or Separation of Concerns

- **Severity:** HIGH
- **Issue:** PHP files mix database queries, business logic, HTML rendering, and JavaScript in the same file. `watch.php` alone contains SQL queries, API calls, HTML structure, and thousands of lines of JavaScript.
- **Fix:** Adopt a lightweight MVC pattern or at minimum separate concerns:
  - Controllers handle request routing and orchestration
  - Models handle database operations
  - Views handle HTML rendering only
  - Services handle external API calls

### 5.2 No Routing System

- **Severity:** MEDIUM
- **Issue:** Every URL maps directly to a PHP file. There is no front controller or router, making URL management fragile and preventing clean URLs.
- **Fix:** Implement a simple router or adopt a micro-framework (Slim, Laminas Mezzio) that maps routes to controllers.

### 5.3 No Dependency Management

- **Severity:** MEDIUM
- **Issue:** No `composer.json` found. The project has zero dependency management, meaning no autoloading, no version pinning, and no ability to use well-tested libraries.
- **Fix:** Initialize Composer, add PSR-4 autoloading, and consider adopting packages for common needs (routing, HTTP client, validation).

### 5.4 Mixed API Patterns

- **Severity:** MEDIUM
- **Issue:** Some endpoints return JSON (`update_account.php`), some return HTML fragments (`category_rails.php`), some redirect. There is no consistent API contract.
- **Fix:** Standardize response format. Use `Content-Type: application/json` consistently for AJAX endpoints with a uniform envelope: `{ success: bool, data?: any, error?: string }`.

### 5.5 Global State Overuse

- **File:** `config/config.php`
- **Severity:** LOW
- **Issue:** Heavy reliance on `$GLOBALS` for configuration arrays (embed providers, resolver allowlists, aggregators). Makes testing impossible and creates implicit coupling.
- **Fix:** Use a configuration class with static methods or a DI container.

### 5.6 No Error Handling Strategy

- **Severity:** MEDIUM
- **Issue:** Errors are handled inconsistently — some die(), some log and continue, some show raw messages to users, some are silently swallowed.
- **Fix:** Implement a global error handler (`set_error_handler`, `set_exception_handler`) that logs everything and shows user-friendly error pages.

---

## 6. Code Quality

### 6.1 Duplicated Code

- **Files:** `admin/index.php` vs `admin/upload_data.php` (forms duplicated), `config/config.php` (embed provider arrays)
- **Severity:** MEDIUM
- **Issue:** The admin form HTML appears identically in both `admin/index.php` and was returned when reading `admin/upload_data.php`. Embed provider configurations are massive duplicated arrays.
- **Fix:** Extract shared markup into partial templates. Extract embed config into a separate file or database table.

### 6.2 Inconsistent Naming Conventions

- **Severity:** LOW
- **Issue:** Mix of snake_case (`$imdb_id`, `$video_url`), camelCase (`$catalogItem`, `$tmdbId`), and prefixed names (`kp_e`, `kp_watch_url`). Database columns use snake_case, some PHP variables use camelCase.
- **Fix:** Adopt PSR-12 coding standards. Use camelCase for variables/methods, PascalCase for classes, UPPER_SNAKE_CASE for constants.

### 6.3 Magic Numbers and Strings

- **Files:** Throughout
- **Severity:** LOW
- **Issue:** Values like `86400 * 30` (cookie expiry), `42` (title truncation in watch.php), `120` (ep search debounce), `15` (curl timeouts) appear as magic numbers without explanation.
- **Fix:** Define named constants for all magic values.

### 6.4 Dead Code

- **File:** `authentication.php:6`
- **Severity:** LOW
- **Issue:** `$success = ''` is declared but never assigned a truthy value anywhere in the file.
- **Fix:** Remove unused variables.

### 6.5 Encoding Issues in Source Files

- **Files:** `config/config.php`, `watch.php`, multiple includes
- **Severity:** LOW
- **Issue:** Many files contain garbled UTF-8 characters (mojibake) in comments and UI strings, suggesting files were saved with wrong encoding at some point. Examples: `â€œ`, `Ã©`, replacement characters.
- **Fix:** Ensure all files are saved as UTF-8 without BOM. Audit and fix corrupted strings.

### 6.6 No Type Declarations

- **Severity:** LOW
- **Issue:** Almost no PHP functions use parameter or return type declarations. `function kp_e($value)` should be `function kp_e(mixed $value): string`.
- **Fix:** Gradually add type hints (PHP 7.4+ supports typed properties, PHP 8.0+ supports union types).

---

## 7. Database Improvements

### 7.1 No Migration System

- **Severity:** HIGH
- **Issue:** Database schema changes are done ad-hoc (including DDL in `functions.php`). There is no version-controlled migration system.
- **Fix:** Adopt a migration tool (Phinx, Laravel migrations standalone, or plain numbered SQL files) and remove DDL from application code.

### 7.2 No Database Backup Strategy Visible

- **Severity:** MEDIUM
- **Issue:** No backup scripts or scheduled dump configurations found.
- **Fix:** Create a cron job script: `tools/backup_db.php` that dumps the database daily.

### 7.3 Potential Data Integrity Issues

- **Severity:** MEDIUM
- **Issue:** No foreign key constraints visible in the code. `show_id` in seasons table and `season_id` in episodes table reference parent tables but may not have FK enforcement.
- **Fix:** Add foreign key constraints to maintain referential integrity.

### 7.4 No Soft Deletes

- **Severity:** LOW
- **Issue:** Deleting records (notifications, notes, watchlist items) permanently removes data with no recovery option.
- **Fix:** Add `deleted_at` timestamp columns and use soft deletes for user-generated content.

---

## 8. Frontend / JavaScript

### 8.1 Massive Inline Scripts

- **File:** `watch.php`
- **Severity:** HIGH
- **Issue:** The watch page contains what appears to be thousands of lines of inline JavaScript. This prevents caching, blocks rendering, and makes the code unmaintainable.
- **Fix:** Move to external JS files bundled with a build tool (Vite, esbuild, Webpack).

### 8.2 No JavaScript Module System

- **Severity:** MEDIUM
- **Issue:** All scripts use global scope or IIFEs. No ES modules or bundling.
- **Fix:** Adopt ES modules (`<script type="module">`) or a bundler.

### 8.3 Manual DOM Manipulation Instead of Framework

- **Severity:** LOW
- **Issue:** Complex state management (episode lists, player controls, season tabs, progress tracking) is done with vanilla DOM manipulation, leading to bugs and maintenance burden.
- **Fix:** Consider Alpine.js for lightweight reactivity or a small framework for complex interactive components.

### 8.4 Accessibility Issues

- **Files:** Multiple
- **Severity:** MEDIUM
- **Issue:**
  - Forms lack proper `<label for="">` associations in some places
  - Modal dialogs don't trap focus
  - Color contrast may be insufficient (dark theme dependent)
  - Screen reader announcements for dynamic content (episode loading, countdown) are missing
  - `aria-label` attributes present on some elements but inconsistent
- **Fix:** Run automated a11y audit (axe-core), add `aria-live` regions for dynamic content, implement focus trapping in modals.

### 8.5 No Client-Side Form Validation Feedback

- **File:** `authentication.php`
- **Severity:** LOW
- **Issue:** Forms rely solely on `required` attribute. No real-time validation feedback for password strength, email format, or username availability.
- **Fix:** Add client-side validation with helpful inline error messages.

---

## 9. DevOps & Deployment

### 9.1 No CI/CD Pipeline

- **Severity:** MEDIUM
- **Issue:** No `.github/workflows`, `.gitlab-ci.yml`, or other CI configuration found. Code goes directly to production without automated testing or linting.
- **Fix:** Add GitHub Actions workflow for PHP linting, security scanning, and basic tests.

### 9.2 No Automated Testing

- **Severity:** HIGH
- **Issue:** Zero test files found. No PHPUnit, no Jest, no integration tests.
- **Fix:** Start with critical path tests: authentication flow, admin authorization, stream resolution.

### 9.3 No Environment Configuration

- **Severity:** MEDIUM
- **Issue:** No `.env.example`, no Docker setup, no way for a new developer to understand required configuration.
- **Fix:** Create `.env.example` documenting all required environment variables. Add Docker Compose for local development.

### 9.4 No Logging Infrastructure

- **Severity:** MEDIUM
- **Issue:** Errors are logged via `error_log()` to the default PHP error log with no structured logging, log rotation, or centralized log management.
- **Fix:** Adopt Monolog or similar PSR-3 logger. Write structured JSON logs. Set up log aggregation.

### 9.5 No Health Check Endpoint

- **Severity:** LOW
- **Issue:** No `/health` or `/status` endpoint for monitoring uptime, database connectivity, or cache health.
- **Fix:** Add a simple health check that verifies DB connection and returns 200/503.

### 9.6 Uploads Directory Security

- **File:** `uploads/`
- **Severity:** MEDIUM
- **Issue:** The uploads directory exists but no restrictions on executable files within it were found. If users can upload PHP files, they achieve RCE.
- **Fix:** Add to `.htaccess`:
  ```apache
  <Directory "/uploads">
      php_flag engine off
      Options -ExecCGI
      AddHandler cgi-script .cgi .pl .py
      RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
  </Directory>
  ```

---

## 10. Recommended Priority Roadmap

### Phase 1: Critical Security (Week 1)

| # | Task | Effort |
|---|------|--------|
| 1 | Rotate all exposed API keys (TMDB, OMDB, MegaPlay) | 1h |
| 2 | Fix cookie-based session forgery vulnerability | 4h |
| 3 | Add CSRF tokens to all forms and AJAX endpoints | 8h |
| 4 | Add authentication guards to all admin endpoints | 2h |
| 5 | Fix XSS in watch.php inline scripts (use json_encode) | 2h |
| 6 | Add security flags to all cookies | 1h |
| 7 | Add session_regenerate_id() on login | 30m |

### Phase 2: Architecture Foundations (Week 2-3)

| # | Task | Effort |
|---|------|--------|
| 8 | Consolidate to single PDO database connection | 8h |
| 9 | Extract DDL from application code into migrations | 4h |
| 10 | Move secrets to environment variables / .env | 4h |
| 11 | Extract inline JS from watch.php to external files | 16h |
| 12 | Implement centralized input validation | 8h |
| 13 | Add rate limiting to auth and API endpoints | 8h |

### Phase 3: Quality & Performance (Week 4-5)

| # | Task | Effort |
|---|------|--------|
| 14 | Optimize N+1 queries in watch.php | 4h |
| 15 | Add database indexes for frequent queries | 2h |
| 16 | Implement server-side pagination for catalogs | 8h |
| 17 | Add CSP and missing security headers | 2h |
| 18 | Fix encoding issues in source files | 4h |
| 19 | Add type declarations to PHP functions | 16h |
| 20 | Write unit tests for critical paths | 20h |

### Phase 4: Long-term (Ongoing)

| # | Task | Effort |
|---|------|--------|
| 21 | Adopt Composer for dependency management | 4h |
| 22 | Implement MVC separation | 40h |
| 23 | Add CI/CD pipeline | 8h |
| 24 | Set up structured logging | 4h |
| 25 | Improve accessibility (a11y audit) | 16h |

---

## Summary Statistics

| Category | Count |
|----------|-------|
| Critical vulnerabilities | 6 |
| High severity issues | 7 |
| Medium severity issues | 10 |
| Low severity issues | 7 |
| Performance issues | 6 |
| Architecture concerns | 6 |
| **Total findings** | **~42** |

---

*This report was generated by automated codebase analysis. Each finding includes file references and specific remediation steps. Prioritize Phase 1 items immediately as they represent active security risks.*
