<?php
/**
 * Keap Campaign Report — core data logic.
 *
 * Keap REST v1 has no broadcast-stats endpoint, so we reconstruct broadcasts by
 * grouping /crm/rest/v1/emails rows by (subject, sent_from, sent_date-day).
 */

require_once(__DIR__ . '/../keap_api.php');
require_once(__DIR__ . '/keap_cache.php');

const KCR_EMAILS_TTL    = 900;   // 15 min
const KCR_CONTACT_TTL   = 3600;  // 1 hour
const KCR_EMAILS_PAGE   = 1000;

/* ---------------------- HTTP helpers ---------------------- */

function kcr_keap_get($url) {
    $token = get_keap_token();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log("kcr_keap_get $url -> $code: $resp");
        return null;
    }
    return json_decode($resp, true);
}

/* ---------------------- Email list pull ---------------------- */

function kcr_fetch_emails_since($since_iso) {
    $cache_key = 'kcr:emails:since:' . $since_iso;
    $cached = kcache_get($cache_key);
    if ($cached !== null) return $cached;

    $all = [];
    $offset = 0;
    $max_pages = 20; // safety net -> 20k rows
    for ($i = 0; $i < $max_pages; $i++) {
        $url = 'https://api.infusionsoft.com/crm/rest/v1/emails'
             . '?since=' . urlencode($since_iso)
             . '&limit=' . KCR_EMAILS_PAGE
             . '&offset=' . $offset
             . '&ordered=true';
        $data = kcr_keap_get($url);
        if (!$data || !isset($data['emails'])) break;
        $batch = $data['emails'];
        if (empty($batch)) break;
        $all = array_merge($all, $batch);
        if (count($batch) < KCR_EMAILS_PAGE) break;
        $offset += KCR_EMAILS_PAGE;
    }

    kcache_put($cache_key, $all, KCR_EMAILS_TTL);
    return $all;
}

/* ---------------------- Broadcast grouping ---------------------- */

function kcr_broadcast_key($subject, $sent_from, $sent_date) {
    $day = substr((string)$sent_date, 0, 10);
    return substr(hash('sha1', strtolower(trim($subject)) . '|' . strtolower(trim($sent_from)) . '|' . $day), 0, 16);
}

function kcr_list_broadcasts($days = 90) {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $emails = kcr_fetch_emails_since($since);

    $groups = [];
    foreach ($emails as $e) {
        if (empty($e['sent_date'])) continue;
        $key = kcr_broadcast_key($e['subject'] ?? '', $e['sent_from_address'] ?? '', $e['sent_date']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key'         => $key,
                'subject'     => $e['subject'] ?? '(no subject)',
                'sent_from'   => $e['sent_from_address'] ?? '',
                'sent_date'   => substr($e['sent_date'], 0, 10),
                'recipients'  => 0,
                'opens'       => 0,
                'clicks'      => 0,
                'earliest'    => $e['sent_date'],
            ];
        }
        $groups[$key]['recipients']++;
        if (!empty($e['opened_date']))  $groups[$key]['opens']++;
        if (!empty($e['clicked_date'])) $groups[$key]['clicks']++;
        if ($e['sent_date'] < $groups[$key]['earliest']) $groups[$key]['earliest'] = $e['sent_date'];
    }

    // Exclude one-off sends — a "broadcast" is >= 10 recipients.
    $broadcasts = array_values(array_filter($groups, function ($g) {
        return $g['recipients'] >= 10;
    }));

    usort($broadcasts, function ($a, $b) {
        return strcmp($b['earliest'], $a['earliest']);
    });
    return $broadcasts;
}

function kcr_get_broadcast_recipients($broadcast_key, $days = 90) {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $emails = kcr_fetch_emails_since($since);
    $rows = [];
    foreach ($emails as $e) {
        $k = kcr_broadcast_key($e['subject'] ?? '', $e['sent_from_address'] ?? '', $e['sent_date'] ?? '');
        if ($k === $broadcast_key) $rows[] = $e;
    }
    return $rows;
}

/* ---------------------- Contact hydration ---------------------- */

function kcr_hydrate_contacts($contact_ids) {
    $token = get_keap_token();
    $contact_ids = array_values(array_unique(array_filter(array_map('intval', $contact_ids))));
    $out = [];

    // Pull from cache first.
    $to_fetch = [];
    foreach ($contact_ids as $id) {
        $cached = kcache_get("kcr:contact:$id");
        if ($cached !== null) {
            $out[$id] = $cached;
        } else {
            $to_fetch[] = $id;
        }
    }
    if (empty($to_fetch)) return $out;

    // curl_multi in batches of 10.
    foreach (array_chunk($to_fetch, 10) as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $id) {
            $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$id}?optional_properties=custom_fields";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $token",
                    "Content-Type: application/json",
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $id => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 200) {
                $data = json_decode(curl_multi_getcontent($ch), true);
                if ($data) {
                    // Also pull tags for this contact in a second pass — kept out
                    // of the inner hot loop for rate-limit safety. Tags are
                    // hydrated lazily via kcr_hydrate_contact_tags when needed.
                    $out[$id] = $data;
                    kcache_put("kcr:contact:$id", $data, KCR_CONTACT_TTL);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

function kcr_hydrate_contact_tags($contact_ids) {
    $token = get_keap_token();
    $out = [];
    foreach (array_chunk($contact_ids, 10) as $batch) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $id) {
            $cached = kcache_get("kcr:tags:$id");
            if ($cached !== null) {
                $out[$id] = $cached;
                continue;
            }
            $url = "https://api.infusionsoft.com/crm/rest/v1/contacts/{$id}/tags?limit=100";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer $token",
                    "Content-Type: application/json",
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        if (empty($handles)) continue;

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active && $status === CURLM_OK);

        foreach ($handles as $id => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 200) {
                $data = json_decode(curl_multi_getcontent($ch), true);
                $tag_ids = [];
                if (!empty($data['tags'])) {
                    foreach ($data['tags'] as $t) {
                        if (isset($t['tag']['id']))     $tag_ids[] = (int)$t['tag']['id'];
                        elseif (isset($t['id']))        $tag_ids[] = (int)$t['id'];
                    }
                }
                $out[$id] = $tag_ids;
                kcache_put("kcr:tags:$id", $tag_ids, KCR_CONTACT_TTL);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

/* ---------------------- Classification ---------------------- */

function kcr_classify_domain($email, $consumer_domains) {
    $email = strtolower(trim((string)$email));
    if (!strpos($email, '@')) return 'unknown';
    $domain = substr($email, strpos($email, '@') + 1);

    if (in_array($domain, $consumer_domains, true)) return 'consumer';

    // Non-.com/.net/.org/.edu/.gov/.io/.co = international signal.
    $us_tlds = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'io', 'co', 'us'];
    $parts = explode('.', $domain);
    $tld = end($parts);
    if (!in_array($tld, $us_tlds, true)) return 'professional'; // still professional, but flagged international elsewhere

    return 'professional';
}

function kcr_is_international($email) {
    $email = strtolower(trim((string)$email));
    if (!strpos($email, '@')) return false;
    $domain = substr($email, strpos($email, '@') + 1);
    $us_tlds = ['com', 'net', 'org', 'edu', 'gov', 'mil', 'io', 'co', 'us'];
    $parts = explode('.', $domain);
    $tld = end($parts);
    return !in_array($tld, $us_tlds, true);
}

function kcr_is_test_junk($email, $patterns) {
    foreach ($patterns as $re) {
        if (preg_match($re, $email)) return true;
    }
    return false;
}

function kcr_org_from_email($email) {
    if (!strpos($email, '@')) return '';
    $domain = substr($email, strpos($email, '@') + 1);
    // Drop TLD for readable label.
    $parts = explode('.', $domain);
    if (count($parts) >= 2) {
        return ucfirst($parts[count($parts) - 2]) . '.' . end($parts);
    }
    return $domain;
}

function kcr_contact_name($contact, $fallback_email = '') {
    $first = trim($contact['given_name'] ?? '');
    $last  = trim($contact['family_name'] ?? '');
    $name = trim("$first $last");
    return $name !== '' ? $name : $fallback_email;
}

/* ---------------------- Report builder ---------------------- */

function kcr_build_report($broadcast_key, $config) {
    $rows = kcr_get_broadcast_recipients($broadcast_key);
    if (empty($rows)) {
        return ['error' => 'No broadcast found for key ' . $broadcast_key];
    }

    $first = $rows[0];
    $broadcast = [
        'key'       => $broadcast_key,
        'subject'   => $first['subject'] ?? '',
        'sent_from' => $first['sent_from_address'] ?? '',
        'sent_date' => substr($first['sent_date'] ?? '', 0, 10),
    ];

    $consumer_domains = $config['consumer_email_domains'] ?? [];
    $test_patterns    = $config['test_email_patterns']    ?? [];
    $vip_tag_map      = $config['vip_tag_ids']            ?? [];

    // Aggregate.
    $delivered = count($rows);
    $opens = 0;
    $clicks = 0;
    foreach ($rows as $r) {
        if (!empty($r['opened_date']))  $opens++;
        if (!empty($r['clicked_date'])) $clicks++;
    }
    $unopened = $delivered - $opens;

    // Segmentation.
    $seg = [
        'consumer'     => ['size' => 0, 'opens' => 0, 'clicks' => 0],
        'professional' => ['size' => 0, 'opens' => 0, 'clicks' => 0],
    ];
    foreach ($rows as $r) {
        $cls = kcr_classify_domain($r['sent_to_address'] ?? '', $consumer_domains);
        if (!isset($seg[$cls])) $seg[$cls] = ['size' => 0, 'opens' => 0, 'clicks' => 0];
        $seg[$cls]['size']++;
        if (!empty($r['opened_date']))  $seg[$cls]['opens']++;
        if (!empty($r['clicked_date'])) $seg[$cls]['clicks']++;
    }

    // Hydrate contacts for clickers + any tagged-VIP lookups.
    $clicker_ids = [];
    foreach ($rows as $r) if (!empty($r['clicked_date']) && !empty($r['contact_id'])) $clicker_ids[] = (int)$r['contact_id'];

    $all_ids = [];
    foreach ($rows as $r) if (!empty($r['contact_id'])) $all_ids[] = (int)$r['contact_id'];
    $all_ids = array_values(array_unique($all_ids));

    // For performance, only fully hydrate clickers + non-openers who might be VIPs
    // (all of them, but contact endpoint is cached). Skip hydration entirely if
    // no VIP tags configured — then we only need clickers.
    $want_vip = !empty(array_filter($vip_tag_map));
    $hydrate_ids = $want_vip ? $all_ids : array_values(array_unique($clicker_ids));
    $contacts = kcr_hydrate_contacts($hydrate_ids);

    $tags_by_id = [];
    if ($want_vip) {
        $tags_by_id = kcr_hydrate_contact_tags($hydrate_ids);
    }

    // Build clicker list.
    $clickers = [];
    foreach ($rows as $r) {
        if (empty($r['clicked_date'])) continue;
        $cid = (int)($r['contact_id'] ?? 0);
        $contact = $cid ? ($contacts[$cid] ?? null) : null;
        $email = $r['sent_to_address'] ?? '';
        $clickers[] = [
            'contact_id'   => $cid,
            'name'         => $contact ? kcr_contact_name($contact, $email) : $email,
            'email'        => $email,
            'organization' => kcr_org_from_email($email),
            'clicked_at'   => $r['clicked_date'],
            'opened_at'    => $r['opened_date'] ?? null,
        ];
    }

    // Hygiene.
    $hygiene = [
        'missing_name'  => [],
        'all_caps_name' => [],
        'duplicates'    => [],
        'test_junk'     => [],
        'international' => [],
        'opt_outs'      => [],
    ];

    // Duplicates: same email, >1 contact_id in the row set.
    $email_to_ids = [];
    foreach ($rows as $r) {
        $e = strtolower($r['sent_to_address'] ?? '');
        $cid = (int)($r['contact_id'] ?? 0);
        if ($e === '' || $cid === 0) continue;
        if (!isset($email_to_ids[$e])) $email_to_ids[$e] = [];
        if (!in_array($cid, $email_to_ids[$e], true)) $email_to_ids[$e][] = $cid;
    }
    foreach ($email_to_ids as $e => $ids) {
        if (count($ids) > 1) $hygiene['duplicates'][] = ['email' => $e, 'contact_ids' => $ids];
    }

    foreach ($rows as $r) {
        $email = $r['sent_to_address'] ?? '';
        $cid = (int)($r['contact_id'] ?? 0);
        if ($email === '') continue;

        if (kcr_is_test_junk($email, $test_patterns)) {
            $hygiene['test_junk'][] = ['email' => $email, 'contact_id' => $cid];
        }
        if (kcr_is_international($email)) {
            $hygiene['international'][] = ['email' => $email, 'contact_id' => $cid];
        }

        $contact = $cid ? ($contacts[$cid] ?? null) : null;
        if ($contact) {
            $first = trim($contact['given_name'] ?? '');
            if ($first === '') {
                $hygiene['missing_name'][] = ['email' => $email, 'contact_id' => $cid];
            } elseif ($first === strtoupper($first) && preg_match('/[A-Z]/', $first) && strlen($first) > 1) {
                $hygiene['all_caps_name'][] = ['email' => $email, 'contact_id' => $cid, 'name' => $first];
            }

            $status = strtolower($contact['email_status'] ?? '');
            if (in_array($status, ['optout', 'nonmarketable', 'unengagedmarketable'], true) && strpos($status, 'opt') !== false) {
                $hygiene['opt_outs'][] = ['email' => $email, 'contact_id' => $cid, 'status' => $contact['email_status']];
            }
        }
    }

    // VIP callouts.
    $vip = [];
    foreach ($vip_tag_map as $label => $tag_id) {
        $tag_id = (int)$tag_id;
        if ($tag_id <= 0) continue;
        $vip[$label] = [];
        foreach ($rows as $r) {
            $cid = (int)($r['contact_id'] ?? 0);
            if (!$cid) continue;
            $contact_tags = $tags_by_id[$cid] ?? [];
            if (!in_array($tag_id, $contact_tags, true)) continue;
            $contact = $contacts[$cid] ?? null;
            $email = $r['sent_to_address'] ?? '';
            $vip[$label][] = [
                'contact_id' => $cid,
                'name'       => $contact ? kcr_contact_name($contact, $email) : $email,
                'email'      => $email,
                'opened'     => !empty($r['opened_date']),
                'clicked'    => !empty($r['clicked_date']),
            ];
        }
    }

    // Top professional openers (non-clickers) as a triage list.
    $top_pro_openers = [];
    foreach ($rows as $r) {
        if (empty($r['opened_date']) || !empty($r['clicked_date'])) continue;
        $email = $r['sent_to_address'] ?? '';
        if (kcr_classify_domain($email, $consumer_domains) !== 'professional') continue;
        $cid = (int)($r['contact_id'] ?? 0);
        $contact = $cid ? ($contacts[$cid] ?? null) : null;
        $top_pro_openers[] = [
            'contact_id'   => $cid,
            'name'         => $contact ? kcr_contact_name($contact, $email) : $email,
            'email'        => $email,
            'organization' => kcr_org_from_email($email),
            'opened_at'    => $r['opened_date'],
        ];
    }
    // Limit to 50 for readability.
    $top_pro_openers = array_slice($top_pro_openers, 0, 50);

    return [
        'broadcast'   => $broadcast,
        'metrics' => [
            'delivered'  => $delivered,
            'opens'      => $opens,
            'open_rate'  => $delivered ? round($opens / $delivered, 4) : 0,
            'clicks'     => $clicks,
            'click_rate' => $delivered ? round($clicks / $delivered, 4) : 0,
            'unopened'   => $unopened,
            'opt_outs'   => count($hygiene['opt_outs']),
        ],
        'segmentation' => [
            'consumer' => [
                'size'       => $seg['consumer']['size'],
                'opens'      => $seg['consumer']['opens'],
                'open_rate'  => $seg['consumer']['size'] ? round($seg['consumer']['opens'] / $seg['consumer']['size'], 4) : 0,
                'clicks'     => $seg['consumer']['clicks'],
                'click_rate' => $seg['consumer']['size'] ? round($seg['consumer']['clicks'] / $seg['consumer']['size'], 4) : 0,
            ],
            'professional' => [
                'size'       => $seg['professional']['size'],
                'opens'      => $seg['professional']['opens'],
                'open_rate'  => $seg['professional']['size'] ? round($seg['professional']['opens'] / $seg['professional']['size'], 4) : 0,
                'clicks'     => $seg['professional']['clicks'],
                'click_rate' => $seg['professional']['size'] ? round($seg['professional']['clicks'] / $seg['professional']['size'], 4) : 0,
            ],
        ],
        'clickers'                 => $clickers,
        'top_openers_professional' => $top_pro_openers,
        'hygiene'                  => $hygiene,
        'vip_callouts'             => $vip,
        'generated_at'             => gmdate('c'),
    ];
}
