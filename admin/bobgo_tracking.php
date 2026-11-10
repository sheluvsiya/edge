<?php
declare(strict_types=1);

/*
 * Bob Go parcel tracking (proof of concept).
 *
 * Everything that depends on Bob Go's exact API lives in this file:
 *   - the address and the authorisation header come from .env
 *   - bobgo_parse_status() reads the response. Its field names are a best guess until
 *     Bob Go's sandbox documentation is available. Adjust it there, nowhere else.
 *
 * The API key is used on the server only. The browser never sees it.
 */

/** Bob Go settings from .env. */
function bobgo_settings(): array
{
    return [
        'env'      => strtolower(env_value('BOBGO_ENV', 'sandbox')),
        'url'      => env_value('BOBGO_TRACKING_URL', 'https://api.sandbox.bobgo.co.za/tracking'),   // e.g. https://.../tracking/{reference}
        'key'      => env_value('BOBGO_API_KEY', 'ffce0bf75baf4e9baf39ce8b60c79298'),
        'auth'     => env_value('BOBGO_AUTH_HEADER', 'Authorization: Bearer {ffce0bf75baf4e9baf39ce8b60c79298}'),
    ];
}

/**
 * Ask Bob Go for the current tracking status of one reference.
 * Returns ['ok' => bool, 'status' => ?string, 'events' => [...], 'checked_at' => 'Y-m-d H:i:s',
 *          'http' => int, 'error' => ?string, 'raw' => mixed]
 */
function bobgo_track(string $reference): array
{
    $cfg = bobgo_settings();
    $result = ['ok' => false, 'status' => null, 'events' => [], 'checked_at' => date('Y-m-d H:i:s'),
               'http' => 0, 'error' => null, 'raw' => null, 'env' => $cfg['env']];

    if ($cfg['url'] === '' || $cfg['key'] === '') {
        $result['error'] = 'Bob Go is not configured yet. Set BOBGO_TRACKING_URL and BOBGO_API_KEY in .env.';
        return $result;
    }
    if (!preg_match('/^[A-Za-z0-9-]{1,40}$/', $reference)) {
        $result['error'] = 'This tracking reference is not in a format Bob Go accepts.';
        return $result;
    }

    $url = str_replace('{reference}', rawurlencode($reference), $cfg['url']);
    $headers = ['Accept: application/json', str_replace('{key}', $cfg['key'], $cfg['auth'])];
    $context = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => implode("\r\n", $headers),
        'timeout'       => 10,
        'ignore_errors' => true, // read the body of error responses too
    ]]);

    $body = @file_get_contents($url, false, $context);
    $result['http'] = bobgo_http_code($http_response_header ?? []);

    if ($body === false) {
        $result['error'] = 'Could not reach Bob Go. Check the internet connection and BOBGO_TRACKING_URL.';
        return $result;
    }
    $data = json_decode($body, true);
    $result['raw'] = $data ?? $body;


if ($result['http'] < 200 || $result['http'] >= 300) {
    $result['error'] = 'Bob Go replied with HTTP '
        . $result['http'] . '. Check the API response for details.';
    return $result;
}

    if (!is_array($data)) {
        // Explain what came back, so the address can be corrected. The key is never shown.
        $contentType = '';
        foreach ($http_response_header ?? [] as $line) {
            if (stripos($line, 'Content-Type:') === 0) { $contentType = trim(substr($line, 13)); }
        }
        $looksLikeWebPage = stripos(ltrim((string)$body), '<') === 0 || stripos($contentType, 'text/html') !== false;
        $result['error'] = 'Bob Go replied with HTTP ' . $result['http'] . ' and '
            . ($contentType !== '' ? 'content type "' . $contentType . '"' : 'no content type')
            . ($looksLikeWebPage
                ? ', which is a web page, not the API. BOBGO_TRACKING_URL is pointing at a website or login page.'
                : ', which is not JSON. Check BOBGO_TRACKING_URL against Bob Go\'s API documentation.');
        return $result;
    }

    $parsed = bobgo_parse_status($data);
    $result['status'] = $parsed['status'];
    $result['events'] = $parsed['events'];
    $result['ok'] = $parsed['status'] !== null;
    if (!$result['ok']) {
        $result['error'] = 'Bob Go replied, but no status was found. Check the field names in bobgo_parse_status().';
    }
    return $result;
}


/** HTTP status code from the response headers ("HTTP/1.1 200 OK"). */
function bobgo_http_code(array $headers): int
{
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

/**
 * ADAPTER: turns Bob Go's JSON into a status and a list of events.
 * The field names below are guesses and must be checked against Bob Go's sandbox documentation.
 */
function bobgo_parse_status(array $data): array
{
    $root = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

    $status = null;
    foreach (['status', 'shipment_status', 'tracking_status', 'current_status', 'state'] as $key) {
        if (isset($root[$key]) && is_string($root[$key]) && $root[$key] !== '') {
            $status = $root[$key];
            break;
        }
    }

    $events = [];
    foreach (['events', 'tracking_events', 'history'] as $key) {
        if (isset($root[$key]) && is_array($root[$key])) {
            foreach ($root[$key] as $event) {
                if (!is_array($event)) { continue; }
                $events[] = [
                    'time'        => (string)($event['timestamp'] ?? $event['date'] ?? $event['time'] ?? ''),
                    'description' => (string)($event['description'] ?? $event['status'] ?? $event['message'] ?? ''),
                ];
            }
            break;
        }
    }
    return ['status' => $status, 'events' => $events];
}
