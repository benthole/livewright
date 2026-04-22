<?php
/**
 * Keap SQLite cache wrapper.
 *
 * Uses the existing keap_cache.db at the repo root. Provides simple get/put with TTL.
 */

function kcache_db() {
    static $db = null;
    if ($db !== null) return $db;

    $path = dirname(__DIR__, 2) . '/keap_cache.db';
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS cache (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        expires_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        size_bytes INTEGER DEFAULT 0,
        access_count INTEGER DEFAULT 0,
        last_accessed INTEGER DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_expires ON cache(expires_at)");

    return $db;
}

function kcache_get($key) {
    try {
        $db = kcache_db();
        $stmt = $db->prepare("SELECT value, expires_at FROM cache WHERE key = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['expires_at'] <= time()) return null;

        $upd = $db->prepare("UPDATE cache SET access_count = access_count + 1, last_accessed = :t WHERE key = :k");
        $upd->execute(['t' => time(), 'k' => $key]);

        return json_decode($row['value'], true);
    } catch (Exception $e) {
        error_log('kcache_get error: ' . $e->getMessage());
        return null;
    }
}

function kcache_put($key, $value, $ttl_seconds) {
    try {
        $db = kcache_db();
        $json = json_encode($value);
        $now = time();
        $stmt = $db->prepare("INSERT OR REPLACE INTO cache (key, value, expires_at, created_at, size_bytes, access_count, last_accessed)
                              VALUES (:k, :v, :exp, :created, :sz, 0, :created)");
        $stmt->execute([
            'k' => $key,
            'v' => $json,
            'exp' => $now + (int)$ttl_seconds,
            'created' => $now,
            'sz' => strlen($json),
        ]);
        return true;
    } catch (Exception $e) {
        error_log('kcache_put error: ' . $e->getMessage());
        return false;
    }
}

function kcache_delete($key) {
    try {
        $db = kcache_db();
        $stmt = $db->prepare("DELETE FROM cache WHERE key = :k");
        $stmt->execute(['k' => $key]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function kcache_purge_expired() {
    try {
        $db = kcache_db();
        $db->prepare("DELETE FROM cache WHERE expires_at <= :t")->execute(['t' => time()]);
    } catch (Exception $e) {
        // swallow
    }
}
