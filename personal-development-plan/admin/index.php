<?php
require_once '../config.php';
requireLogin();

// Get all contracts
$stmt = $pdo->prepare("
    SELECT id, unique_id, first_name, last_name, email, signed, created_at
    FROM contracts
    WHERE deleted_at IS NULL
    ORDER BY id DESC
");
$stmt->execute();
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Dashboard';
require_once 'includes/header.php';
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

        <table>
            <thead>
                <tr>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Email</th>
                    <th>Date Created</th>
                    <th>Signed</th>
                    <th>Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contracts)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center;">No plans found</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($contracts as $contract): ?>
                    <tr>
                        <td><?= htmlspecialchars($contract['first_name']) ?></td>
                        <td><?= htmlspecialchars($contract['last_name']) ?></td>
                        <td><?= htmlspecialchars($contract['email']) ?></td>
                        <td class="date-cell"><?= date('M j, Y', strtotime($contract['created_at'])) ?></td>
                        <td>
                            <?php if ($contract['signed']): ?>
                                <span class="signed-icon">✓</span>
                            <?php else: ?>
                                <span class="unsigned">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="link-cell">
                            <div class="link-actions" style="display: flex; flex-direction: column; gap: 6px;">
                                <div style="display: flex; gap: 4px; align-items: center;">
                                    <span style="font-size: 0.8em; color: #666; min-width: 55px;">Classic:</span>
                                    <a href="https://checkout.livewright.com/personal-development-plan/?uid=<?= urlencode($contract['unique_id']) ?>" target="_blank" class="link-btn">View</a>
                                    <button onclick="copyLink('<?= addslashes($contract['unique_id']) ?>', 'classic')" class="copy-btn" id="copy-classic-<?= $contract['id'] ?>">Copy</button>
                                </div>
                                <div style="display: flex; gap: 4px; align-items: center;">
                                    <span style="font-size: 0.8em; color: #666; min-width: 55px;">Simple:</span>
                                    <a href="https://checkout.livewright.com/personal-development-plan/?uid=<?= urlencode($contract['unique_id']) ?>&skin=simple" target="_blank" class="link-btn">View</a>
                                    <button onclick="copyLink('<?= addslashes($contract['unique_id']) ?>', 'simple')" class="copy-btn" id="copy-simple-<?= $contract['id'] ?>">Copy</button>
                                </div>
                            </div>
                        </td>
                        <td class="actions">
                            <a href="edit.php?id=<?= $contract['id'] ?>" class="btn">Edit</a>
                            <a href="delete.php?id=<?= $contract['id'] ?>" class="btn btn-danger">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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

    function showCopySuccess(uniqueId, skin) {
        const selector = skin === 'simple'
            ? `[id^="copy-simple-"]`
            : `[id^="copy-classic-"]`;
        const buttons = document.querySelectorAll(`.copy-btn${selector}, .copy-btn`);
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
