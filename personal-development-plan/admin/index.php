<?php
require_once '../config.php';
requireLogin();

// ---- Tab filter ----
$validStatuses = ['all', 'pending', 'signed', 'archived'];
$status = $_GET['status'] ?? 'all';
if (!in_array($status, $validStatuses, true)) {
    $status = 'all';
}

// ---- Sort ----
// Whitelist of sortable columns -> SQL expression. Default to date modified DESC
// so the most-recently-edited plans surface first.
$sortMap = [
    'modified'   => 'c.updated_at',
    'created'    => 'c.created_at',
    'first_name' => 'c.first_name',
    'last_name'  => 'c.last_name',
    'email'      => 'c.email',
    'status'     => 'c.signed',
];
$sort = $_GET['sort'] ?? 'modified';
if (!isset($sortMap[$sort])) $sort = 'modified';
$dir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderExpr = $sortMap[$sort] . ' ' . $dir . ', c.id DESC';

// ---- Counts (drive tab badges) ----
// "all/pending/signed" tabs exclude archived rows; the Archived tab shows
// exactly those rows (and still excludes soft-deleted trash).
$counts = ['all' => 0, 'pending' => 0, 'signed' => 0, 'archived' => 0];
$countStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) AS total,
        SUM(CASE WHEN archived_at IS NULL AND signed = 1 THEN 1 ELSE 0 END) AS signed_count,
        SUM(CASE WHEN archived_at IS NULL AND (signed = 0 OR signed IS NULL) THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_count
    FROM contracts
    WHERE deleted_at IS NULL
");
if ($row = $countStmt->fetch(PDO::FETCH_ASSOC)) {
    $counts['all'] = (int)$row['total'];
    $counts['signed'] = (int)$row['signed_count'];
    $counts['pending'] = (int)$row['pending_count'];
    $counts['archived'] = (int)$row['archived_count'];
}

// ---- Contracts query for the active tab ----
$where = 'c.deleted_at IS NULL';
if ($status === 'archived') {
    $where .= ' AND c.archived_at IS NOT NULL';
} else {
    $where .= ' AND c.archived_at IS NULL';
    if ($status === 'signed') {
        $where .= ' AND c.signed = 1';
    } elseif ($status === 'pending') {
        $where .= ' AND (c.signed = 0 OR c.signed IS NULL)';
    }
}

$stmt = $pdo->prepare("
    SELECT c.id, c.unique_id, c.first_name, c.last_name, c.email, c.signed, c.created_at, c.updated_at,
           c.selected_option_id, c.agreement_pdf_path, c.agreement_email_sent_at,
           c.archived_at, c.archive_note,
           po.option_number AS selected_option_number,
           po.sub_option_name AS selected_sub_option_name,
           po.type AS selected_type,
           po.price AS selected_price,
           (
               SELECT COALESCE(SUM(p.amount), 0)
               FROM payments p
               WHERE p.contract_id = c.id AND p.status = 'succeeded'
           ) AS total_paid,
           (
               SELECT MIN(p.created_at)
               FROM payments p
               WHERE p.contract_id = c.id AND p.status = 'succeeded'
           ) AS first_payment_at
    FROM contracts c
    LEFT JOIN pricing_options po ON po.id = c.selected_option_id
    WHERE $where
    ORDER BY $orderExpr
");
$stmt->execute();
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Dashboard';
require_once 'includes/header.php';

$tabs = [
    'all'      => 'View All',
    'pending'  => 'Pending',
    'signed'   => 'Signed',
    'archived' => 'Archived',
];
?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Personal Development Plan Management</h1>
            <div>
                <a href="edit.php" class="btn">Add New Plan</a>
            </div>
        </div>

        <?php if (!empty($_GET['deleted'])): ?>
            <div class="alert alert-success">Plan moved to Trash. <a href="trash.php">View Trash</a>.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['archived'])): ?>
            <div class="alert alert-success">
                Archived <?= (int)$_GET['archived'] ?> plan<?= (int)$_GET['archived'] === 1 ? '' : 's' ?>.
                <a href="?status=archived">View Archived</a>.
            </div>
        <?php endif; ?>
        <?php if (!empty($_GET['unarchived'])): ?>
            <div class="alert alert-success">
                Restored <?= (int)$_GET['unarchived'] ?> plan<?= (int)$_GET['unarchived'] === 1 ? '' : 's' ?> from archive.
            </div>
        <?php endif; ?>

        <nav class="tab-nav" role="tablist" aria-label="Filter plans">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="?status=<?= urlencode($key) ?>&sort=<?= urlencode($sort) ?>&dir=<?= urlencode(strtolower($dir)) ?>"
                   class="<?= $status === $key ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $status === $key ? 'true' : 'false' ?>">
                    <span><?= htmlspecialchars($label) ?></span>
                    <span class="tab-count"><?= (int)$counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php
        // Helper: build a sort header link that toggles direction when re-clicking the active column.
        $sortLink = function ($key, $label) use ($sort, $dir, $status) {
            $isActive = ($sort === $key);
            $nextDir  = ($isActive && $dir === 'DESC') ? 'asc' : 'desc';
            $arrow    = $isActive ? ($dir === 'DESC' ? ' ▼' : ' ▲') : '';
            $url = '?status=' . urlencode($status) . '&sort=' . urlencode($key) . '&dir=' . $nextDir;
            return '<a href="' . htmlspecialchars($url) . '" style="color:inherit;text-decoration:none;">'
                . htmlspecialchars($label) . $arrow . '</a>';
        };
        ?>
        <!-- Bulk action bar (hidden until a row is checked) -->
        <form id="bulkForm" method="POST" action="archive.php" style="margin: 10px 0;">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <div id="bulkBar" style="display:none; padding: 10px 14px; background: #eaf3fb; border: 1px solid #b6d4ed; border-radius: 6px; align-items: center; gap: 12px;">
                <span><strong id="bulkCount">0</strong> selected</span>
                <?php if ($status === 'archived'): ?>
                    <button type="submit" name="action" value="unarchive" class="btn">Restore selected</button>
                <?php else: ?>
                    <button type="button" class="btn" onclick="openArchiveModal()">Archive selected…</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="clearSelection()">Clear</button>
            </div>

        <table>
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                    <th><?= $sortLink('first_name', 'First Name') ?></th>
                    <th><?= $sortLink('last_name', 'Last Name') ?></th>
                    <th><?= $sortLink('email', 'Email') ?></th>
                    <th><?= $sortLink('created', 'Date Created') ?></th>
                    <th><?= $sortLink('modified', 'Date Modified') ?></th>
                    <th><?= $sortLink('status', 'Status') ?></th>
                    <th>Selected Plan</th>
                    <th>Initial Paid</th>
                    <th>Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contracts)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; color: var(--ink-500); padding: 32px 0;">
                            <?php
                            switch ($status) {
                                case 'signed':   echo 'No signed plans yet.'; break;
                                case 'pending':  echo 'No pending plans.'; break;
                                case 'archived': echo 'No archived plans.'; break;
                                default:         echo 'No plans found.';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($contracts as $contract): ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int)$contract['id'] ?>" form="bulkForm" onchange="updateBulkBar()"></td>
                        <td>
                            <?= htmlspecialchars($contract['first_name']) ?>
                            <?php if (!empty($contract['archived_at'])): ?>
                                <?php $note = trim((string)($contract['archive_note'] ?? '')); ?>
                                <div style="font-size: 0.78em; color: var(--ink-500); margin-top: 4px;">
                                    Archived <?= date('M j, Y', strtotime($contract['archived_at'])) ?>
                                    <?php if ($note !== ''): ?>
                                        <span title="<?= htmlspecialchars($note) ?>" style="cursor: help; text-decoration: underline dotted;">· note</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($note !== ''): ?>
                                    <div style="font-size: 0.78em; color: var(--ink-500); margin-top: 2px; max-width: 220px; white-space: normal;">
                                        <em><?= htmlspecialchars($note) ?></em>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($contract['last_name']) ?></td>
                        <td><?= htmlspecialchars($contract['email']) ?></td>
                        <td class="date-cell"><?= date('M j, Y', strtotime($contract['created_at'])) ?></td>
                        <td class="date-cell">
                            <?= !empty($contract['updated_at']) ? date('M j, Y', strtotime($contract['updated_at'])) : '—' ?>
                        </td>
                        <td>
                            <?php if ($contract['signed']): ?>
                                <span class="status-pill is-signed">Signed</span>
                            <?php else: ?>
                                <span class="status-pill is-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="selected-plan-cell">
                            <?php if (!empty($contract['selected_option_id'])): ?>
                                <div style="font-size: 0.9em;">
                                    <strong>Option <?= (int)$contract['selected_option_number'] ?></strong>
                                    <?php if (!empty($contract['selected_sub_option_name']) && $contract['selected_sub_option_name'] !== 'Default'): ?>
                                        — <?= htmlspecialchars($contract['selected_sub_option_name']) ?>
                                    <?php endif; ?>
                                    <div style="color: var(--ink-500); font-size: 0.92em; margin-top: 2px;">
                                        $<?= number_format((float)$contract['selected_price'], 2) ?>
                                        / <?= htmlspecialchars($contract['selected_type']) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="unsigned">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((float)$contract['total_paid'] > 0): ?>
                                <div style="font-weight:600; color: #248a3d;">
                                    $<?= number_format((float)$contract['total_paid'], 2) ?>
                                </div>
                                <?php if (!empty($contract['first_payment_at'])): ?>
                                    <div style="font-size: 0.8em; color: var(--ink-500); margin-top: 2px;">
                                        <?= date('M j, Y', strtotime($contract['first_payment_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="unsigned">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="link-cell">
                            <div class="link-actions" style="display: flex; flex-direction: column; gap: 6px;">
                                <div style="display: flex; gap: 4px; align-items: center;">
                                    <span style="font-size: 0.8em; color: var(--ink-500); min-width: 55px;">Classic:</span>
                                    <a href="https://checkout.livewright.com/personal-development-plan/?uid=<?= urlencode($contract['unique_id']) ?>" target="_blank" class="link-btn">View</a>
                                    <button onclick="copyLink('<?= addslashes($contract['unique_id']) ?>', 'classic')" class="copy-btn" id="copy-classic-<?= $contract['id'] ?>">Copy</button>
                                </div>
                                <div style="display: flex; gap: 4px; align-items: center;">
                                    <span style="font-size: 0.8em; color: var(--ink-500); min-width: 55px;">Simple:</span>
                                    <a href="https://checkout.livewright.com/personal-development-plan/?uid=<?= urlencode($contract['unique_id']) ?>&skin=simple" target="_blank" class="link-btn">View</a>
                                    <button onclick="copyLink('<?= addslashes($contract['unique_id']) ?>', 'simple')" class="copy-btn" id="copy-simple-<?= $contract['id'] ?>">Copy</button>
                                </div>
                            </div>
                        </td>
                        <td class="actions">
                            <a href="edit.php?id=<?= $contract['id'] ?>" class="btn">Edit</a>
                            <?php if (!empty($contract['agreement_pdf_path'])): ?>
                                <a href="../agreement.php?uid=<?= urlencode($contract['unique_id']) ?>" target="_blank" class="btn">Agreement PDF</a>
                            <?php endif; ?>
                            <?php if (!empty($contract['archived_at'])): ?>
                                <button type="button" class="btn" onclick="quickUnarchive(<?= (int)$contract['id'] ?>)">Restore</button>
                            <?php else: ?>
                                <button type="button" class="btn" onclick="quickArchive(<?= (int)$contract['id'] ?>)">Archive</button>
                            <?php endif; ?>
                            <a href="delete.php?id=<?= $contract['id'] ?>" class="btn btn-danger">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </form>
    </div>

    <!-- Archive Modal -->
    <div id="archiveModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: #fff; border-radius: 8px; max-width: 480px; width: 90%; padding: 24px;">
            <h3 style="margin-top: 0;">Archive plan<span id="archiveModalCount"></span>?</h3>
            <p style="color: var(--ink-500); font-size: 14px;">Archived plans are hidden from the main list. They stay searchable under the Archived tab and can be restored at any time.</p>
            <form id="archiveModalForm" method="POST" action="archive.php">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                <div id="archiveModalIds"></div>
                <label style="display:block; font-weight:600; margin-bottom: 6px;">Note (optional):</label>
                <textarea name="archive_note" rows="3" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="e.g., Client decided to delay enrollment; revisit in Q3"></textarea>
                <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeArchiveModal()">Cancel</button>
                    <button type="submit" class="btn">Archive</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function copyLink(uniqueId, skin) {
        skin = skin || 'classic';
        let url = `https://checkout.livewright.com/personal-development-plan/?uid=${uniqueId}`;
        if (skin === 'simple') url += '&skin=simple';

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(() => {
                showCopySuccess(uniqueId, skin);
            }).catch(() => {
                fallbackCopyTextToClipboard(url, uniqueId, skin);
            });
        } else {
            fallbackCopyTextToClipboard(url, uniqueId, skin);
        }
    }

    function fallbackCopyTextToClipboard(text, uniqueId, skin) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            document.execCommand('copy');
            showCopySuccess(uniqueId, skin);
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
        }

        document.body.removeChild(textArea);
    }

    // ---- Bulk select + archive UI ----
    function getCheckedIds() {
        return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
    }
    function updateBulkBar() {
        const ids = getCheckedIds();
        const bar = document.getElementById('bulkBar');
        const countEl = document.getElementById('bulkCount');
        if (ids.length === 0) {
            bar.style.display = 'none';
        } else {
            bar.style.display = 'flex';
            countEl.textContent = ids.length;
        }
    }
    function toggleSelectAll(cb) {
        document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
        updateBulkBar();
    }
    function clearSelection() {
        document.querySelectorAll('.row-check').forEach(c => c.checked = false);
        const sa = document.getElementById('selectAll');
        if (sa) sa.checked = false;
        updateBulkBar();
    }
    function openArchiveModal() {
        const ids = getCheckedIds();
        if (ids.length === 0) return;
        const container = document.getElementById('archiveModalIds');
        container.innerHTML = ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('');
        document.getElementById('archiveModalCount').textContent = ids.length > 1 ? ` (${ids.length})` : '';
        const modal = document.getElementById('archiveModal');
        modal.style.display = 'flex';
    }
    function closeArchiveModal() {
        document.getElementById('archiveModal').style.display = 'none';
    }
    function quickArchive(id) {
        clearSelection();
        const cb = document.querySelector(`.row-check[value="${id}"]`);
        if (cb) cb.checked = true;
        updateBulkBar();
        openArchiveModal();
    }
    function quickUnarchive(id) {
        if (!confirm('Restore this plan to the active list?')) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = 'archive.php';
        f.innerHTML = `
            <input type="hidden" name="action" value="unarchive">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <input type="hidden" name="ids[]" value="${id}">
        `;
        document.body.appendChild(f);
        f.submit();
    }
    // Close modal on background click
    document.getElementById('archiveModal').addEventListener('click', function(e) {
        if (e.target === this) closeArchiveModal();
    });

    function showCopySuccess(uniqueId, skin) {
        let targetButton = null;
        document.querySelectorAll('.copy-btn').forEach(button => {
            const onclick = button.getAttribute('onclick') || '';
            if (onclick.includes(uniqueId) && onclick.includes(`'${skin}'`)) {
                targetButton = button;
            }
        });

        if (targetButton) {
            const originalText = targetButton.textContent;
            targetButton.textContent = 'Copied!';
            targetButton.classList.add('copied');

            setTimeout(() => {
                targetButton.textContent = originalText;
                targetButton.classList.remove('copied');
            }, 2000);
        }
    }
    </script>

<?php require_once 'includes/footer.php'; ?>
