<?php
declare(strict_types=1);

/**
 * Ollama's latest stable GitHub release, for the public version compatibility
 * probe. This is NOT a claim about the installed Ollama binary (this proxy
 * fronts remote cloud models). The release is cached to avoid hitting GitHub
 * on each VS Code connection.
 */
function proxy_ollama_release_version($tag): ?string
{
    if (!is_string($tag)) return null;
    $tag = trim($tag);
    return preg_match('/^v?([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $matches)
        ? $matches[1] : null;
}

function proxy_fetch_latest_ollama_release(): ?string
{
    if (!function_exists('curl_init')) return null;

    $ch = curl_init('https://api.github.com/repos/ollama/ollama/releases/latest');
    if (!$ch) return null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: MediaPitch-Ollama-Proxy',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($httpStatus !== 200 || !is_string($response) || strlen($response) > 512 * 1024) {
        return null;
    }

    $release = json_decode($response, true);
    if (!is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
        return null;
    }
    return proxy_ollama_release_version($release['tag_name'] ?? null);
}

function proxy_cached_latest_ollama_version(?callable $fetchRelease = null): string
{
    // Last verified stable version, used only when GitHub and the cache are
    // unavailable. Keep this baseline updated when changing compatibility.
    $fallback = '0.40.3';
    $path = proxy_data_dir() . '/ollama-latest-stable.json';
    $now = time();

    $readCache = static function () use ($path): array {
        $json = @file_get_contents($path);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    };
    $freshVersion = static function (array $cache, int $now): ?string {
        $version = proxy_ollama_release_version($cache['version'] ?? null);
        $checkedAt = (int)($cache['checked_at'] ?? 0);
        $ttl = ($cache['verified'] ?? false) === true ? 21600 : 300;
        return $version !== null && $checkedAt > 0
            && $checkedAt <= $now && ($now - $checkedAt) < $ttl
            ? $version : null;
    };

    $cache = $readCache();
    if (($fresh = $freshVersion($cache, $now)) !== null) return $fresh;

    // A nonblocking lock prevents concurrent requests from all fetching the
    // release at the same time; concurrent callers use the last known value.
    $handle = @fopen($path . '.lock', 'c');
    if (!$handle) return proxy_ollama_release_version($cache['version'] ?? null) ?? $fallback;
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return proxy_ollama_release_version($cache['version'] ?? null) ?? $fallback;
    }

    try {
        $cache = $readCache();
        if (($fresh = $freshVersion($cache, $now)) !== null) return $fresh;

        try {
            $latest = proxy_ollama_release_version(
                $fetchRelease ? $fetchRelease() : proxy_fetch_latest_ollama_release()
            );
        } catch (Throwable $ignored) {
            $latest = null;
        }

        $version = $latest ?? proxy_ollama_release_version($cache['version'] ?? null) ?? $fallback;
        $snapshot = json_encode([
            'version' => $version,
            'checked_at' => $now,
            'verified' => $latest !== null,
        ]);
        if (is_string($snapshot)) {
            // Write atomically. Readers must never see a half-written cache.
            $temporary = @tempnam(proxy_data_dir(), 'ollama-release-');
            if (is_string($temporary)) {
                if (@file_put_contents($temporary, $snapshot, LOCK_EX) !== false) {
                    @rename($temporary, $path);
                }
                @unlink($temporary);
            }
        }

        return $version;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
