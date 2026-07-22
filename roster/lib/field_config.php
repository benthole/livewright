<?php
/**
 * Field organization config.
 *
 * Lets admins organize the Keap custom-field values shown in the roster UI
 * (grouping, ordering, hiding) without editing config.php. Organization is
 * stored in the `roster_field_options` table; config.php values are used only
 * to seed the first time a value is seen.
 *
 * Managed fields: Team/cohort (45), individual coach (137), group coach (49),
 * E-team role (73). Requires config.php and keap_api.php to be loaded.
 */

// Divider items are stored as rows whose value starts with this prefix.
// They render as non-selectable separator lines in the dropdowns.
const FC_DIVIDER_PREFIX = '__divider__';

function fc_is_divider($value) {
    return is_string($value) && strpos($value, FC_DIVIDER_PREFIX) === 0;
}

// The label shown for a plain-line divider in the dropdowns.
function fc_divider_label() {
    return '──────────';
}

// Display order + labels for the groups (shared across all managed fields).
function fc_groups() {
    return [
        'active'     => 'Active',
        'functional' => 'Functional',
        'inactive'   => 'Inactive',
    ];
}

/**
 * Definitions for each managed field, built from config.php globals.
 * Each: label, keap_field_id, multi (comma-separated values?), seed groups.
 */
function fc_field_defs() {
    global $cohort_field_id, $individual_coach_field_id, $group_coach_field_id,
           $eteam_role_field_id, $cohorts, $individual_coaches, $group_coaches,
           $quarter_field_id;

    $defs = [
        'cohort' => [
            'label'         => 'Team / Cohort',
            'keap_field_id' => $cohort_field_id,
            'multi'         => false,
            // Seed each config group into the matching organizer group.
            'seed'          => [
                'active'     => $cohorts['active'] ?? [],
                'functional' => $cohorts['functional'] ?? [],
                'inactive'   => $cohorts['inactive'] ?? [],
            ],
        ],
        'individual_coach' => [
            'label'         => 'Individual Coach',
            'keap_field_id' => $individual_coach_field_id,
            'multi'         => true, // field 137 holds comma-separated coaches
            'seed'          => ['active' => $individual_coaches ?? []],
        ],
        'group_coach' => [
            'label'         => 'Group Coach',
            'keap_field_id' => $group_coach_field_id,
            'multi'         => false,
            'seed'          => ['active' => $group_coaches ?? []],
        ],
        'eteam_role' => [
            'label'         => 'E-Team Role',
            'keap_field_id' => $eteam_role_field_id,
            'multi'         => false,
            'seed'          => [], // no predefined list; pulled from Keap/data
        ],
    ];

    // Quarter is optional: only managed once its Keap field id is configured
    // in config.php (server-only). Dormant otherwise.
    if (isset($quarter_field_id) && (int)$quarter_field_id > 0) {
        $defs['quarter'] = [
            'label'         => 'Quarter',
            'keap_field_id' => (int)$quarter_field_id,
            'multi'         => false,
            'seed'          => ['active' => ['Q1', 'Q2', 'Q3', 'Q4']],
        ];
    }

    return $defs;
}

/** Ensure the storage table exists. */
function fc_ensure_table($conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS roster_field_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        field_key VARCHAR(40) NOT NULL,
        value VARCHAR(191) NOT NULL,
        group_name VARCHAR(40) NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_field_value (field_key, value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * All known values for a field: Keap dropdown options (cached) + config seed
 * + values present in local roster data. Deduplicated, non-empty.
 * Pure-separator strings (only dashes) are excluded.
 */
function fc_all_values($conn, $fieldKey) {
    $defs = fc_field_defs();
    if (!isset($defs[$fieldKey])) return [];
    $def = $defs[$fieldKey];
    $fieldId = $def['keap_field_id'];

    $values = [];

    // Keap options (source of truth; cached).
    if (function_exists('keap_get_custom_field_options_cached')) {
        foreach (keap_get_custom_field_options_cached($fieldId) as $v) {
            $values[$v] = true;
        }
    }

    // Config seed.
    foreach ($def['seed'] as $groupValues) {
        foreach ($groupValues as $v) {
            $values[$v] = true;
        }
    }

    // Values present in local roster data.
    try {
        $stmt = $conn->query("SELECT data FROM roster");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['data'], true);
            if (empty($data['custom_fields'])) continue;
            foreach ($data['custom_fields'] as $field) {
                if ((int)($field['id'] ?? 0) !== (int)$fieldId) continue;
                $content = trim((string)($field['content'] ?? ''));
                if ($content === '') continue;
                $parts = $def['multi'] ? array_map('trim', explode(',', $content)) : [$content];
                foreach ($parts as $p) {
                    if ($p !== '') $values[$p] = true;
                }
            }
        }
    } catch (PDOException $e) {
        // ignore; Keap/config still provide values
    }

    // Drop pure-separator entries.
    $out = [];
    foreach (array_keys($values) as $v) {
        if (preg_match('/^-+$/', $v)) continue;
        $out[] = $v;
    }
    return $out;
}

/** Map value => seed group for a field (from config), for first-time placement. */
function fc_seed_group_map($fieldKey) {
    $defs = fc_field_defs();
    $map = [];
    if (!isset($defs[$fieldKey])) return $map;
    foreach ($defs[$fieldKey]['seed'] as $group => $vals) {
        foreach ($vals as $v) $map[$v] = $group;
    }
    return $map;
}

/**
 * Organized view of a field for the admin UI: every known value with its
 * stored group/order/hidden, backfilling unconfigured values from the config
 * seed (or 'active'). Returns a flat list ordered by (group order, sort_order).
 */
function fc_organized($conn, $fieldKey) {
    fc_ensure_table($conn);
    $groups = fc_groups();
    $seedMap = fc_seed_group_map($fieldKey);

    // Stored rows.
    $stored = [];
    $stmt = $conn->prepare("SELECT value, group_name, sort_order, hidden
                            FROM roster_field_options WHERE field_key = :k");
    $stmt->execute(['k' => $fieldKey]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored[$r['value']] = [
            'group'  => isset($groups[$r['group_name']]) ? $r['group_name'] : 'active',
            'order'  => (int)$r['sort_order'],
            'hidden' => (int)$r['hidden'] === 1,
        ];
    }

    $items = [];

    // Real values (from Keap/config/data), with stored or seed placement.
    foreach (fc_all_values($conn, $fieldKey) as $value) {
        if (isset($stored[$value])) {
            $item = $stored[$value] + ['value' => $value];
        } else {
            $g = $seedMap[$value] ?? 'active';
            $item = ['value' => $value, 'group' => $g, 'order' => 9999, 'hidden' => false];
        }
        $item['divider'] = false;
        $items[] = $item;
    }

    // Divider rows (stored only; not real values).
    foreach ($stored as $val => $meta) {
        if (!fc_is_divider($val)) continue;
        $items[] = [
            'value'   => $val,
            'group'   => $meta['group'],
            'order'   => $meta['order'],
            'hidden'  => false,
            'divider' => true,
        ];
    }

    // Stable sort by group display order, then sort_order, then value.
    $groupIndex = array_flip(array_keys($groups));
    usort($items, function ($a, $b) use ($groupIndex) {
        $ga = $groupIndex[$a['group']] ?? 99;
        $gb = $groupIndex[$b['group']] ?? 99;
        if ($ga !== $gb) return $ga <=> $gb;
        if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
        return strcasecmp($a['value'], $b['value']);
    });

    return $items;
}

/**
 * Persist organization for a field. $items is an ordered list of
 * ['value'=>..., 'group'=>..., 'hidden'=>bool]; sort_order is assigned from
 * array order within each group. Replaces all rows for the field.
 */
function fc_save($conn, $fieldKey, $items) {
    fc_ensure_table($conn);
    $groups = fc_groups();

    $conn->beginTransaction();
    try {
        $del = $conn->prepare("DELETE FROM roster_field_options WHERE field_key = :k");
        $del->execute(['k' => $fieldKey]);

        $ins = $conn->prepare("INSERT INTO roster_field_options
            (field_key, value, group_name, sort_order, hidden)
            VALUES (:k, :v, :g, :o, :h)");

        $perGroupOrder = [];
        $dividerCounter = 0;
        foreach ($items as $item) {
            $isDivider = !empty($item['divider']);
            if ($isDivider) {
                // Assign a unique token so multiple dividers don't collide on
                // the (field_key, value) unique key.
                $value = FC_DIVIDER_PREFIX . ':' . ($dividerCounter++);
            } else {
                $value = trim((string)($item['value'] ?? ''));
                if ($value === '') continue;
                // Guard: a real value must not spoof the divider token space.
                if (fc_is_divider($value)) continue;
            }
            $group = $item['group'] ?? 'active';
            if (!isset($groups[$group])) $group = 'active';
            $perGroupOrder[$group] = ($perGroupOrder[$group] ?? -1) + 1;
            $ins->execute([
                'k' => $fieldKey,
                'v' => $value,
                'g' => $group,
                'o' => $perGroupOrder[$group],
                'h' => (!$isDivider && !empty($item['hidden'])) ? 1 : 0,
            ]);
        }
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log('fc_save error: ' . $e->getMessage());
        return false;
    }
}

/**
 * For rendering a dropdown: visible (non-hidden) values grouped and ordered.
 * Returns [ groupLabel => [value, ...] ] preserving group + item order.
 * Empty groups are omitted.
 */
function fc_dropdown_groups($conn, $fieldKey) {
    $groups = fc_groups();
    $out = [];
    foreach ($groups as $label) $out[$label] = [];

    foreach (fc_organized($conn, $fieldKey) as $item) {
        if (!empty($item['hidden'])) continue;
        $label = $groups[$item['group']] ?? 'Active';
        $out[$label][] = $item['value'];
    }

    foreach ($out as $label => $vals) {
        if (empty($vals)) unset($out[$label]);
    }
    return $out;
}
