<?php
/**
 * Re-group Garden channels using the categories already present in 3502.
 * 3502 is the category authority. No channel is deleted here.
 */
const JIO_PLAYLIST = 'https://raw.githubusercontent.com/Sri014/3502/main/playlist_working.m3u';

function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 Garden-Jio-Category-Sync/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    return is_string($out) ? $out : '';
}

function norm_name(string $name): string {
    $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = strtolower(trim($name));
    $name = preg_replace('/\s*\(?\b(?:1080p|720p|576p|480p|2160p|4k|uhd|fhd|hd|sd)\b\)?/i', '', $name);
    $name = preg_replace('/\s*[-|:]?\s*\d+\s*$/', '', $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

function parse_jio_map(string $m3u): array {
    $map = [];
    $lines = preg_split('/\r\n|\n|\r/', $m3u);
    $current = null;
    foreach ($lines as $line) {
        $line = trim($line);
        if (stripos($line, '#EXTINF:') === 0) {
            $group = '';
            $name = '';
            if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $group = trim($m[1]);
            if (preg_match('/,([^,]*)$/', $line, $m)) $name = trim($m[1]);
            if ($name !== '' && $group !== '') $current = [$name, $group];
            else $current = null;
        } elseif ($current && $line !== '' && $line[0] !== '#') {
            $key = norm_name($current[0]);
            if ($key !== '' && !isset($map[$key])) $map[$key] = $current[1];
            $current = null;
        }
    }
    return $map;
}

function rewrite(string $file, array $map): void {
    if (!is_file($file)) return;
    $text = file_get_contents($file);
    if ($text === false) return;
    $text = preg_replace_callback(
        '/^#EXTINF:(.*?)(?:group-title="[^"]*")(.*?),(.*)$/mi',
        function ($m) use ($map) {
            $name = trim($m[3]);
            $key = norm_name($name);
            if (!isset($map[$key])) {
                // Only channels that are not matched to 3502 stay Non Jio.
                $group = 'Non Jio';
            } else {
                $group = $map[$key];
            }
            $prefix = $m[1];
            $suffix = $m[2];
            return '#EXTINF:' . $prefix . 'group-title="' . addcslashes($group, '"\\') . '"' . $suffix . ',' . $m[3];
        },
        $text
    );
    file_put_contents($file, $text);
}

$jio = http_get(JIO_PLAYLIST);
if ($jio === '') {
    fwrite(STDERR, "ERROR: Could not fetch 3502 playlist\n");
    exit(1);
}

$map = parse_jio_map($jio);
if (!$map) {
    fwrite(STDERR, "ERROR: 3502 category map is empty\n");
    exit(1);
}

rewrite(__DIR__ . '/working.m3u', $map);
rewrite(__DIR__ . '/non_working.m3u', $map);

$counts = [];
foreach ($map as $group) $counts[$group] = ($counts[$group] ?? 0) + 1;
krsort($counts);
echo 'Jio category map: ' . count($map) . " channels\n";
foreach ($counts as $group => $count) echo "$group: $count\n";
