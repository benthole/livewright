<?php
/**
 * Shared UI system for the LiveWright Roster admin console.
 *
 * Single source of truth for the "calm modern workbench" design system
 * (see roster/DESIGN.md and roster/PRODUCT.md). Provides:
 *   - roster_ui_styles()  : design tokens + base + shared component CSS
 *   - roster_ui_topbar()  : the shared top navigation bar (with Tools menu)
 *   - roster_ui_menu_js() : tiny self-contained dropdown toggle for the Tools menu
 *
 * All colors are OKLCH. No CDN framework. Purely presentational: it never
 * touches roster behavior, the filter/sort/modal JS, or the $quarterEnabled guards.
 */

if (!function_exists('roster_ui_styles')) {

/**
 * Emit the shared design tokens, base reset, and component styles.
 * Safe to include on every page; the token block is idempotent.
 */
function roster_ui_styles() {
    ?>
    <style>
    /* ============================================================
       LiveWright Roster — design tokens (see DESIGN.md)
       ============================================================ */
    :root {
        /* Canvas & surface */
        --bg:            oklch(0.975 0.004 85);
        --surface:       oklch(0.995 0.003 85);
        --surface-sunk:  oklch(0.965 0.004 85);
        --surface-raise: oklch(0.998 0.002 85);

        /* Ink */
        --ink:       oklch(0.29 0.008 75);
        --ink-soft:  oklch(0.46 0.007 75);
        --ink-faint: oklch(0.60 0.006 75);

        /* Lines */
        --line:        oklch(0.915 0.004 80);
        --line-strong: oklch(0.86 0.005 80);

        /* Accent — deep petrol teal (reserved "act here" color) */
        --accent:       oklch(0.52 0.078 205);
        --accent-hover: oklch(0.46 0.082 205);
        --accent-weak:  oklch(0.955 0.018 205);
        --accent-ink:   oklch(0.40 0.085 205);
        --focus:        oklch(0.62 0.10 205);

        /* Semantic tags */
        --tag-individual-bg: oklch(0.95 0.02 205); --tag-individual-ink: oklch(0.40 0.08 205);
        --tag-group-bg:      oklch(0.95 0.03 155); --tag-group-ink:      oklch(0.40 0.08 155);
        --tag-eteam-bg:      oklch(0.95 0.03 300); --tag-eteam-ink:      oklch(0.42 0.10 300);
        --tag-neutral-bg:    oklch(0.93 0.004 80); --tag-neutral-ink:    var(--ink);

        /* Feedback */
        --ok:      oklch(0.52 0.09 150);
        --ok-bg:   oklch(0.95 0.03 150);
        --warn-bg: oklch(0.95 0.055 85);  --warn-ink: oklch(0.42 0.06 70);
        --danger:  oklch(0.53 0.15 27);   --danger-hover: oklch(0.47 0.16 27);
        --danger-bg: oklch(0.955 0.03 27); --danger-ink: oklch(0.45 0.13 27);

        /* Radii */
        --r-sm: 6px;
        --r-md: 8px;
        --r-lg: 10px;

        /* Elevation */
        --shadow-sm: 0 1px 2px rgb(from var(--ink) r g b / 0.06);
        --shadow-md: 0 8px 24px rgb(from var(--ink) r g b / 0.14);

        /* Motion */
        --ease: cubic-bezier(0.22, 1, 0.36, 1);
        --dur:  140ms;

        --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* ============================================================ base */
    .rui *, .rui *::before, .rui *::after { box-sizing: border-box; }

    body.rui {
        margin: 0;
        font-family: var(--font);
        background: var(--bg);
        color: var(--ink);
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
        line-height: 1.45;
    }

    .rui-container {
        max-width: 1440px;
        margin: 24px auto;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .rui-page { padding: 0 20px 40px; }

    /* ============================================================ top bar */
    .rui-topbar {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 13px 24px;
        background: var(--surface);
        border-bottom: 1px solid var(--line);
        flex-wrap: wrap;
    }

    .rui-brand {
        display: inline-flex;
        align-items: baseline;
        gap: 8px;
        text-decoration: none;
        color: var(--ink);
        white-space: nowrap;
    }
    .rui-brand:hover { text-decoration: none; color: var(--ink); }
    .rui-brand__mark {
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.02em;
        color: var(--accent-ink);
    }
    .rui-brand__page {
        font-size: 20px;
        font-weight: 600;
        letter-spacing: -0.01em;
    }
    .rui-brand__sep { color: var(--ink-faint); font-weight: 400; }

    .rui-state-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-left: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        background: var(--warn-bg);
        color: var(--warn-ink);
    }

    .rui-spacer { flex: 1 1 auto; }

    .rui-topbar__center {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-variant-numeric: tabular-nums;
    }

    .rui-nav {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    /* nav links / buttons share one quiet neutral resting state */
    .rui-navlink {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 11px;
        border-radius: var(--r-sm);
        font-size: 13px;
        font-weight: 500;
        color: var(--ink-soft);
        background: transparent;
        border: 1px solid transparent;
        text-decoration: none;
        cursor: pointer;
        transition: background var(--dur) var(--ease), color var(--dur) var(--ease);
    }
    .rui-navlink:hover {
        background: var(--surface-sunk);
        color: var(--ink);
        text-decoration: none;
    }
    .rui-navlink.is-active {
        color: var(--accent-ink);
        background: var(--accent-weak);
    }
    .rui-navlink__caret { font-size: 10px; color: var(--ink-faint); }

    /* Tools dropdown */
    .rui-menu { position: relative; }
    .rui-menu__panel {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        min-width: 210px;
        background: var(--surface-raise);
        border: 1px solid var(--line);
        border-radius: var(--r-md);
        box-shadow: var(--shadow-md);
        padding: 6px;
        z-index: 4000;
        display: none;
    }
    .rui-menu.is-open .rui-menu__panel { display: block; }
    .rui-menu__label {
        padding: 6px 10px 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--ink-faint);
    }
    .rui-menu__item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 10px;
        border-radius: var(--r-sm);
        font-size: 13.5px;
        color: var(--ink);
        text-decoration: none;
        transition: background var(--dur) var(--ease);
    }
    .rui-menu__item:hover { background: var(--surface-sunk); color: var(--ink); text-decoration: none; }
    .rui-menu__item.is-active { color: var(--accent-ink); background: var(--accent-weak); }
    .rui-menu__item.is-active::after { content: "•"; margin-left: auto; color: var(--accent); }
    .rui-menu__glyph { width: 18px; text-align: center; opacity: 0.8; }
    .rui-menu__sep { height: 1px; background: var(--line); margin: 6px 4px; }

    /* user chip */
    .rui-user {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding-left: 8px;
        margin-left: 2px;
        border-left: 1px solid var(--line);
    }
    .rui-user__name { font-size: 13px; color: var(--ink-soft); white-space: nowrap; }
    .rui-role {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 10.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .rui-role--viewer { background: var(--tag-neutral-bg); color: var(--ink-soft); }
    .rui-role--editor { background: var(--tag-group-bg);   color: var(--tag-group-ink); }
    .rui-role--admin  { background: var(--accent-weak);    color: var(--accent-ink); }

    /* ============================================================ buttons */
    .rui-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 16px;
        border-radius: var(--r-sm);
        border: 1px solid transparent;
        font-family: inherit;
        font-size: 14px;
        font-weight: 600;
        line-height: 1;
        cursor: pointer;
        text-decoration: none;
        transition: background var(--dur) var(--ease), border-color var(--dur) var(--ease), color var(--dur) var(--ease);
    }
    .rui-btn:hover { text-decoration: none; }
    .rui-btn--primary { background: var(--accent); color: oklch(0.99 0.003 85); }
    .rui-btn--primary:hover { background: var(--accent-hover); color: oklch(0.99 0.003 85); }
    .rui-btn--secondary { background: var(--surface); color: var(--ink); border-color: var(--line-strong); }
    .rui-btn--secondary:hover { background: var(--surface-sunk); }
    .rui-btn--danger { background: var(--danger); color: oklch(0.99 0.003 85); }
    .rui-btn--danger:hover { background: var(--danger-hover); color: oklch(0.99 0.003 85); }
    .rui-btn--ghost { background: transparent; color: var(--ink-soft); }
    .rui-btn--ghost:hover { background: var(--surface-sunk); color: var(--ink); }
    .rui-btn:disabled { opacity: 0.55; cursor: not-allowed; }

    /* ============================================================ inputs */
    .rui-input, .rui-select, .rui-textarea {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--line-strong);
        border-radius: var(--r-sm);
        background: var(--surface);
        color: var(--ink);
        font-family: inherit;
        font-size: 14px;
        transition: border-color var(--dur) var(--ease), box-shadow var(--dur) var(--ease);
    }
    .rui-input::placeholder { color: var(--ink-faint); }
    .rui-input:focus, .rui-select:focus, .rui-textarea:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgb(from var(--focus) r g b / 0.22);
    }

    /* ============================================================ badges / tags */
    .rui-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 9px;
        border-radius: var(--r-sm);
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
        background: var(--tag-neutral-bg);
        color: var(--tag-neutral-ink);
    }
    .rui-tag--individual { background: var(--tag-individual-bg); color: var(--tag-individual-ink); }
    .rui-tag--group      { background: var(--tag-group-bg);      color: var(--tag-group-ink); }
    .rui-tag--eteam      { background: var(--tag-eteam-bg);      color: var(--tag-eteam-ink); }

    /* ============================================================ notices */
    .rui-notice {
        padding: 11px 24px;
        text-align: center;
        font-size: 13px;
        font-weight: 500;
    }
    .rui-notice--warn { background: var(--warn-bg); color: var(--warn-ink); }
    .rui-notice--ok   { background: var(--ok-bg);   color: var(--ok); }
    .rui-notice--danger { background: var(--danger-bg); color: var(--danger-ink); }

    /* ============================================================ focus visibility (a11y) */
    .rui a:focus-visible,
    .rui button:focus-visible,
    .rui input:focus-visible,
    .rui select:focus-visible,
    .rui textarea:focus-visible,
    .rui [tabindex]:focus-visible {
        outline: 2px solid var(--focus);
        outline-offset: 2px;
        border-radius: var(--r-sm);
    }

    @media (prefers-reduced-motion: reduce) {
        .rui *, body.rui * { transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; }
    }

    @media (max-width: 720px) {
        .rui-container { margin: 0; border-radius: 0; border-left: 0; border-right: 0; }
        .rui-user__name { display: none; }
    }
    </style>
    <?php
}

/**
 * Render the shared top navigation bar.
 *
 * @param array $o {
 *   @type string $base       Relative path prefix to the roster root ('' for index, '../' for admin/*).
 *   @type string $active     Current surface key: roster|assign_teams|assign_quarters|organize_fields|users|reports.
 *   @type string $page_title Big title shown after the brand mark.
 *   @type string $state_chip Optional HTML/text for a state chip (e.g. dropped view). Escaped.
 *   @type string $center     Optional raw HTML slot (record count / refresh) — index only. Trusted caller markup.
 *   @type string $search     Optional raw HTML slot for the search control — index only. Trusted caller markup.
 *   @type array  $user       Logged-in user array with 'name' and 'role'.
 *   @type bool   $is_admin   Whether to show admin tools in the Tools menu.
 *   @type bool   $can_edit   Whether the user can edit (affects nothing structural; reserved).
 * }
 */
function roster_ui_topbar($o = []) {
    $base       = $o['base'] ?? '';
    $active     = $o['active'] ?? '';
    $pageTitle  = $o['page_title'] ?? 'Roster';
    $stateChip  = $o['state_chip'] ?? '';
    $center     = $o['center'] ?? '';
    $search     = $o['search'] ?? '';
    $user       = $o['user'] ?? null;
    $isAdmin    = !empty($o['is_admin']);

    $rootHref = $base === '' ? './' : $base;
    $reportsHref = $base . 'campaign_reports.php';
    $logoutHref  = $base . 'logout.php';

    // Admin tools shown in the Tools menu: label => [href, key, glyph]
    $tools = [
        ['label' => 'Assign Teams',    'href' => $base . 'admin/assign_teams.php',    'key' => 'assign_teams',    'glyph' => '⇄'],
        ['label' => 'Assign Quarters', 'href' => $base . 'admin/assign_quarters.php', 'key' => 'assign_quarters', 'glyph' => '◵'],
        ['label' => 'Organize Fields', 'href' => $base . 'admin/organize_fields.php', 'key' => 'organize_fields', 'glyph' => '☰'],
        ['label' => 'Manage Users',    'href' => $base . 'admin/users.php',           'key' => 'users',           'glyph' => '◐'],
    ];
    $toolsActive = in_array($active, ['assign_teams', 'assign_quarters', 'organize_fields', 'users'], true);
    ?>
    <header class="rui-topbar">
        <a class="rui-brand" href="<?php echo htmlspecialchars($rootHref); ?>" title="Back to roster">
            <span class="rui-brand__mark">LiveWright</span>
            <span class="rui-brand__sep">/</span>
            <span class="rui-brand__page"><?php echo htmlspecialchars($pageTitle); ?></span>
        </a>
        <?php if ($stateChip !== ''): ?>
            <span class="rui-state-chip"><?php echo htmlspecialchars($stateChip); ?></span>
        <?php endif; ?>

        <?php if ($search !== ''): ?><div class="rui-topbar__search"><?php echo $search; ?></div><?php endif; ?>

        <span class="rui-spacer"></span>

        <?php if ($center !== ''): ?><div class="rui-topbar__center"><?php echo $center; ?></div><?php endif; ?>

        <nav class="rui-nav" aria-label="Roster navigation">
            <a class="rui-navlink <?php echo $active === 'reports' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($reportsHref); ?>">Reports</a>

            <?php if ($isAdmin): ?>
            <div class="rui-menu" data-rui-menu>
                <button type="button" class="rui-navlink <?php echo $toolsActive ? 'is-active' : ''; ?>"
                        data-rui-menu-trigger aria-haspopup="true" aria-expanded="false">
                    Tools <span class="rui-navlink__caret" aria-hidden="true">▾</span>
                </button>
                <div class="rui-menu__panel" role="menu">
                    <div class="rui-menu__label">Bulk assign</div>
                    <?php foreach (array_slice($tools, 0, 2) as $t): ?>
                        <a class="rui-menu__item <?php echo $active === $t['key'] ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo htmlspecialchars($t['href']); ?>">
                            <span class="rui-menu__glyph" aria-hidden="true"><?php echo $t['glyph']; ?></span><?php echo htmlspecialchars($t['label']); ?>
                        </a>
                    <?php endforeach; ?>
                    <div class="rui-menu__sep"></div>
                    <div class="rui-menu__label">Administer</div>
                    <?php foreach (array_slice($tools, 2) as $t): ?>
                        <a class="rui-menu__item <?php echo $active === $t['key'] ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo htmlspecialchars($t['href']); ?>">
                            <span class="rui-menu__glyph" aria-hidden="true"><?php echo $t['glyph']; ?></span><?php echo htmlspecialchars($t['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($user): ?>
            <span class="rui-user">
                <span class="rui-user__name"><?php echo htmlspecialchars($user['name']); ?></span>
                <span class="rui-role rui-role--<?php echo htmlspecialchars($user['role']); ?>"><?php echo htmlspecialchars(ucfirst($user['role'])); ?></span>
            </span>
            <?php endif; ?>
            <a class="rui-navlink" href="<?php echo htmlspecialchars($logoutHref); ?>">Logout</a>
        </nav>
    </header>
    <?php
}

/**
 * Tiny self-contained dropdown toggle for the Tools menu.
 * Namespaced to [data-rui-menu]; does not touch any other page JS.
 */
function roster_ui_menu_js() {
    ?>
    <script>
    (function () {
        document.querySelectorAll('[data-rui-menu]').forEach(function (menu) {
            var trigger = menu.querySelector('[data-rui-menu-trigger]');
            if (!trigger) return;
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = menu.classList.toggle('is-open');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
        document.addEventListener('click', function () {
            document.querySelectorAll('[data-rui-menu].is-open').forEach(function (m) {
                m.classList.remove('is-open');
                var t = m.querySelector('[data-rui-menu-trigger]');
                if (t) t.setAttribute('aria-expanded', 'false');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('[data-rui-menu].is-open').forEach(function (m) {
                    m.classList.remove('is-open');
                    var t = m.querySelector('[data-rui-menu-trigger]');
                    if (t) { t.setAttribute('aria-expanded', 'false'); t.focus(); }
                });
            }
        });
    })();
    </script>
    <?php
}

} // end function_exists guard
