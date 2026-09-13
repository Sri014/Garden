<?php
header('Content-Type: audio/x-mpegurl; charset=utf-8');
$download = isset($_GET['download']) && $_GET['download'] === '1';
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="garden.m3u"');
header('Cache-Control: no-cache');

$gf = __DIR__ . '/cache/garden_feed.json';
$data = file_exists($gf) ? json_decode(file_get_contents($gf), true) : null;

if (!$data || empty($data['result'])) {
    $_GET['refresh'] = '1';
    ob_start();
    include __DIR__ . '/garden_feed.php';
    $raw = ob_get_clean();
    $data = json_decode($raw, true) ?: [];
}

function csv_filter($value, $upper=false) {
    if (!isset($_GET[$value]) || trim($_GET[$value]) === '') return [];
    $items = array_filter(array_map('trim', explode(',', $_GET[$value])));
    return $upper ? array_map('strtoupper', $items) : array_map('strtolower', $items);
}

$languages = csv_filter('language');
$qualities = csv_filter('quality', true);
$groups = csv_filter('group');
$excludeGroups = csv_filter('exclude_group');

$wantAllLanguage = empty($languages) || in_array('all', $languages, true);
$wantAllQuality = empty($qualities) || in_array('ALL', $qualities, true);
$wantAllGroup = empty($groups) || in_array('all', $groups, true);

echo "#EXTM3U\n# Garden IPTV\n";

foreach (($data['result'] ?? []) as $ch) {
    $name = trim($ch['name'] ?? 'Channel');
    $logo = $ch['logo'] ?? '';
    $url = trim($ch['url'] ?? '');
    $cat = trim($ch['cat'] ?? 'Entertainment');
    $lang = trim($ch['lang'] ?? 'All');
    $isHd = !empty($ch['hd']);
    if ($url === '') continue;

    $langKey = strtolower($lang);
    $catKey = strtolower($cat);
    $qualityKey = $isHd ? 'HD' : 'SD';

    if (!$wantAllLanguage && !in_array($langKey, $languages, true)) continue;
    if (!$wantAllQuality && !in_array($qualityKey, $qualities, true)) continue;
    if (!$wantAllGroup && !in_array($catKey, $groups, true)) continue;
    if (!empty($excludeGroups) && in_array($catKey, $excludeGroups, true)) continue;

    printf(
        "#EXTINF:-1 tvg-logo=\"%s\" group-title=\"%s\" tvg-language=\"%s\" tvg-quality=\"%s\",%s\n%s\n",
        htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'),
        $qualityKey,
        htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
        $url
    );
}
?>
