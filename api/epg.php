<?php
/**
 * api/epg.php
 *
 * Scarica l'EPG (XMLTV) lato server, lo mette in cache su disco e lo
 * restituisce al browser. In questo modo:
 *  - non ci sono più problemi di CORS (il fetch è server-to-server);
 *  - non serve appoggiarsi a proxy CORS pubblici di terze parti, spesso
 *    lenti, instabili o rate-limitati;
 *  - ogni visitatore del sito non forza un nuovo download del file XMLTV
 *    (che può pesare diversi MB): lo si scarica al massimo una volta ogni
 *    epg_cache_ttl secondi.
 *
 * Risposta:
 *  - 200 + XML  con header X-EPG-Source (host usato) e, se la cache è
 *    scaduta e nessuna fonte ha risposto, X-EPG-Stale: 1
 *  - 503 + JSON {"error":"..."} se non esiste nessuna cache utilizzabile
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$config = require __DIR__ . '/config.php';

$cacheDir = $config['cache_dir'];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$cacheFile = $cacheDir . '/epg.xml';
$cacheMetaFile = $cacheDir . '/epg.meta.json';
$lockFile = $cacheDir . '/epg.lock';

header('X-Content-Type-Options: nosniff');

/**
 * Sorgente personalizzata opzionale (?custom=...), impostabile dall'utente
 * nel pannello "Config" del frontend. Per sicurezza (evitare che il server
 * diventi un proxy verso la rete interna, cioè SSRF) accettiamo solo URL
 * http/https con un host pubblico risolvibile, escludendo IP privati/loopback.
 */
function sanitizeCustomUrl(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    $url = trim($url);
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    $host = $parts['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        // gethostbyname non risolve -> host non valido
        return null;
    }
    if (
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
    ) {
        return null; // IP privato, loopback o riservato: rifiutato
    }
    return $url;
}

$customUrl = sanitizeCustomUrl($_GET['custom'] ?? null);
$sources = $customUrl ? [$customUrl] : $config['epg_sources'];

function httpGet(string $url, int $timeout, string $userAgent): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_ENCODING => '', // gestisce automaticamente gzip/deflate
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml, */*'],
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return [false, null, "cURL: $error"];
    }
    if ($status < 200 || $status >= 300) {
        return [false, null, "HTTP $status"];
    }
    if ($body === false || $body === '' || strpos($body, '<programme') === false) {
        return [false, null, 'Contenuto non riconosciuto come XMLTV'];
    }
    return [true, $body, null];
}

function serveXml(string $xml, string $sourceLabel, bool $stale): void
{
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    header('X-EPG-Source: ' . $sourceLabel);
    if ($stale) {
        header('X-EPG-Stale: 1');
    }
    echo $xml;
    exit;
}

function fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$cacheTtl = (int) $config['epg_cache_ttl'];
$staleMaxAge = (int) $config['epg_stale_max_age'];

// 1) Cache fresca? Servila subito, senza toccare la rete.
if (!$customUrl && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $meta = json_decode((string) @file_get_contents($cacheMetaFile), true) ?: [];
    serveXml((string) file_get_contents($cacheFile), $meta['source'] ?? 'cache', false);
}

// 2) Proviamo a scaricare da una fonte fresca. Lock semplice per evitare
//    che richieste concorrenti scarichino tutte insieme (dogpile).
$fp = @fopen($lockFile, 'c');
$gotLock = $fp && flock($fp, LOCK_EX | LOCK_NB);

if ($gotLock || $customUrl) {
    $lastError = null;
    foreach ($sources as $url) {
        [$ok, $body, $err] = httpGet($url, (int) $config['epg_fetch_timeout'], $config['user_agent']);
        if ($ok) {
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            if (!$customUrl) {
                @file_put_contents($cacheFile . '.tmp', $body);
                @rename($cacheFile . '.tmp', $cacheFile);
                @file_put_contents($cacheMetaFile, json_encode(['source' => $host, 'time' => time()]));
            }
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
            serveXml($body, $host, false);
        }
        $lastError = "$url -> $err";
    }
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
} else {
    // Un altro processo sta già scaricando: attendiamo un istante e poi
    // ripieghiamo sulla cache (anche se leggermente scaduta) se disponibile.
    usleep(300000);
}

// 3) Tutte le fonti hanno fallito (o un altro processo sta scaricando):
//    serviamo la cache anche se scaduta, purché non troppo vecchia.
if (!$customUrl && is_file($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age < $staleMaxAge) {
        $meta = json_decode((string) @file_get_contents($cacheMetaFile), true) ?: [];
        serveXml((string) file_get_contents($cacheFile), $meta['source'] ?? 'cache', true);
    }
}

fail(503, 'Nessuna fonte EPG raggiungibile al momento' . (isset($lastError) ? " ($lastError)" : '') . '.');
