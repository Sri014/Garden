<?php
header('Content-Type: audio/x-mpegurl; charset=utf-8');
$download = isset($_GET['download']) && $_GET['download'] === '1';
header('Content-Disposition: '.($download?'attachment':'inline').'; filename="garden.m3u"');
header('Cache-Control: no-cache');
$gf=__DIR__.'/cache/garden_feed.json';
$data=file_exists($gf)?json_decode(file_get_contents($gf),true):null;
if(!$data || empty($data['result'])){$_GET['refresh']='1';ob_start();include __DIR__.'/garden_feed.php';$raw=ob_get_clean();$data=json_decode($raw,true)?:[];}
echo "#EXTM3U\n# Garden IPTV\n";
foreach(($data['result']??[]) as $ch){$name=$ch['name']??'Channel';$logo=$ch['logo']??'';$url=$ch['url']??'';if($url==='')continue;$cat=$ch['cat']??'Entertainment';$lang=$ch['lang']??'All';printf("#EXTINF:-1 tvg-logo=\"%s\" group-title=\"%s\" tvg-language=\"%s\",%s\n%s\n",htmlspecialchars($logo,ENT_QUOTES),htmlspecialchars($cat,ENT_QUOTES),htmlspecialchars($lang,ENT_QUOTES),$name,$url);}
