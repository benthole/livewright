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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn { padding: 8px 16px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; display: inline-block; margin: 2px; }
        .btn-danger { background: #dc3545; }
        .btn-secondary { background: #6c757d; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f5f5f5; }
        .actions { white-space: nowrap; }
        .signed-icon { color: #28a745; font-size: 18px; text-align: center; }
        .unsigned { color: #dc3545; text-align: center; }
        .date-cell { white-space: nowrap; }
        .link-cell { text-align: center; }
        .link-btn { background: #17a2b8; color: white; padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 0.9em; margin-right: 5px; display: inline-block; }
        .link-btn:hover { background: #138496; color: white; }
        .copy-btn { background: #6c757d; color: white; padding: 6px 12px; border: none; border-radius: 4px; font-size: 0.9em; cursor: pointer; }
        .copy-btn:hover { background: #5a6268; }
        .copy-btn.copied { background: #28a745; }
        .link-actions { display: flex; justify-content: center; gap: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Personal Development Plan Management</h1>
        <div>
            <a href="edit.php" class="btn">Add New Plan</a>
            <a href="coaching-rates.php" class="btn btn-secondary">Coaching Rates</a>
            <a href="presets.php" class="btn btn-secondary">Manage Presets</a>
            <a href="logout.php" class="btn btn-secondary">Logout</a>
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

    <script>
    function copyLink(uniqueId) {
        const url = `https://checkout.livewright.com/personal-development-plan/?uid=${uniqueId}`;
        
        // Try using the modern clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(() => {
                showCopySuccess(uniqueId);
            }).catch(() => {
                fallbackCopyTextToClipboard(url, uniqueId);
            });
        } else {
            // Fallback for older browsers
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
        // Find the button by searching for the unique ID in onclick attribute
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
        </tbody>
    </table>
</body>
</html>