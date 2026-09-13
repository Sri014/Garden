<?php
/**
 * TV Garden feed
 * - India-only TV channels
 * - Indian language metadata
 * - Worldwide cricket, football, hockey and tennis sports channels
 * - Category metadata for playlist filtering
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
$cacheFile = $cacheDir . '/garden_feed.json';
$ttl = 1800;

if (empty($_GET['refresh']) && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    readfile($cacheFile);
    exit;
}

function gf_http($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 TV-Garden/2.0',
        ]);
        $b = curl_exec($ch);
        curl_close($ch);
        return $b ?: '';
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'header' => "User-Agent: Mozilla/5.0\r\n"],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    return @file_get_contents($url, false, $ctx) ?: '';
}

function gf_clean_name($name) {
    $name = trim($name);
    if ($name === '' || preg_match('/chrome\/|gecko\)|mozilla\/|applewebkit/i', $name)) return '';
    $name = preg_replace('/\s*\(\d{3,4}p\)\s*$/i', '', $name);
    $name = preg_replace('/\s*\[.*?\]\s*$/', '', $name);
    return trim($name);
}

function gf_cat($group, $name) {
    $g = strtolower($group . ' ' . $name);
    if (preg_match('/education|educational|vidya|swayam|classroom|study|learning/', $g)) return 'Educational';
    if (preg_match('/shopping|shop|teleshopping|homeshopping|home shopping/', $g)) return 'ShoppingMain';
    if (preg_match('/relig|devot|bhakti|sanskar|spiritual|temple/', $g)) return 'Devotional';
    if (preg_match('/sport|cricket|football|hockey|tennis|wwe|boxing|soccer|t sports|star sports|sony six|sony ten|sports18/', $g)) return 'Sports';
    if (preg_match('/news|wion|ndtv|republic|times now|bbc|cnn|aaj tak|news18/', $g)) return 'News';
    if (preg_match('/movie|cinema|cineplex|film|classic/', $g)) return 'Movie';
    if (preg_match('/music|sangeet|mtv|9xm|b4u music|mastiii|masti/', $g)) return 'Music';
    if (preg_match('/kid|cartoon|nick|pogo|hungama|disney|sony yay|cartoon network|discovery kids/', $g)) return 'Cartoon';
    if (preg_match('/lifestyle|fox life|tlc|travelxp|food|good times|fashion|ftv/', $g)) return 'Lifestyle';
    if (preg_match('/science|discovery|national geographic|nat geo|animal planet|history tv|ngc/', $g)) return 'Science';
    if (preg_match('/business|cnbc|et now|bloomberg/', $g)) return 'Business';
    if (preg_match('/infotainment|epic|sony bbc|bbc earth/', $g)) return 'Infotainment';
    return 'Entertainment';
}

function gf_parse($text, $lang) {
    $out = [];
    $cur = null;
    foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
        $line = trim($line);
        if (stripos($line, '#EXTINF:') === 0) {
            $name = '';
            if (preg_match('/,([^,]*)$/', $line, $m)) $name = gf_clean_name(trim($m[1]));
            $logo = '';
            $group = 'General';
            $id = '';
            if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) $logo = $m[1];
            if (preg_match('/tvg-id="([^"]*)"/', $line, $m)) $id = trim($m[1]);
            if (preg_match('/group-title="([^"]*)"/', $line, $m) && trim($m[1]) !== '') $group = trim($m[1]);
            $cur = compact('name', 'logo', 'group', 'id');
        } elseif ($cur && $line !== '' && $line[0] !== '#') {
            if ($cur['name'] !== '' && preg_match('/^https?:\/\//i', $line)) {
                $out[] = [
                    'name' => $cur['name'],
                    'logo' => $cur['logo'],
                    'id' => $cur['id'],
                    'cat' => gf_cat($cur['group'], $cur['name']),
                    'lang' => $lang,
                    'url' => $line,
                    'source' => 'garden',
                    'hd' => (bool) preg_match('/1080|2160|1440|720|\bFHD\b|\bHD\b/i', $cur['name'] . ' ' . $line),
                ];
            }
            $cur = null;
        }
    }
    return $out;
}

function gf_should_remove($name) {
    $n = strtolower($name);
    foreach (['bollywood classic romania','zee cinema me'] as $b) if (strpos($n, $b) !== false) return true;
    return false;
}

$all = [];
$seen = [];
function gf_add(&$all, &$seen, $ch) {
    if (gf_should_remove($ch['name'] ?? '')) return;
    $nk = strtolower(preg_replace('/\s+/', ' ', trim($ch['name'] ?? '')));
    if ($nk === '') return;
    if (isset($seen[$nk])) {
        $i = $seen[$nk];
        if (strpos($all[$i]['url'], 'https://') !== 0 && strpos($ch['url'], 'https://') === 0) $all[$i] = $ch;
        return;
    }
    $seen[$nk] = count($all);
    $all[] = $ch;
}

/* India country feed is the authoritative TV universe: no foreign general channels. */
$indiaRaw = gf_http('https://iptv-org.github.io/iptv/countries/in.m3u');
$indiaChannels = $indiaRaw !== '' ? gf_parse($indiaRaw, 'English') : [];

/* Build Indian-language metadata from language playlists, but only for channels
   already present in the India country feed. This prevents foreign channels from
   leaking into the normal Garden TV list. */
$languageFeeds = [
    'Assamese' => 'asm', 'Bengali' => 'ben', 'Bhojpuri' => 'bho',
    'Chhattisgarhi' => 'hne', 'English' => 'eng', 'Gujarati' => 'guj',
    'Hindi' => 'hin', 'Haryanvi' => 'bgc', 'Kannada' => 'kan',
    'Konkani' => 'kok', 'Maithili' => 'mai', 'Malayalam' => 'mal',
    'Marathi' => 'mar', 'Nepali' => 'nep', 'Odia' => 'ori', 'Punjabi' => 'pan',
    'Sanskrit' => 'san', 'Santali' => 'sat', 'Sindhi' => 'snd',
    'Tamil' => 'tam', 'Telugu' => 'tel', 'Urdu' => 'urd',
];

$indiaNames = [];
foreach ($indiaChannels as $i => $ch) {
    $indiaNames[strtolower(preg_replace('/\s+/', ' ', trim($ch['name'])))] = $i;
}

$langByName = [];
foreach ($languageFeeds as $lang => $code) {
    $raw = gf_http('https://iptv-org.github.io/iptv/languages/' . $code . '.m3u');
    if ($raw === '') continue;
    foreach (gf_parse($raw, $lang) as $ch) {
        $nk = strtolower(preg_replace('/\s+/', ' ', trim($ch['name'])));
        if (isset($indiaNames[$nk])) $langByName[$nk] = $lang;
    }
}

foreach ($indiaChannels as $ch) {
    $nk = strtolower(preg_replace('/\s+/', ' ', trim($ch['name'])));
    $ch['lang'] = $langByName[$nk] ?? 'English';
    if (preg_match('/news|wion|ndtv|republic|times now|bbc|cnn|aaj tak|news18|india today|mirror now|newsx/', strtolower($ch['name']))) $ch['lang'] = $ch['lang'] ?: 'English';
    gf_add($all, $seen, $ch);
}

/* Worldwide public sports feeds. Only these sports are intentionally global. */
function gf_is_target_sport($name, $group = '') {
    $n = strtolower($name . ' ' . $group);
    return (bool) preg_match('/cricket|football|soccer|hockey|tennis|rugby|basketball|volleyball|golf|motorsport|formula 1|f1|nascar|boxing|wwe|mma|ufc|sports?/', $n);
}
$sportsRaw = gf_http('https://iptv-org.github.io/iptv/categories/sports.m3u');
if ($sportsRaw !== '') {
    foreach (gf_parse($sportsRaw, 'Sports') as $ch) {
        if (!gf_is_target_sport($ch['name'])) continue;
        $ch['lang'] = 'Sports';
        $ch['cat'] = 'Sports';
        $ch['source'] = 'garden-sports';
        gf_add($all, $seen, $ch);
    }
}

/* A few stable Garden fallbacks retained from the previous feed. */
$manual = [
    ['name'=>'Mastiii','logo'=>'https://jiotvimages.cdn.jio.com/dare_images/images/Mastiii.png','cat'=>'Music','lang'=>'Hindi','url'=>'https://mumt03.tangotv.in/O5aw8Zn3MASTIII/index.m3u8','source'=>'garden','hd'=>false],
    ['name'=>'Sangeet Bhojpuri','logo'=>'','cat'=>'Music','lang'=>'Bhojpuri','url'=>'https://mumt01.tangotv.in/O5aw8Zn3SANGEETBHOJPURI/index.m3u8','source'=>'garden','hd'=>false],
    ['name'=>'Bhojpuri Cinema','logo'=>'','cat'=>'Movie','lang'=>'Bhojpuri','url'=>'https://live-bhojpuri.akamaized.net/liveabr/playlist.m3u8','source'=>'garden','hd'=>false],
];
foreach ($manual as $ch) gf_add($all, $seen, $ch);

usort($all, function($a, $b) {
    $order = ['Sports'=>0,'Hindi'=>1,'English'=>2,'Bhojpuri'=>3];
    $oa = $order[$a['lang']] ?? 9;
    $ob = $order[$b['lang']] ?? 9;
    if ($oa !== $ob) return $oa - $ob;
    if (strcasecmp($a['lang'], $b['lang']) !== 0) return strcasecmp($a['lang'], $b['lang']);
    return strcasecmp($a['name'], $b['name']);
});

$json = json_encode([
    'result' => array_values($all),
    'count' => count($all),
    'updated' => date('c'),
    'note' => 'India-only TV channels + worldwide target sports. Filters are applied by playlist.php.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

@file_put_contents($cacheFile, $json);
echo $json;