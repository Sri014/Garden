<?php
header('Content-Type: audio/x-mpegurl; charset=utf-8');
$download = isset($_GET['download']) && $_GET['download'] === '1';
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="garden.m3u"');
header('Cache-Control: no-cache');

$gf = __DIR__ . '/cache/garden_feed.json';
$data = file_exists($gf) ? json_decode(file_get_contents($gf), true) : null;
if (!$data || empty($data['result'])) {
    $_GET['refresh'] = '1';
    ob_start(); include __DIR__ . '/garden_feed.php'; $raw = ob_get_clean();
    $data = json_decode($raw, true) ?: [];
}

function csv_filter($key, $upper=false) {
    if (!isset($_GET[$key]) || trim($_GET[$key]) === '') return [];
    $a = array_filter(array_map('trim', explode(',', $_GET[$key])));
    return $upper ? array_map('strtoupper', $a) : array_map('strtolower', $a);
}
$languages = csv_filter('language');
$qualities = csv_filter('quality', true);
$groups = csv_filter('group');
$excludeGroups = csv_filter('exclude_group');
$wantAllLanguage = empty($languages) || in_array('all', $languages, true);
$wantAllQuality = empty($qualities) || in_array('ALL', $qualities, true);
$wantAllGroup = empty($groups) || in_array('all', $groups, true);

function http_get($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
        $r = curl_exec($c); curl_close($c); return $r ?: '';
    }
    $ctx=stream_context_create(['http'=>['timeout'=>25,'header'=>"User-Agent: Mozilla/5.0\r\n"],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    return @file_get_contents($url,false,$ctx) ?: '';
}

function norm_name($s) {
    $s = strtolower(html_entity_decode((string)$s, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
    $s = str_replace(['&','+'], ' and ', $s);
    $s = preg_replace('/\b(uhd|fhd|hd|sd|4k|1080p|720p|576p|480p)\b/i', ' ', $s);
    $s = preg_replace('/\b(tv|television|channel)\b/i', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
function compact_name($s) { return str_replace(' ', '', norm_name($s)); }

/* JioTV category IDs used by the complete JioTV channel list. */
$jioCats = [5=>'Entertainment',6=>'Movies',7=>'Kids',8=>'Sports',9=>'Lifestyle',10=>'Infotainment',12=>'News',13=>'Music',15=>'Devotional',16=>'Business',17=>'Educational',18=>'Shopping',19=>'JioDarshan'];

function find_channel_arrays($v, &$out) {
    if (!is_array($v)) return;
    if (isset($v['channelId']) || isset($v['channel_id']) || isset($v['channelName']) || isset($v['channel_name'])) $out[]=$v;
    foreach ($v as $x) if (is_array($x)) find_channel_arrays($x,$out);
}
function jio_category($ch) {
    global $jioCats;
    foreach (['channelCategoryId','channel_category_id','categoryId','category_id'] as $k) {
        if (isset($ch[$k]) && is_numeric($ch[$k])) {
            $id=(int)$ch[$k]; if (isset($jioCats[$id])) return $jioCats[$id];
        }
    }
    foreach (['channelCategoryName','channel_category_name','categoryName','category_name','genre','category'] as $k) {
        if (!empty($ch[$k])) {
            $n=strtolower(trim((string)$ch[$k]));
            $map=['movie'=>'Movies','movies'=>'Movies','kid'=>'Kids','kids'=>'Kids','sport'=>'Sports','sports'=>'Sports','news'=>'News','music'=>'Music','devotional'=>'Devotional','business'=>'Business','educational'=>'Educational','education'=>'Educational','shopping'=>'Shopping','lifestyle'=>'Lifestyle','infotainment'=>'Infotainment','jiodarshan'=>'JioDarshan','entertainment'=>'Entertainment'];
            foreach($map as $a=>$b) if($n===$a || strpos($n,$a)!==false) return $b;
        }
    }
    return 'None';
}

function load_jio_map() {
    $urls = [
      'https://jiotv.data.cdn.jio.com/apis/v3.0/getMobileChannelList/get/?os=android&devicetype=phone&usertype=tvYR7NSNn7rymo3F',
      'https://jiotv.data.cdn.jio.com/apis/v3.0/getMobileChannelList/get/?os=android&devicetype=phone'
    ];
    foreach($urls as $u) {
        $raw=http_get($u); if($raw==='') continue;
        $j=json_decode($raw,true); if(!is_array($j)) continue;
        $arr=[]; find_channel_arrays($j,$arr); if(!$arr) continue;
        $map=[];
        foreach($arr as $ch) {
            $name=$ch['channelName'] ?? $ch['channel_name'] ?? $ch['name'] ?? '';
            $n=norm_name($name); if($n==='') continue;
            $cat=jio_category($ch); if($cat==='None') continue;
            $map[$n]=['cat'=>$cat,'name'=>$name];
            $map[compact_name($name)]=['cat'=>$cat,'name'=>$name];
        }
        if($map) return $map;
    }
    return [];
}

function match_jio_category($gardenName, $jioMap) {
    $n=norm_name($gardenName); if($n==='') return 'None';
    if(isset($jioMap[$n])) return $jioMap[$n]['cat'];
    $c=compact_name($gardenName); if(isset($jioMap[$c])) return $jioMap[$c]['cat'];
    $best='None'; $bestScore=0; $ties=0;
    foreach($jioMap as $jn=>$v) {
        if($jn!==$n && strlen($jn)<5) continue;
        $a=$c; $b=str_replace(' ','',$jn); if($a===''||$b==='') continue;
        $short=min(strlen($a),strlen($b)); $long=max(strlen($a),strlen($b));
        if($short<6 || $short/$long<0.72) continue;
        similar_text($a,$b,$p);
        if($p>$bestScore){$bestScore=$p;$best=$v['cat'];$ties=1;}
        elseif(abs($p-$bestScore)<1.0){$ties++;}
    }
    return ($bestScore>=90 && $ties===1) ? $best : 'None';
}

function detect_language($ch) {
    $e=trim($ch['lang']??''); $el=strtolower($e);
    if($e!=='' && !in_array($el,['all','general','unknown','india'],true)) return $e;
    $n=strtolower(($ch['name']??'').' '.($ch['cat']??''));
    $rules=[
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
      'English'=>['english','wion','ndtv','republic','times now','cnn','india today','mirror now','newsx','dd india','cnbc','et now','bloomberg','bbc','al jazeera','dw english','france 24','travelxp','good times','discovery','history tv','animal planet','national geographic','nat geo','tlc','fox life','fashion tv','ftv','mtv','nick','disney']
    ];
    foreach($rules as $lang=>$needles) foreach($needles as $x) if(strpos($n,$x)!==false) return $lang;
    return 'Hindi';
}
function language_matches($a,$w){
    $a=strtolower(trim($a));$w=strtolower(trim($w));
    $x=['bangla'=>'bengali','bengali'=>'bengali','oriya'=>'odia','odia'=>'odia','assam'=>'assamese','assamese'=>'assamese'];
    return ($x[$a]??$a)===($x[$w]??$w);
}

/* Match EVERY Garden channel against the COMPLETE JioTV list.
   Matched => JioTV category. No match => None. Never default unmatched to Entertainment. */
$jioMap=load_jio_map();
foreach(($data['result']??[]) as &$ch){
    $ch['cat']=match_jio_category($ch['name']??'', $jioMap);
    $ch['lang']=detect_language($ch);
}
unset($ch);

echo "#EXTM3U\n# Garden IPTV\n";
foreach(($data['result']??[]) as $ch){
    $name=trim($ch['name']??'Channel'); $logo=$ch['logo']??''; $url=trim($ch['url']??'');
    if($url==='') continue;
    $cat=trim($ch['cat']??'None') ?: 'None'; $lang=detect_language($ch); $isHd=!empty($ch['hd']);
    $catKey=strtolower($cat); $qualityKey=$isHd?'HD':'SD';
    if(!$wantAllLanguage){$ok=false;foreach($languages as $w)if(language_matches($lang,$w)){$ok=true;break;}if(!$ok)continue;}
    if(!$wantAllQuality && !in_array($qualityKey,$qualities,true)) continue;
    if(!$wantAllGroup && !in_array($catKey,$groups,true)) continue;
    if($excludeGroups && in_array($catKey,$excludeGroups,true)) continue;
    printf("#EXTINF:-1 tvg-logo=\"%s\" group-title=\"%s\" tvg-language=\"%s\" tvg-quality=\"%s\",%s\n%s\n",htmlspecialchars($logo,ENT_QUOTES,'UTF-8'),htmlspecialchars($cat,ENT_QUOTES,'UTF-8'),htmlspecialchars($lang,ENT_QUOTES,'UTF-8'),$qualityKey,htmlspecialchars($name,ENT_QUOTES,'UTF-8'),$url);
}
?>