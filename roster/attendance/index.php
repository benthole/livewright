<?php
require_once('config.php');
$pdo = new PDO("mysql:host=$db_host_lw;dbname=$db_name_lw;charset=utf8mb4", $db_user_lw, $db_pass_lw);

$allContacts = $pdo->query("SELECT id, first_name, last_name, email FROM contact ORDER BY last_name, first_name")
                   ->fetchAll(PDO::FETCH_ASSOC);

// === Helper Functions ===
function findKnownAttendee($pdo, $email, $first_name, $last_name) {
    if ($email) {
        $stmt = $pdo->prepare("SELECT ka.*, c.keap_contact_id 
                               FROM known_attendee ka 
                               JOIN contact c ON ka.contact_id = c.id 
                               WHERE ka.email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT ka.*, c.keap_contact_id 
                               FROM known_attendee ka 
                               JOIN contact c ON ka.contact_id = c.id 
                               WHERE ka.first_name = ? AND ka.last_name = ?");
        $stmt->execute([$first_name, $last_name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function suggestMatches($pdo, $first_name, $last_name) {
    $stmt = $pdo->prepare("SELECT * FROM contact WHERE first_name = ? AND last_name = ?");
    $stmt->execute([$first_name, $last_name]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isSkipped($pdo, $email, $first_name, $last_name) {
    $first_name = strtolower(trim($first_name));
    $last_name = strtolower(trim($last_name));
    $email = $email ? strtolower(trim($email)) : null;

    if ($email) {
        $stmt = $pdo->prepare("SELECT * FROM skipped_attendee WHERE LOWER(email) = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) return true;
    }

    $stmt = $pdo->prepare("SELECT * FROM skipped_attendee WHERE LOWER(first_name) = ? AND LOWER(last_name) = ?");
    $stmt->execute([$first_name, $last_name]);
    return $stmt->fetch() ? true : false;
}

$entries = [];


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {

  $uploadedFilename = $_FILES['file']['name'];

$fh = fopen($_FILES['file']['tmp_name'], 'r');
$header = fgetcsv($fh); // row 1 = column names

$defaultMeetingName = 'LiveMORE';

// Now "push" Tracy Virtue's row back:
rewind($fh); // Rewind to the start
fgetcsv($fh); // Read header again to move pointer past header (but don't discard Tracy now)

    $nameCol = $emailCol = null;
    foreach ($header as $i => $col) {
        $lc = strtolower($col);
        if ($nameCol === null && strpos($lc, 'name') !== false) $nameCol = $i;
        if ($emailCol === null && strpos($lc, 'email') !== false) $emailCol = $i;
    }
    $nameCol = $nameCol ?? 0;
    $emailCol = $emailCol ?? 1;

   $meetingDate = null;
   $seen = [];
   while ($row = fgetcsv($fh)) {
    $rawName  = trim($row[$nameCol] ?? '');
    $rawEmail = trim($row[$emailCol] ?? '');
    $email    = filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? strtolower($rawEmail) : null;

    $nameParts = explode(' ', $rawName, 2);
    $first_name = $nameParts[0] ?? '';
    $last_name  = $nameParts[1] ?? '';


    if (!$first_name || !$last_name) continue;

    // Deduplication key: name + email
    $key = strtolower(trim($first_name)) . '|' . strtolower(trim($last_name)) . '|' . ($email ?? '');

    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    // Skip check
    $isSkipped = isSkipped($pdo, $email, $first_name, $last_name);
    if ($isSkipped) continue;

    $known = findKnownAttendee($pdo, $email, $first_name, $last_name);
    $suggestions = !$known ? suggestMatches($pdo, $first_name, $last_name) : [];

    $entries[] = [
        'name' => $rawName,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'known' => $known,
        'suggestions' => $suggestions
    ];

$dateCol = 2; // column index 2 = 3rd column

// Set meeting date from the first valid row
if (!$meetingDate && !empty($row[$dateCol])) {
    $rawDate = trim($row[$dateCol]);
    $timestamp = strtotime($rawDate);
    if ($timestamp) {
        $meetingDate = date('Y-m-d', $timestamp);
    }
}

$cohortStmt = $pdo->query("SELECT name FROM cohorts ORDER BY name");
$cohorts = $cohortStmt->fetchAll(PDO::FETCH_COLUMN);

}
    fclose($fh);
}

if (!$meetingDate) {
    $meetingDate = date('Y-m-d'); // default to today
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Match Participants</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
            margin: 0;
            padding: 2rem;
        }
        .container {
            max-width: 900px;
            margin: auto;
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        h1 {
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
        }
        .upload-box {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 400px;
            margin: 3rem auto;
        }
        input[type="file"] {
            display: block;
            margin: 1rem auto;
        }
        button, .btn {
            background: #3b82f6;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            margin: 0.2rem;
        }
        button:hover, .btn:hover {
            background: #2563eb;
        }
        .btn.matched { background: #28a745; }
        .btn.skip-always { background: #f59e0b; }
        .btn.skip-once { background: #9ca3af; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th, td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
        }
        th {
            background: #f3f4f6;
            font-weight: 600;
        }
        tr:nth-child(even) { background: #f9fafb; }

.badge-matched {
    display: inline-block;
    padding: 0.4rem 0.75rem;
    border: 2px solid #28a745;
    border-radius: 6px;
    color: #28a745;
    background-color: #ffffff;
    font-size: 0.9rem;
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
}

    </style>
    <script>
        function acceptMatch(rowId, contactId, firstName, lastName, email) {
            fetch('save_match.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contact_id: contactId, first_name: firstName, last_name: lastName, email: email })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const btn = document.getElementById('btn-' + rowId);
                    btn.textContent = 'Matched';
                    btn.classList.add('matched');
                    btn.disabled = true;
                } else {
                    alert('Error saving match');
                }
            });
			row.setAttribute('data-status', 'matched');
			checkAllRowsResolved();
        }

        function skipAlways(rowId, firstName, lastName, email) {
            fetch('skip_always.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ first_name: firstName, last_name: lastName, email: email })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('row-' + rowId).style.display = 'none';
                } else {
                    alert('Error skipping');
                }
            });

		row.setAttribute('data-status', 'skipped');
		checkAllRowsResolved();
        }

	function skipThisTime(rowId) {
		const row = document.getElementById('row-' + rowId); // ✅ define the row
		row.style.display = 'none';
		row.setAttribute('data-status', 'skipped');
		checkAllRowsResolved();
		console.log(`Row ${rowId} marked as skipped (this time only).`);
	}
    </script>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

</head>
<body>

<?php if (empty($entries)): ?>
    <div class="upload-box">
        <h1>Upload Your Zoom CSV</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="file" accept=".csv" required>
            <br>
            <button type="submit">Upload & Preview</button>
        </form>
    </div>
<?php else: ?>
    <div class="container">
        <h1>Match Participants</h1>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Known</th>
                    <th>Suggestions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $index => $entry): ?>

<?php $initialStatus = $entry['known'] ? 'matched' : 'pending'; ?>
<tr id="row-<?= $index ?>"
    data-first-name="<?= htmlspecialchars($entry['first_name']) ?>"
    data-last-name="<?= htmlspecialchars($entry['last_name']) ?>"
    data-email="<?= htmlspecialchars($entry['email']) ?>"
    data-status="<?= $initialStatus ?>"
    <?php if ($entry['known']): ?>
      data-keap-id="<?= htmlspecialchars($entry['known']['keap_contact_id']) ?>"
    <?php endif; ?>
>
                        <td><?= htmlspecialchars($entry['name']) ?></td>
                        <td><?= htmlspecialchars($entry['email'] ?? '—') ?></td>
                        <td><?= $entry['known'] ? 'Yes (Keap ID: ' . $entry['known']['keap_contact_id'] . ')' : 'No' ?></td>
                        <td>
<?php
    // Safely encode values for JS
    $jsFirst = htmlspecialchars(json_encode($entry['first_name']));
    $jsLast  = htmlspecialchars(json_encode($entry['last_name']));
    $jsEmail = htmlspecialchars(json_encode($entry['email']));
?>
<?php if (!$entry['known'] && !empty($entry['suggestions'])): ?>
    <?php foreach ($entry['suggestions'] as $suggestion): 
        $jsContactId = htmlspecialchars(json_encode($suggestion['id']));
        $suggestedEmail = htmlspecialchars($suggestion['email'] ?? 'No Email');
    ?>
        <?php
$fullSuggestedName = htmlspecialchars($suggestion['first_name'] . ' ' . $suggestion['last_name']);
?>
<button id="btn-<?= $index ?>" 
        class="btn" 
        onclick="acceptMatch('<?= $index ?>', <?= $jsContactId ?>, <?= $jsFirst ?>, <?= $jsLast ?>, <?= $jsEmail ?>)">
    Accept <?= $fullSuggestedName ?> – <?= $suggestedEmail ?> (Keap ID: <?= $suggestion['keap_contact_id'] ?>)
</button><br>
    <?php endforeach; ?>
    <button class="btn skip-always" onclick="skipAlways('<?= $index ?>', <?= $jsFirst ?>, <?= $jsLast ?>, <?= $jsEmail ?>)">Skip (Always)</button>
    <button class="btn skip-once" onclick="skipThisTime('<?= $index ?>')">Skip (This time)</button>
<?php elseif (!$entry['known']): ?>
<?php
$selectId = "select-" . $index;
?>
No suggestions<br>

<select id="<?= $selectId ?>" class="contact-select" style="width: 100%; max-width: 400px;">
  <option value="">Select a contact to match…</option>
  <?php foreach ($allContacts as $c): 
    $label = htmlspecialchars(
      "{$c['first_name']} {$c['last_name']}" 
      . ($c['email'] ? " ({$c['email']})" : '')
    );
  ?>
    <option value="<?= $c['id'] ?>"><?= $label ?></option>
  <?php endforeach; ?>
</select>
<br>

<button class="btn" onclick="assignManualMatch('<?= $index ?>')">Match Selected</button>
<button class="btn skip-always" onclick="skipAlways('<?= $index ?>', <?= $jsFirst ?>, <?= $jsLast ?>, <?= $jsEmail ?>)">Skip (Always)</button>
<button class="btn skip-once" onclick="skipThisTime('<?= $index ?>')">Skip (This time)</button>
<?php else: ?>
    <span class="badge-matched">Already matched</span>
<?php endif; ?>
</td>                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="container" style="margin-top: 2rem;">
  <div style="font-size: 1rem; font-weight: 600; color: #374151;">
    ✅ Uploaded File: <span style="color: #1f2937;"><?= htmlspecialchars($uploadedFilename ?? 'Unknown') ?></span>
  </div>
</div>

<!-- Attendance Tagging Form -->
<div class="container" style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #ccc;">

  <h2>Mark Attendance in Keap</h2>
  <form id="attendance-form" onsubmit="return false;">
    <label for="meeting_name">Meeting Name:</label><br>
    <input type="text" 
       id="meeting_name" 
       name="meeting_name" 
       placeholder="e.g. Team Training – May 16" 
       value="<?= htmlspecialchars($defaultMeetingName) ?>" 
       style="width: 100%; padding: 8px;" 
       required>
    <br><br>

    <label for="cohort_name">Cohort:</label><br>
<select id="cohort_name" name="cohort_name" style="width: 200px; padding: 8px;">
  <option value="">— None —</option>
  <?php foreach ($cohorts as $c): ?>
    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
  <?php endforeach; ?>
</select>
<br><br>

    <label for="meeting_date">Meeting Date:</label><br>
    <input type="date" id="meeting_date" name="meeting_date" value="<?= $meetingDate ?>" style="width: 200px; padding: 8px;" required>
    <br><br>

    <button type="button" 
        id="mark-btn" 
        class="btn" 
        style="cursor: not-allowed; opacity: 0.6;" 
        disabled 
        title="Please match or skip all participants first.">
  Mark Attendance
</button>

  </form>

  <div id="attendance-result" style="margin-top: 1rem;"></div>
</div>

<div style="text-align: center; margin-top: 2rem;">
  <form method="post">
    <button type="submit" class="btn" style="background-color: #6b7280;">Return to Upload Screen</button>
  </form>
</div>

<?php endif; ?>





<script>
$(document).ready(function () {
    $('.contact-select').select2();
});

function assignManualMatch(rowId) {
    const select = document.getElementById('select-' + rowId);
    const contactId = select.value;
    if (!contactId) return alert("Please select a contact to match.");

    const row = document.getElementById('row-' + rowId);
    const firstName = row.dataset.firstName;
    const lastName = row.dataset.lastName;
    const email = row.dataset.email;

    fetch('save_match.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            contact_id: contactId,
            first_name: firstName,
            last_name: lastName,
            email: email
        })
    })
    .then(r => r.json())
    .then(data => {
    if (data.success) {
      const cells = row.getElementsByTagName('td');
      const knownCell = cells[cells.length - 2];
      const suggestionsCell = cells[cells.length - 1];

      knownCell.innerHTML = '&nbsp;';
      suggestionsCell.innerHTML = '<span class="badge-matched">Matched manually</span>';
      
      row.setAttribute('data-status', 'matched');
      row.setAttribute('data-keap-id', contactId);
      checkAllRowsResolved();
    } else {
      alert("Error saving manual match.");
    }
})

}

function markAttendance() {

  const name = document.getElementById('meeting_name').value.trim();
  const date = document.getElementById('meeting_date').value;
  const cohort = document.getElementById('cohort_name').value.trim(); // NEW

const resultDiv = document.getElementById('attendance-result');

  const contactIds = Array.from(document.querySelectorAll('tr[data-status="matched"]'))
  .map(row => parseInt(row.getAttribute('data-keap-id')))
  .filter(id => !isNaN(id));
  
  // ✅ Now you can log it
  console.log("Mark Attendance clicked");
  console.log("Sending request with:", { name, date, contactIds });

  if (!name || !date) {
    resultDiv.innerHTML = '<div style="color: red;">❌ Please enter both Meeting Name and Date.</div>';
    return;
  }


  fetch('mark_attendance.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
  name,
  date,
  cohort,
  contact_ids: contactIds
})
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      resultDiv.innerHTML = `<div style="color: green;">✔️ Tag "${data.tag_name}" applied to ${data.count} contact(s).</div>`;
    } else if (data.tag_exists) {
      const confirmed = confirm(`A tag named "${name}" already exists in Keap.\n\nUse this existing tag?`);
      if (confirmed) {
        fetch('mark_attendance.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, date, use_existing: true })
        })
        .then(res => res.json())
        .then(final => {
          if (final.success) {
            resultDiv.innerHTML = `<div style="color: green;">✔️ Existing tag "${final.tag_name}" applied to ${final.count} contact(s).</div>`;
          } else {
            resultDiv.innerHTML = `<div style="color: red;">❌ ${final.error}</div>`;
          }
        });
      } else {
        resultDiv.innerHTML = `<div style="color: orange;">⚠️ Tag not applied. Please rename or confirm usage.</div>`;
      }
    } else {
      resultDiv.innerHTML = `<div style="color: red;">❌ ${data.error}</div>`;
    }
  })
  .catch(err => {
    console.error("Fetch error:", err);
  });
}

function checkAllRowsResolved() {
  const btn = document.getElementById('mark-btn');
  if (!btn) return; // <-- ✅ ADD THIS to avoid error if button is missing

  const rows = document.querySelectorAll('tr[data-status]');
  const allDone = Array.from(rows).every(row => {
    const status = row.getAttribute('data-status');
    return status === 'matched' || status === 'skipped';
  });

  if (allDone) {
    btn.disabled = false;
    btn.style.cursor = 'pointer';
    btn.style.opacity = 1;
    btn.removeAttribute('title');
  } else {
    btn.disabled = true;
    btn.style.cursor = 'not-allowed';
    btn.style.opacity = 0.6;
    btn.setAttribute('title', 'Please match or skip all participants first.');
  }
}

document.addEventListener('DOMContentLoaded', () => {
  checkAllRowsResolved();
  const markBtn = document.getElementById('mark-btn');
  if (markBtn) {
    markBtn.addEventListener('click', markAttendance);
  }
});

</script>
</body>
</html>