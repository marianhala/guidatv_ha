<?php
/**
 * api/tmdb.php
 *
 * Proxy verso TMDB per recuperare poster/trama di un programma TV.
 * La API key vive solo in config.php lato server: non è mai visibile
 * nel browser (a differenza della versione originale, che la teneva in
 * localStorage e la mandava in chiaro nell'URL di ogni chiamata TMDB).
 *
 * GET ?title=Nome+Programma
 * Risposta JSON: {"poster": "https://...", "overview": "...", "type": "tv|movie"}
 * oppure {"poster": null} se non trovato / API key non configurata.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

header('Content-Type: application/json; charset=UTF-8');

$config = require __DIR__ . '/config.php';

$title = trim((string) ($_GET['title'] ?? ''));
if ($title === '') {
    echo json_encode(['poster' => null]);
    exit;
}
if (mb_strlen($title) > 200) {
    $title = mb_substr($title, 0, 200);
}

$apiKey = $config['tmdb_api_key'];
if (!$apiKey || $apiKey === 'INSERISCI_QUI_LA_TUA_TMDB_API_KEY') {
    // Chiave non configurata: niente poster, ma la guida TV resta funzionante.
    echo json_encode(['poster' => null, 'reason' => 'tmdb_not_configured']);
    exit;
}

$cacheDir = $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheKey = 'tmdb_' . md5(mb_strtolower($title));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$ttl = (int) $config['tmdb_cache_ttl'];

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    echo (string) file_get_contents($cacheFile);
    exit;
}

function tmdbSearch(string $endpoint, string $apiKey, string $title, int $timeout): ?array
{
    $url = sprintf(
        'https://api.themoviedb.org/3/search/%s?api_key=%s&query=%s&language=it-IT',
        $endpoint,
        urlencode($apiKey),
        urlencode($title)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

$timeout = (int) $config['tmdb_fetch_timeout'];
$result = ['poster' => null];

$tv = tmdbSearch('tv', $apiKey, $title, $timeout);
$best = $tv['results'][0] ?? null;
if ($best && !empty($best['poster_path'])) {
    $result = [
        'poster' => 'https://image.tmdb.org/t/p/w185' . $best['poster_path'],
        'overview' => $best['overview'] ?? '',
        'type' => 'tv',
    ];
} else {
    $movie = tmdbSearch('movie', $apiKey, $title, $timeout);
    $bestMovie = $movie['results'][0] ?? null;
    if ($bestMovie && !empty($bestMovie['poster_path'])) {
        $result = [
            'poster' => 'https://image.tmdb.org/t/p/w185' . $bestMovie['poster_path'],
            'overview' => $bestMovie['overview'] ?? '',
            'type' => 'movie',
        ];
    }
}

$json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile . '.tmp', $json);
@rename($cacheFile . '.tmp', $cacheFile);
echo $json;
