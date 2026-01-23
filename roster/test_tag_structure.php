<?php
// test_tag_structure.php - See what the tags endpoint actually returns

require_once('keap_api.php');

$tagId = 525;
$results = keap_get_contacts_by_tag($tagId, 5, 0);

echo "<h2>Testing Tag Endpoint Response Structure</h2>";
echo "<pre>";
print_r($results);
echo "</pre>";
