<?php
/**
 * TV Garden feed
 * - Hindi + Bhojpuri (full language lists)
 * - Indian English (selected)
 * - Sports: India + Bangladesh T Sports only (NO world sports dump)
 * Refresh = latest streams from source; new matching channels auto-added
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
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
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
    if (preg_match('/sport|cricket|football|hockey|tennis|wwe|boxing|soccer|t sports|star sports|sony six|sony ten|sony espn/', $g)) return 'Sports';
    if (preg_match('/news|wion|ndtv|republic|times now|bbc|cnn|aaj tak/', $g)) return 'News';
    if (preg_match('/movie|cinema|cineplex|film|classic/', $g)) return 'Movie';
    if (preg_match('/music|sangeet|mtv|9xm|b4u music/', $g)) return 'Music';
    if (preg_match('/kid|cartoon|nick|pogo|hungama|disney|sony yay|cartoon network|discovery kids/', $g)) return 'Cartoon';
    if (preg_match('/lifestyle|fox life|tlc|travelxp|food|ndt v good times|good times/', $g)) return 'Lifestyle';
    if (preg_match('/science|discovery|national geographic|nat geo|animal planet|history tv|ngc/', $g)) return 'Science';
    if (preg_match('/fashion|ftv|fashion tv/', $g)) return 'Lifestyle';
    if (preg_match('/relig|devot|bhakti|sanskar/', $g)) return 'Devotional';
    if (preg_match('/business|cnbc|et now|bloomberg/', $g)) return 'Business';
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
            $logo = ''; $group = 'General';
            if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) $logo = $m[1];
            if (preg_match('/group-title="([^"]*)"/', $line, $m) && trim($m[1]) !== '') $group = trim($m[1]);
            $cur = compact('name', 'logo', 'group');
        } elseif ($cur && $line !== '' && $line[0] !== '#') {
            if ($cur['name'] !== '' && preg_match('/^https?:\/\//i', $line)) {
                $out[] = [
                    'name' => $cur['name'], 'logo' => $cur['logo'],
                    'cat' => gf_cat($cur['group'], $cur['name']), 'lang' => $lang,
                    'url' => $line, 'source' => 'garden',
                    'hd' => (bool) preg_match('/1080|720|\bHD\b/i', $cur['name']),
                ];
            }
            $cur = null;
        }
    }
    return $out;
}

$block = ['tamil','telugu','malayalam','kannada','bengali','bangla','marathi','gujarati','punjabi','odia','oriya','assamese','urdu','sun tv','sun news','ktv','adithya','gemini','eenadu','etv telugu','asianet','manorama','flowers tv','mathrubhumi','mazhavil','surya tv','udaya','colors kannada','colors tamil','colors marathi','zee kannada','zee tamil','zee telugu','zee keralam','star suvarna','star vijay','star maa','jaya tv','polimer','puthiya','thanthi','abn andhra','tv9 telugu','tv9 kannada','tv9 marathi','news18 tamil','news18 kerala','news18 kannada','news18 assam','dd chandana','dd yadagiri','dd malayalam','dd podhigai','dd sahyadri','chithiram','jaya max'];
function gf_blocked($name, $block) {
    $n = strtolower($name);
    foreach ($block as $b) if (strpos($n, $b) !== false) return true;
    return false;
}
function gf_is_india_sport($name) {
    $n = strtolower($name);
    foreach (['star sports','sony six','sony ten','sony espn','dd sports','willow','cricket','ptv sports','ten cricket','sports18','jio cricket','t sports','tsports','unite8 sports','unite sports','fox sports','1sports','ssc sports','astro cricket','sky sports cricket','super sport cricket','a sports','geo super'] as $k) if (strpos($n, $k) !== false) return true;
    return false;
}
function gf_should_remove($name) {
    $n = strtolower($name);
    foreach (['e vidya','evidya','e-vidya','swayam prabha','swayamprabha','vande gujarat','vande gujrat','bollywood classic romania','zee cinema me'] as $b) if (strpos($n, $b) !== false) return true;
    return false;
}
$all=[]; $seen=[];
function gf_add(&$all,&$seen,$ch) {
    if (gf_should_remove($ch['name']??'')) return;
    $nk=strtolower(preg_replace('/\s+/',' ',$ch['name']));
    if(isset($seen[$nk])) { $i=$seen[$nk]; if(strpos($all[$i]['url'],'https://')===0)return; if(strpos($ch['url'],'https://')===0)$all[$i]=$ch; return; }
    $seen[$nk]=count($all); $all[]=$ch;
}

foreach (['Hindi'=>'https://iptv-org.github.io/iptv/languages/hin.m3u','Bhojpuri'=>'https://iptv-org.github.io/iptv/languages/bho.m3u'] as $lang=>$url) {
    $raw=gf_http($url); if($raw==='')continue;
    foreach(gf_parse($raw,$lang) as $ch){if(gf_blocked($ch['name'],$block))continue;gf_add($all,$seen,$ch);}
}
$indiaRaw=gf_http('https://iptv-org.github.io/iptv/countries/in.m3u');
$engHints=['wion','ndtv','republic','times now','cnn-news18','cnn news18','india today','mirror now','newsx','dd india','cnbc','et now','bloomberg','bbc','al jazeera','dw english','france 24','discovery','history tv','travelxp','good times'];
if($indiaRaw!=='') foreach(gf_parse($indiaRaw,'English') as $ch){if(gf_blocked($ch['name'],$block))continue;$ln=strtolower($ch['name']);$isEng=false;foreach($engHints as $h)if(strpos($ln,$h)!==false){$isEng=true;break;}$isSport=gf_is_india_sport($ch['name'])||$ch['cat']==='Sports';if(!$isEng&&!$isSport)continue;if($isSport){$ch['lang']='Sports';$ch['cat']='Sports';}else$ch['lang']='English';gf_add($all,$seen,$ch);}
$bdRaw=gf_http('https://iptv-org.github.io/iptv/countries/bd.m3u');
if($bdRaw!=='')foreach(gf_parse($bdRaw,'Sports') as $ch){$n=strtolower($ch['name']);if(strpos($n,'t sports')===false&&strpos($n,'tsports')===false)continue;$ch['lang']='Sports';$ch['cat']='Sports';gf_add($all,$seen,$ch);}
function gf_is_extra_cat($name,$group){$g=strtolower($group.' '.$name);if(preg_match('/kid|cartoon|nick|pogo|hungama|disney|sony yay|cartoon network|discovery kids|nick jr/',$g))return'Cartoon';if(preg_match('/music|mtv|9xm|9x |b4u music|mastiii|masti|vh1|music india/',$g))return'Music';if(preg_match('/lifestyle|fox life|tlc|travelxp|food food|good times|ndt v good/',$g))return'Lifestyle';if(preg_match('/discovery|national geographic|nat geo|animal planet|history tv|science|ngc/',$g))return'Science';if(preg_match('/fashion tv|\bftv\b|ftv india|fashiontv/',$g))return'Lifestyle';if(preg_match('/infotainment|epic|sony bbc|bbc earth/',$g))return'Infotainment';return null;}
if($indiaRaw!=='')foreach(gf_parse($indiaRaw,'English') as $ch){if(gf_blocked($ch['name'],$block))continue;$cat=gf_is_extra_cat($ch['name'],$ch['cat']??'');if($cat===null)continue;$ln=strtolower($ch['name']);$isEng=false;foreach(['discovery','national geographic','nat geo','animal planet','history','tlc','fox life','fashion','ftv','bbc','nick','disney','mtv'] as $e)if(strpos($ln,$e)!==false){$isEng=true;break;}$ch['cat']=$cat;$ch['lang']=$isEng?'English':'Hindi';gf_add($all,$seen,$ch);}
foreach(['https://iptv-org.github.io/iptv/categories/music.m3u','https://iptv-org.github.io/iptv/categories/animation.m3u','https://iptv-org.github.io/iptv/categories/documentary.m3u'] as $eu){$raw=gf_http($eu);if($raw==='')continue;foreach(gf_parse($raw,'English') as $ch){$n=strtolower($ch['name']);if(gf_blocked($ch['name'],$block))continue;if(!preg_match('/\b(9xm|9x jalwa|9x jhakaas|9x tashan|b4u music|mastiii|masti|mtv india|\bmtv\b|vh1|hungama|pogo|nick|disney|sony yay|cartoon network|discovery kids|discovery|national geographic|nat geo|animal planet|history tv|fashion tv|\bftv\b|fox life|\btlc\b|travelxp)/',$n))continue;$cat=gf_is_extra_cat($ch['name'],$ch['cat']??'');if($cat===null&&preg_match('/fashion tv|\bftv\b/',$n))$cat='Lifestyle';if($cat===null)continue;$ch['cat']=$cat;$ch['lang']=preg_match('/9xm|9x |b4u|mast|hungama|pogo/',$n)?'Hindi':'English';gf_add($all,$seen,$ch);}}
function gf_want_intl_sport($name){$n=strtolower($name);foreach(['cricket','willow','fox cricket','sky sports cricket','tnt sport','tnt sports','super sport','supersport','star sports','sony six','sony ten','sony espn','t sports','tsports','dd sports','ptv sports','ten cricket','sports18','cricket gold','astro cricket','nine cricket','7 cricket','fox sports'] as $k)if(strpos($n,$k)!==false)return true;return false;}
$sportsRaw=gf_http('https://iptv-org.github.io/iptv/categories/sports.m3u');
if($sportsRaw!=='')foreach(gf_parse($sportsRaw,'Sports') as $ch){if(!gf_want_intl_sport($ch['name']))continue;$ch['lang']='Sports';$ch['cat']='Sports';gf_add($all,$seen,$ch);}
foreach(['https://iptv-org.github.io/iptv/countries/uk.m3u','https://iptv-org.github.io/iptv/countries/us.m3u','https://iptv-org.github.io/iptv/countries/nz.m3u','https://iptv-org.github.io/iptv/countries/za.m3u','https://iptv-org.github.io/iptv/countries/au.m3u'] as $curl){$raw=gf_http($curl);if($raw==='')continue;foreach(gf_parse($raw,'Sports') as $ch){if(!gf_want_intl_sport($ch['name']))continue;$ch['lang']='Sports';$ch['cat']='Sports';gf_add($all,$seen,$ch);}}
$fixes=['sangeet bhojpuri'=>'https://mumt01.tangotv.in/O5aw8Zn3SANGEETBHOJPURI/index.m3u8','bhojpuri cinema'=>'https://live-bhojpuri.akamaized.net/liveabr/playlist.m3u8','star sports select 2'=>'http://tvsen7.aynascope.net/ssport2hd/index.m3u8','star sports select 1'=>'http://tvsen7.aynascope.net/sspts1/index.m3u8','willow'=>'https://d36r8jifhgsk5j.cloudfront.net/Willow_TV.m3u8'];
$manual=[['name'=>'Mastiii','logo'=>'https://jiotvimages.cdn.jio.com/dare_images/images/Mastiii.png','cat'=>'Music','lang'=>'Hindi','url'=>'https://mumt03.tangotv.in/O5aw8Zn3MASTIII/index.m3u8','source'=>'garden','hd'=>false],['name'=>'Discovery Channel','logo'=>'https://upload.wikimedia.org/wikipedia/commons/thumb/2/27/Discovery_Channel_-_Logo_2019.svg/512px-Discovery_Channel_-_Logo_2019.svg.png','cat'=>'Science','lang'=>'English','url'=>'https://cdn-1.pishow.tv/live/1211/master.m3u8','source'=>'garden','hd'=>true],['name'=>'Fashion TV','logo'=>'https://upload.wikimedia.org/wikipedia/commons/thumb/7/7a/Fashion_TV_logo.svg/512px-Fashion_TV_logo.svg.png','cat'=>'Lifestyle','lang'=>'English','url'=>'https://fash1043.cloudycdn.services/slive/_definst_/ftv_ftv_midnite_seoul_1_1_1080p_1_hls.smil/playlist.m3u8','source'=>'garden','hd'=>true],['name'=>'Animal Planet','logo'=>'https://upload.wikimedia.org/wikipedia/commons/thumb/2/20/2018_Animal_Planet_logo.svg/512px-2018_Animal_Planet_logo.svg.png','cat'=>'Science','lang'=>'English','url'=>'https://cdn-1.pishow.tv/live/1211/master.m3u8','source'=>'garden','hd'=>true]];
foreach($manual as $ch)gf_add($all,$seen,$ch);
foreach($all as &$ch){$ln=strtolower($ch['name']);foreach($fixes as $k=>$u)if(strpos($ln,$k)!==false){$ch['url']=$u;$ch['urls']=[$u];break;}}unset($ch);
usort($all,function($a,$b){$order=['Sports'=>0,'Hindi'=>1,'English'=>2,'Bhojpuri'=>3];$oa=$order[$a['lang']]??9;$ob=$order[$b['lang']]??9;if($oa!==$ob)return$oa-$ob;return strcasecmp($a['name'],$b['name']);});
$json=json_encode(['result'=>array_values($all),'count'=>count($all),'updated'=>date('c'),'note'=>'Hindi+English+Bhojpuri+India/BD sports (T Sports). No world sports dump.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
@file_put_contents($cacheFile,$json);echo $json;
