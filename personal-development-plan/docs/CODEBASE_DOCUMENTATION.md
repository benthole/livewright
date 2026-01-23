# LiveWright PDP - Codebase Documentation

## Overview

**LiveWright PDP** (Personal Development Plan) is a PHP-based web application for creating, managing, and presenting personalized coaching/development plans with tiered pricing options. The application enables administrators to create customized plans for clients who can then review and sign up for their selected pricing tier.

**Production URL:** `https://checkout.livewright.com/personal-development-plan/`

---

## Technology Stack

| Component | Technology |
|-----------|------------|
| **Backend** | PHP 7+ (procedural) |
| **Database** | MySQL (PDO) |
| **Frontend** | Vanilla HTML/CSS/JavaScript |
| **Rich Text Editor** | Quill.js (v1.3.7 via CDN) |
| **Hosting** | RunCloud-managed server |
| **CI/CD** | GitHub Actions |
| **Version Control** | Git |

---

## Project Structure

```
livewright-pdp/
├── .claude/                    # Claude Code configuration (symlinked)
│   ├── agents -> /Users/benthole/Development/.claude/agents
│   ├── hooks -> /Users/benthole/Development/.claude/hooks
│   ├── skills -> /Users/benthole/Development/.claude/skills
│   └── settings.local.json     # Project-specific Claude permissions
├── .github/
│   └── workflows/
│       └── deploy.yml          # GitHub Actions deployment workflow
├── admin/                      # Admin panel (protected area)
│   ├── index.php               # Dashboard - lists all plans
│   ├── login.php               # Admin authentication
│   ├── logout.php              # Session termination
│   ├── edit.php                # Create/edit plans
│   ├── delete.php              # Soft-delete plans
│   ├── presets.php             # List plan presets
│   ├── preset-edit.php         # Create/edit presets
│   └── preset-delete.php       # Delete presets
├── config.php                  # Database connection & auth helpers
├── index.php                   # Public-facing plan display
├── next.php                    # Checkout confirmation page
├── CLAUDE.md                   # Project-specific Claude instructions
├── .gitignore                  # Git ignore rules
└── deploy-test.txt             # Deployment verification file
```

---

## Database Schema

### Tables (Inferred from Code)

#### `admin_users`
Admin authentication table.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `username` | VARCHAR | Login username |
| `password_hash` | VARCHAR | Bcrypt-hashed password |

**Default credentials:** `admin` / `admin123`

#### `contracts`
Main table for Personal Development Plans.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `unique_id` | VARCHAR | Public-facing unique identifier (generated via `uniqid('', true)`) |
| `first_name` | VARCHAR | Client first name |
| `last_name` | VARCHAR | Client last name |
| `email` | VARCHAR | Client email (unique per active record) |
| `contract_description` | TEXT | HTML content - items included in all options |
| `pdp_from` | TEXT | HTML content - "FROM" state (challenges) |
| `pdp_toward` | TEXT | HTML content - "TOWARD" state (ideal outcomes) |
| `option_1_minimum_months` | INT | Minimum commitment for Option 1 (default: 1) |
| `option_2_minimum_months` | INT | Minimum commitment for Option 2 (default: 1) |
| `option_3_minimum_months` | INT | Minimum commitment for Option 3 (default: 1) |
| `selected_option_id` | INT | FK to pricing_options (after signing) |
| `signed` | TINYINT | Boolean - whether plan is signed |
| `created_at` | DATETIME | Creation timestamp |
| `deleted_at` | DATETIME | Soft delete timestamp (NULL = active) |

#### `pricing_options`
Pricing tiers associated with contracts.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `contract_id` | INT | FK to contracts |
| `option_number` | INT | Option grouping (1, 2, or 3) |
| `sub_option_name` | VARCHAR | Sub-option label (e.g., coach name) or "Default" |
| `description` | TEXT | HTML content describing the option |
| `price` | DECIMAL | Price amount |
| `type` | ENUM | 'Monthly', 'Quarterly', 'Yearly' |
| `deleted_at` | DATETIME | Soft delete timestamp |

#### `pdp_presets`
Template configurations for quick plan creation.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `name` | VARCHAR | Preset name (unique) |
| `contract_description` | TEXT | Default "included" content |
| `pdp_from` | TEXT | Default "FROM" content |
| `pdp_toward` | TEXT | Default "TOWARD" content |
| `option_1_desc` | TEXT | Option 1 description |
| `option_1_price_monthly` | DECIMAL | Option 1 monthly price |
| `option_1_price_quarterly` | DECIMAL | Option 1 quarterly price |
| `option_1_price_yearly` | DECIMAL | Option 1 yearly price |
| `option_1_sub_options` | TEXT | JSON array of sub-options |
| `option_1_minimum_months` | INT | Option 1 minimum commitment |
| (repeat for options 2 and 3) | ... | ... |

---

## Application Flow

### 1. Admin Creates a Plan

```
[Admin Login] --> [Dashboard] --> [Add New Plan]
                                        |
                                        v
                              [Fill out form with:]
                              - Client details (name, email)
                              - FROM/TOWARD sections
                              - What's Included
                              - Up to 3 pricing options
                              - Sub-options per option (optional)
                              - Minimum commitment periods
                                        |
                                        v
                              [Save] --> [Plan created with unique_id]
```

### 2. Client Views and Signs Plan

```
[Client receives link] --> checkout.livewright.com/personal-development-plan/?uid=UNIQUE_ID
        |
        v
[index.php displays:]
- Contract details
- FROM/TOWARD sections
- What's Included
- Pricing options (Monthly/Quarterly/Yearly columns)
- Discount calculations shown
- Minimum commitment warnings
        |
        v
[Client selects option] --> [Continue to Checkout]
        |
        v
[next.php - Confirmation page:]
- Review selection
- Client info displayed
        |
        v
[Confirm & Sign] --> [Contract marked as signed]
                     [selected_option_id recorded]
```

---

## Key Features

### Pricing System

1. **Three Billing Periods:** Monthly, Quarterly, Yearly
2. **Automatic Discount Calculation:**
   - Quarterly: 5% discount (monthly * 3 * 0.95)
   - Yearly: 10% discount (monthly * 12 * 0.90)
3. **Sub-Options:** Allow variations within an option (e.g., different coaches with different rates)
4. **Minimum Commitment:** Per-option configurable minimum months

### Preset System

- Presets store complete plan templates
- Can include sub-options as JSON
- Quick-start workflow: select preset to pre-fill all fields
- Currently hardcoded preset buttons for IDs 1 and 2

### Rich Text Editing

- Uses Quill.js for WYSIWYG editing
- Toolbar: bold, italic, underline, alignment, lists, links, clean
- Content stored as HTML in database
- Applied to: contract_description, pdp_from, pdp_toward, option descriptions

### Soft Deletes

- Contracts and pricing_options use `deleted_at` timestamps
- Records are never permanently removed via admin UI
- Presets use hard deletes

---

## Security Considerations

### Current Implementation

| Area | Status | Notes |
|------|--------|-------|
| **SQL Injection** | Protected | Uses PDO prepared statements throughout |
| **XSS** | Partial | Uses `htmlspecialchars()` for most output; rich text content rendered as raw HTML |
| **CSRF** | Not implemented | Forms lack CSRF tokens |
| **Session Security** | Basic | Uses PHP sessions; no additional hardening |
| **Password Storage** | Secure | Uses `password_hash()` / `password_verify()` |
| **Credentials in Code** | Issue | Database credentials hardcoded in config.php |

### Recommendations for Production

1. Move database credentials to environment variables
2. Add CSRF protection to all forms
3. Sanitize HTML content (consider HTMLPurifier for rich text)
4. Add rate limiting to login
5. Implement session timeout
6. Add HTTPS enforcement
7. Consider adding audit logging

---

## Deployment

### GitHub Actions Workflow

**File:** `.github/workflows/deploy.yml`

**Trigger:** Push to `main` branch or manual dispatch

**Process:**
1. SSH into RunCloud server using secrets
2. `cd` to deployment path
3. `git pull origin main`

**Required Secrets:**
- `SSH_HOST` - Server hostname/IP
- `SSH_USER` - SSH username
- `SSH_KEY` - Private SSH key
- `SSH_PORT` - SSH port (defaults to 22)
- `DEPLOY_PATH` - Absolute path on server

**Optional Post-Deploy Commands (commented out):**
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan cache:clear
```

---

## Database Connection

**Host:** `mysql.exactsite.com`
**Database:** `livewright`
**User:** `app_user_lw`

Connection established in `config.php` using PDO with exception error mode.

---

## Authentication System

### Admin Authentication Flow

1. User visits `/admin/` - redirected to `login.php` if not authenticated
2. Login form posts username/password
3. Credentials verified against `admin_users` table using `password_verify()`
4. On success: `$_SESSION['admin_logged_in'] = true` and `$_SESSION['admin_id']` set
5. All admin pages call `requireLogin()` which redirects unauthorized users

### Helper Functions (config.php)

```php
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
```

---

## URL Structure

| URL | Purpose |
|-----|---------|
| `/?uid=UNIQUE_ID` | Public plan view |
| `/next.php` (POST) | Checkout confirmation |
| `/admin/` | Admin dashboard |
| `/admin/login.php` | Admin login |
| `/admin/logout.php` | Admin logout |
| `/admin/edit.php` | Create new plan |
| `/admin/edit.php?id=X` | Edit existing plan |
| `/admin/edit.php?preset=X` | New plan from preset |
| `/admin/delete.php?id=X` | Delete plan |
| `/admin/presets.php` | List presets |
| `/admin/preset-edit.php` | Create preset |
| `/admin/preset-edit.php?id=X` | Edit preset |
| `/admin/preset-delete.php?id=X` | Delete preset |

---

## External Dependencies

| Dependency | Version | Source | Purpose |
|------------|---------|--------|---------|
| Quill.js | 1.3.7 | CDN (cdnjs) | Rich text editor |
| Quill Snow Theme | 1.3.7 | CDN (cdnjs) | Editor styling |

No server-side package managers (Composer/npm) appear to be in use based on the codebase.

---

## Claude Code Integration

The project uses Claude Code with symlinked shared configurations:

- **Agents:** Shared from parent Development directory
- **Hooks:** Shared from parent Development directory
- **Skills:** Shared from parent Development directory (QVIDEO, QKEAP, QDATA, QZOOM, etc.)

### Permissions (settings.local.json)

**Allowed:** Bash, Read, Write, Edit, Glob, Grep, WebFetch, WebSearch, Task, Skill, various MCP tools (Trello, Supabase, Tavily, AWS Billing)

**Ask confirmation:** Destructive bash commands (rm, rm -r, rm -rf, rmdir)

---

## Known Issues / Technical Debt

1. **Bug in delete.php:** Redirects to `admin.php` instead of `index.php`
2. **Hardcoded preset IDs:** Preset buttons in edit.php are hardcoded to IDs 1 and 2
3. **No input sanitization for rich text:** HTML from Quill is stored and displayed raw
4. **Credentials in source code:** Database password is in config.php
5. **Duplicate HTML closing tags:** `index.php` has duplicate `</tbody></table>` at bottom
6. **No CSRF protection:** Forms vulnerable to cross-site request forgery
7. **No rate limiting:** Login form has no brute-force protection

---

## Development Notes

### Adding a New Option

The system supports up to 3 options per plan. To extend this:
1. Add new columns to `contracts` table (`option_4_minimum_months`)
2. Update edit.php form loop (`for ($i = 1; $i <= 3; $i++)` to `<= 4`)
3. Update index.php display logic
4. Update preset tables and forms

### Pricing Calculation Logic

```javascript
// Quarterly: 3 months with 5% discount
const quarterly = (monthly * 3) * 0.95;

// Yearly: 12 months with 10% discount
const yearly = (monthly * 12) * 0.90;
```

This is implemented both server-side (index.php) and client-side (admin JavaScript).

---

## File Sizes

| File | Lines | Purpose |
|------|-------|---------|
| `admin/edit.php` | 731 | Largest file - complex form with Quill editors |
| `admin/preset-edit.php` | 554 | Preset management with similar complexity |
| `index.php` | 392 | Public-facing plan display |
| `next.php` | 177 | Checkout confirmation |
| `admin/index.php` | 166 | Admin dashboard |
| `admin/presets.php` | 79 | Preset list |
| `admin/login.php` | 57 | Login form |

---

## Recommended Improvements

### High Priority
1. Move database credentials to environment variables
2. Fix the delete.php redirect bug
3. Add CSRF tokens to all forms
4. Sanitize rich text HTML output

### Medium Priority
1. Add logging for admin actions
2. Implement session timeout
3. Add password reset functionality
4. Create database migration scripts

### Low Priority
1. Refactor to use a proper MVC structure
2. Add unit tests
3. Implement caching for frequently accessed plans
4. Add multi-language support

---

*Documentation generated: January 2026*
*Last code review: Based on commit 5691a0f*
