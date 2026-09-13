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

function csv_filter($key, $upper = false) {
    if (!isset($_GET[$key]) || trim($_GET[$key]) === '') return [];
    $items = array_filter(array_map('trim', explode(',', $_GET[$key])));
    return $upper ? array_map('strtoupper', $items) : array_map('strtolower', $items);
}

$languages = csv_filter('language');
$qualities = csv_filter('quality', true);
$groups = csv_filter('group');
$excludeGroups = csv_filter('exclude_group');

$wantAllLanguage = empty($languages) || in_array('all', $languages, true);
$wantAllQuality = empty($qualities) || in_array('ALL', $qualities, true);
$wantAllGroup = empty($groups) || in_array('all', $groups, true);

function detect_language($ch) {
    $existing = trim($ch['lang'] ?? '');
    $e = strtolower($existing);
    if ($e !== '' && !in_array($e, ['all','general','unknown','india'], true)) return $existing;
    $n = strtolower(trim(($ch['name'] ?? '') . ' ' . ($ch['cat'] ?? '')));
    $rules = [
        'Tamil'=>['tamil','sun tv','sun news','ktv','adithya','polimer','puthiya','thanthi','dd podhigai','jaya tv','jaya max'],
        'Telugu'=>['telugu','gemini','eenadu','etv telugu','abn andhra','tv9 telugu','dd yadagiri','star maa'],
        'Malayalam'=>['malayalam','asianet','manorama','flowers tv','mathrubhumi','mazhavil','surya tv','dd malayalam','zee keralam'],
        'Kannada'=>['kannada','udaya','colors kannada','zee kannada','star suvarna','dd chandana','tv9 kannada'],
        'Bengali'=>['bengali','bangla','zee bangla','star jalsha','colors bangla','sun bangla','dd bangla','abp ananda','tv9 bangla'],
        'Marathi'=>['marathi','zee marathi','colors marathi','star pravah','sony marathi','dd sahyadri','tv9 marathi'],
        'Gujarati'=>['gujarati','zee 24 kalak','colors gujarati','sandesh','vande gujarat','dd girnar'],
        'Punjabi'=>['punjabi','zee punjabi','ptc punjabi','ptc news','chardikla','dd punjabi'],
        'Odia'=>['odia','oriya','zee odia','kalinga tv','otv','kanak news','dd odia'],
        'Assamese'=>['assamese','assam','pratidin time','prag news','dy365','news live','dd assam'],
        'Urdu'=>['urdu','dd urdu'],
        'Bhojpuri'=>['bhojpuri','sangeet bhojpuri','bhojpuri cinema','dabangg','b4u bhojpuri','zee ganga'],
        'Hindi'=>['hindi','zee tv','zee cinema','colors','sony entertainment','sony sab','sony pal','star plus','star bharat','&tv','and tv','dd national','dd kisan','aaj tak','abp news','india tv','news18 india','republic bharat'],
        'English'=>['english','wion','ndtv','republic','times now','cnn','india today','mirror now','newsx','dd india','cnbc','et now','bloomberg','bbc','al jazeera','dw english','france 24','travelxp','good times','discovery','history tv','animal planet','national geographic','nat geo','tlc','fox life','fashion tv','ftv','mtv','nick','disney'],
    ];
    foreach ($rules as $lang=>$needles) foreach ($needles as $needle) if (strpos($n,$needle)!==false) return $lang;
    return 'Hindi';
}

/* Never expose the old "Non Jio" category. If an old cached feed still contains
   it, recategorize the channel here instead of passing it to the M3U. */
function fix_category($ch) {
    $cat = trim($ch['cat'] ?? '');
    if (strcasecmp($cat, 'Non Jio') !== 0) return $cat ?: 'Entertainment';

    $n = strtolower(trim($ch['name'] ?? ''));
    if (preg_match('/cricket/i', $n)) return 'Sports';
    if (preg_match('/news|aaj tak|ndtv|republic|wion|cnn|bbc|times now|news18|cnbc|et now|india today/i', $n)) return 'News';
    if (preg_match('/music|mtv|9xm|b4u music|mastiii|sangeet/i', $n)) return 'Music';
    if (preg_match('/movie|cinema|cineplex|film/i', $n)) return 'Movies';
    if (preg_match('/kid|cartoon|nick|pogo|hungama|disney/i', $n)) return 'Kids';
    if (preg_match('/education|educational|study|learning/i', $n)) return 'Educational';
    if (preg_match('/shopping|shop|teleshopping/i', $n)) return 'ShoppingMain';
    if (preg_match('/devot|bhakti|sanskar|spiritual|temple/i', $n)) return 'Devotional';
    if (preg_match('/science|discovery|national geographic|nat geo|animal planet|history/i', $n)) return 'Science';
    if (preg_match('/lifestyle|travel|food|fashion|tlc/i', $n)) return 'Lifestyle';
    if (preg_match('/infotainment|epic/i', $n)) return 'Infotainment';
    return 'Entertainment';
}

function language_matches($actual, $wanted) {
    $a = strtolower(trim($actual));
    $w = strtolower(trim($wanted));
    $aliases = [
        'bangla'=>'bengali','bengali'=>'bengali',
        'oriya'=>'odia','odia'=>'odia',
        'assam'=>'assamese','assamese'=>'assamese',
    ];
    if (isset($aliases[$w])) $w = $aliases[$w];
    if (isset($aliases[$a])) $a = $aliases[$a];
    return $a === $w;
}

echo "#EXTM3U\n# Garden IPTV\n";

foreach (($data['result'] ?? []) as $ch) {
    $name = trim($ch['name'] ?? 'Channel');
    $logo = $ch['logo'] ?? '';
    $url = trim($ch['url'] ?? '');
    $cat = fix_category($ch);
    $lang = detect_language($ch);
    $isHd = !empty($ch['hd']);
    if ($url === '') continue;

    $catKey = strtolower($cat);
    $qualityKey = $isHd ? 'HD' : 'SD';

    if (!$wantAllLanguage) {
        $matched = false;
        foreach ($languages as $wanted) {
            if (language_matches($lang, $wanted)) { $matched = true; break; }
        }
        if (!$matched) continue;
    }
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