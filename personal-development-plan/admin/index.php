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
                            <div class="link-actions">
                                <a href="https://checkout.livewright.com/personal-development-plan/?uid=<?= urlencode($contract['unique_id']) ?>" target="_blank" class="link-btn">
                                    View
                                </a>
                                <button onclick="copyLink('<?= addslashes($contract['unique_id']) ?>')" class="copy-btn" id="copy-<?= $contract['id'] ?>">
                                    Copy
                                </button>
                            </div>
                        </td>
                        <td class="actions">
                            <a href="edit.php?id=<?= $contract['id'] ?>" class="btn">Edit</a>
                            <a href="delete.php?id=<?= $contract['id'] ?>" class="btn btn-danger" onclick="return confirm('Delete this plan?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    function copyLink(uniqueId) {
        const url = `https://checkout.livewright.com/personal-development-plan/?uid=${uniqueId}`;

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(() => {
                showCopySuccess(uniqueId);
            }).catch(() => {
                fallbackCopyTextToClipboard(url, uniqueId);
            });
        } else {
            fallbackCopyTextToClipboard(url, uniqueId);
        }
    }

    function fallbackCopyTextToClipboard(text, uniqueId) {
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
            showCopySuccess(uniqueId);
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
        }

        document.body.removeChild(textArea);
    }

    function showCopySuccess(uniqueId) {
        const buttons = document.querySelectorAll('.copy-btn');
        let targetButton = null;

        buttons.forEach(button => {
            if (button.getAttribute('onclick').includes(uniqueId)) {
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
