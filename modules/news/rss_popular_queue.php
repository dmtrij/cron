<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../send.php';
require_once __DIR__ . '/../../lib/formatter/formatter_news_rss.php';

date_default_timezone_set(CRON_TIMEZONE);
const TAG = 'news_rss_popular';

$filtersFile = __DIR__ . '/news_filters_rss_popular.json';
$sourcesFileDefault = __DIR__ . '/news_sources_rss.json';
$digestPromptFileDefault = __DIR__ . '/news_digest_prompt_rss.json';
$queueFile = __DIR__ . '/news_moderation_queue_rss.json';
$editStateFile = __DIR__ . '/news_moderation_edit_state_rss.json';

function load_json(string $path): array { if (!is_file($path)) return []; $r=@file_get_contents($path); if(!is_string($r)||trim($r)==='') return []; $j=json_decode($r,true); return is_array($j)?$j:[]; }
function save_json_atomic(string $path, array $data): void {
  $dir=dirname($path); if(!is_dir($dir)) @mkdir($dir,0775,true);
  $p=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); if(!is_string($p)) return;
  $tmp=$path.'.tmp'; $f=@fopen($tmp,'wb'); if($f===false) return; try{flock($f,LOCK_EX);fwrite($f,$p);fflush($f);}finally{flock($f,LOCK_UN);fclose($f);} @rename($tmp,$path);
}
function tg_html_escape(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function norm_text(string $s): string { $s=html_entity_decode($s, ENT_QUOTES|ENT_HTML5,'UTF-8'); $s=strip_tags($s); $s=preg_replace('~\s+~u',' ', $s ?? ''); return trim((string)$s); }
function normalize_url(string $url): string {
  $url=html_entity_decode(trim($url), ENT_QUOTES|ENT_HTML5,'UTF-8'); if($url==='') return '';
  $p=parse_url($url); if(!$p||!isset($p['scheme'],$p['host'])) return '';
  $sch=strtolower((string)$p['scheme']); if($sch!=='http'&&$sch!=='https') return '';
  $host=strtolower((string)$p['host']); $path=(string)($p['path']??'/'); if($path==='') $path='/'; if($path!=='/'&&str_ends_with($path,'/')) $path=rtrim($path,'/');
  return $sch.'://'.$host.$path;
}
function contains_any(string $text, array $list): bool {
  $text=mb_strtolower($text,'UTF-8'); foreach($list as $w){ $w=trim((string)$w); if($w!==''&&mb_strpos($text, mb_strtolower($w,'UTF-8'))!==false) return true; } return false;
}
function parse_feed_date(string $raw): int { $raw=trim($raw); if($raw==='') return 0; $ts=strtotime($raw); return $ts===false?0:(int)$ts; }

function telegram_api_post(string $method, array $data, string $tag): ?array {
  if (TELEGRAM_BOT_TOKEN==='') { cron_log($tag,'TELEGRAM_BOT_TOKEN is empty'); return null; }
  $url='https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method;
  $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>HTTP_TIMEOUT_SEC,'header'=>"Content-type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($data)]]);
  $raw=@file_get_contents($url,false,$ctx); if(!is_string($raw)||$raw===''){ cron_log($tag,'Telegram API '.$method.' failed'); return null; }
  $j=json_decode($raw,true); if(!is_array($j)||!($j['ok']??false)){ cron_log($tag,'Telegram API '.$method.' error'); return null; }
  return $j;
}
function telegram_send_html_message(string $chatId,string $text,string $tag,?array $replyMarkup=null): ?array {
  $d=['chat_id'=>$chatId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true]; if($replyMarkup!==null) $d['reply_markup']=json_encode($replyMarkup, JSON_UNESCAPED_UNICODE); return telegram_api_post('sendMessage',$d,$tag);
}
function telegram_delete_message(string $chatId,int $messageId,string $tag): bool { if($messageId<=0) return false; return telegram_api_post('deleteMessage',['chat_id'=>$chatId,'message_id'=>$messageId],$tag)!==null; }

function moderation_reply_markup(string $id): array {
  $publish="\u{041E}\u{043F}\u{0443}\u{0431}\u{043B}\u{0438}\u{043A}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";
  $reject="\u{041E}\u{0442}\u{043A}\u{043B}\u{043E}\u{043D}\u{0438}\u{0442}\u{044C}";
  $edit="\u{0420}\u{0435}\u{0434}\u{0430}\u{043A}\u{0442}\u{0438}\u{0440}\u{043E}\u{0432}\u{0430}\u{0442}\u{044C}";
  $clear="\u{1F9F9}";
  return ['inline_keyboard'=>[[['text'=>$publish,'callback_data'=>'rssmod:pub:'.$id],['text'=>$reject,'callback_data'=>'rssmod:rej:'.$id]],[['text'=>$edit,'callback_data'=>'rssmod:edit:'.$id],['text'=>$clear,'callback_data'=>'rssmod:clear:'.$id]]]];
}
function generate_moderation_id(string $seed,int $now): string { try{$r=bin2hex(random_bytes(8));}catch(Throwable){$r=uniqid('',true);} return substr(hash('sha256',$seed.'|'.$now.'|'.$r),0,20); }

function load_moderation_queue(string $path): array { $q=load_json($path); if(!isset($q['items'])||!is_array($q['items'])) $q['items']=[]; return $q; }
function prune_moderation_queue(array &$queue, int $now, int $windowSec): void {
  if(!isset($queue['items'])||!is_array($queue['items'])) { $queue['items']=[]; return; }
  $ttl=max(60,$windowSec); foreach($queue['items'] as $id=>$item){ if(!is_array($item)){unset($queue['items'][$id]); continue;} $c=(int)($item['createdAt']??0); if($c<=0||($now-$c)>$ttl) unset($queue['items'][$id]); }
}
function queue_has_pending_for_url(array $queue,string $url): bool { foreach(($queue['items']??[]) as $it){ if(is_array($it)&&($it['status']??'')==='pending'&&($it['url']??'')===$url) return true; } return false; }

function clear_moderation_for_fresh_run(string $queueFilePath,string $editStateFilePath,string $moderatorChatId,string $tag): array {
  $queue=load_moderation_queue($queueFilePath); $items=is_array($queue['items']??null)?$queue['items']:[]; $msgIds=[];
  foreach($items as $item){ if(!is_array($item)) continue; $chat=(string)($item['moderatorChatId']??''); if($moderatorChatId!==''&&$chat!==''&&$chat!==$moderatorChatId) continue; $mid=(int)($item['moderationMessageId']??0); if($mid>0) $msgIds[]=$mid; }
  $deleted=0; foreach($msgIds as $mid){ if(telegram_delete_message($moderatorChatId,(int)$mid,$tag)) $deleted++; }
  $removed=count($items); $queue['items']=[]; save_json_atomic($queueFilePath,$queue);
  $state=load_json($editStateFilePath); $sessions=is_array($state['sessions']??null)?$state['sessions']:[]; $removedSessions=count($sessions); $state['sessions']=[]; save_json_atomic($editStateFilePath,$state);
  return ['removed_items'=>$removed,'removed_sessions'=>$removedSessions,'delete_attempted'=>count($msgIds),'delete_ok'=>$deleted];
}

function xml_node_text(SimpleXMLElement $node, array $names): string {
  foreach($names as $n){ if(isset($node->{$n})){ $v=norm_text((string)$node->{$n}); if($v!=='') return $v; } }
  foreach($names as $n){ $res=$node->xpath('./*[local-name()="'.$n.'"]'); if(!is_array($res)) continue; foreach($res as $cand){ $v=norm_text((string)$cand); if($v!=='') return $v; }}
  return '';
}
function atom_entry_link(SimpleXMLElement $entry): string { $links=$entry->xpath('./*[local-name()="link"]'); if(!is_array($links)) return ''; foreach($links as $ln){ $h=trim((string)($ln['href']??'')); $r=strtolower(trim((string)($ln['rel']??''))); if($h!==''&&($r===''||$r==='alternate')) return $h; } return ''; }
function xml_node_link(SimpleXMLElement $node): string { $u=xml_node_text($node,['link','guid','id']); if($u!=='') return $u; $links=$node->xpath('./*[local-name()="link"]'); if(!is_array($links)) return ''; foreach($links as $ln){ $h=trim((string)($ln['href']??'')); if($h!=='') return $h; } return ''; }
function parse_feed_items(string $xmlRaw,string $source): array {
  $xmlRaw=preg_replace('/^\xEF\xBB\xBF/','',$xmlRaw) ?? $xmlRaw; $xmlRaw=trim($xmlRaw); if($xmlRaw==='') return [];
  libxml_use_internal_errors(true); $xml=simplexml_load_string($xmlRaw,'SimpleXMLElement',LIBXML_NOCDATA); if($xml===false) return [];
  $out=[];
  $rss=$xml->xpath('/rss/channel/item'); if(!is_array($rss)||$rss===[]) $rss=$xml->xpath('/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]');
  if(is_array($rss)) foreach($rss as $it){ $title=norm_text(xml_node_text($it,['title'])); $url=normalize_url(xml_node_link($it)); if($title===''||$url==='') continue; $out[]=['title'=>$title,'desc'=>norm_text(xml_node_text($it,['description','summary','content'])),'url'=>$url,'src'=>$source,'pubTs'=>parse_feed_date(xml_node_text($it,['pubDate','published','updated','date']))]; }
  $entries=$xml->xpath('/feed/entry'); if(!is_array($entries)||$entries===[]) $entries=$xml->xpath('/*[local-name()="feed"]/*[local-name()="entry"]');
  if(is_array($entries)) foreach($entries as $e){ $title=norm_text(xml_node_text($e,['title'])); $url=normalize_url(atom_entry_link($e)); if($url==='') $url=normalize_url(xml_node_text($e,['id'])); if($title===''||$url==='') continue; $out[]=['title'=>$title,'desc'=>norm_text(xml_node_text($e,['summary','content','description'])),'url'=>$url,'src'=>$source,'pubTs'=>parse_feed_date(xml_node_text($e,['published','updated','date']))]; }
  return $out;
}

function fetch_feed(string $url): ?string {
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'ignore_errors'=>true,'header'=>"Accept: application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.8\r\nConnection: close\r\n"]]);
  $raw=@file_get_contents($url,false,$ctx); return is_string($raw)&&$raw!=='' ? $raw : null;
}

function text_has_cyrillic(string $text): bool { return preg_match('~\p{Cyrillic}~u', $text) === 1; }
function translate_to_ru_best_effort(string $text): string {
  $text=trim($text); if($text==='') return '';
  if(text_has_cyrillic($text)) return $text;
  static $cache=[]; if(isset($cache[$text])) return $cache[$text];
  $query=$text; if(function_exists('mb_substr')) $query=mb_substr($query,0,1400,'UTF-8'); else $query=substr($query,0,1400);
  $url='https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=ru&dt=t&q='.rawurlencode($query);
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>6,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);
  $raw=@file_get_contents($url,false,$ctx);
  if(!is_string($raw)||$raw===''){ $cache[$text]=$text; return $text; }
  $j=json_decode($raw,true); if(!is_array($j)||!isset($j[0])||!is_array($j[0])){ $cache[$text]=$text; return $text; }
  $out=''; foreach($j[0] as $part){ if(is_array($part)&&isset($part[0])) $out.=(string)$part[0]; }
  $out=norm_text($out); if($out==='') $out=$text; $cache[$text]=$out; return $out;
}
function digest_item_is_informative(array $it): bool {
  $title=norm_text((string)($it['title']??'')); if($title==='') return false;
  $desc=norm_text((string)($it['desc']??''));
  $titleLc=function_exists('mb_strtolower')?mb_strtolower($title,'UTF-8'):strtolower($title);
  $ban=['analyst report','press release','earnings call transcript','market wrap','market recap','watchlist','stock movers','ticker tape'];
  foreach($ban as $p){ if($p!=='' && strpos($titleLc,$p)!==false && $desc==='') return false; }
  if($desc===''){
    $wcount=count(array_filter(preg_split('~\s+~u',$title)?:[]));
    if($wcount<=8) return false;
    if((function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title))<70) return false;
  }
  return true;
}

function build_digest_input_blob(array $items,int $maxItems): string {
  $rows=[]; $n=1;
  foreach(array_slice($items,0,max(1,$maxItems)) as $it){
    if(!digest_item_is_informative($it)) continue;
    $t=norm_text((string)($it['title']??'')); if($t==='') continue;
    $d=norm_text((string)($it['desc']??'')); $u=(string)($it['url']??'');
    $s=function_exists('rss_news_source_label')?rss_news_source_label($it):(string)($it['src']??'');
    $rows[]=$n.'. ['.$s.'] '.$t.($d!==''?' | '.$d:'').($u!==''?' | '.$u:'');
    $n++;
  }
  return implode("\n",$rows);
}
function normalize_model_candidates(string $primary,array $fallback): array { $all=[]; foreach(array_merge([$primary],$fallback,['gpt-4o-mini','gpt-4.1-mini']) as $m){ $m=trim((string)$m); if($m!=='') $all[$m]=true; } return array_values(array_keys($all)); }
function openai_digest(string $apiKey,string $baseUrl,string $model,string $systemPrompt,string $userPrompt,float $temperature,int $timeoutSec,string $project,string $tag): ?string {
  if($apiKey===''){ cron_log($tag,'DIGEST skipped: OPENAI_API_KEY is empty'); return null; }
  $url=rtrim($baseUrl!==''?$baseUrl:'https://api.openai.com/v1','/').'/chat/completions';
  $payload=json_encode(['model'=>$model,'messages'=>[['role'=>'system','content'=>$systemPrompt],['role'=>'user','content'=>$userPrompt]],'temperature'=>max(0.0,min(1.0,$temperature)),'max_tokens'=>900], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($payload)){ cron_log($tag,'DIGEST failed: cannot encode payload; model='.$model); return null; }
  $headers="Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n".(trim($project)!==''?"OpenAI-Project: ".trim($project)."\r\n":'');
  $ctx=stream_context_create(['http'=>['method'=>'POST','timeout'=>max(5,$timeoutSec),'header'=>$headers,'content'=>$payload,'ignore_errors'=>true]]);
  $raw=@file_get_contents($url,false,$ctx); $http=0; if(isset($http_response_header)&&is_array($http_response_header)){ foreach($http_response_header as $h){ if(preg_match('~HTTP/\S+\s+(\d{3})~',$h,$m)){ $http=(int)$m[1]; break; } } }
  if(!is_string($raw)||$raw===''){ cron_log($tag,'DIGEST failed: empty response; model='.$model.'; http='.$http); return null; }
  $j=json_decode($raw,true); if(!is_array($j)){ cron_log($tag,'DIGEST failed: bad JSON; model='.$model.'; http='.$http); return null; }
  if($http>=400 || isset($j['error'])){ $msg=trim((string)($j['error']['message']??'')); cron_log($tag,'DIGEST failed: API error; model='.$model.'; http='.$http.'; msg='.$msg); return null; }
  $content=$j['choices'][0]['message']['content'] ?? ''; if(is_array($content)){ $parts=[]; foreach($content as $p){ if(is_array($p)){ $txt=trim((string)($p['text']??'')); if($txt!=='') $parts[]=$txt; } } $content=implode("\n",$parts); }
  $content=trim((string)$content); if($content===''){ cron_log($tag,'DIGEST failed: empty content; model='.$model); return null; }
  return $content;
}
function digest_points(string $plain,int $limit): array {
  $out=[]; $plain=trim($plain); if($plain==='') return $out;
  $blocks=preg_split('~(?:\R\s*){2,}~u',$plain) ?: [];
  if($blocks===[]) $blocks=[$plain];
  foreach($blocks as $block){
    $lines=[]; foreach((preg_split('~\R+~u',trim((string)$block))?:[]) as $line){
      $line=trim((string)$line); if($line==='') continue;
      $line=preg_replace('~^\s*(?:[-*\x{2022}]+|\d+[\.)])\s*~u','',$line) ?? $line;
      $line=trim($line); if($line==='') continue;
      $lines[]=$line;
    }
    if($lines===[]) continue;
    $out[] = implode("\n",$lines);
    if(count($out)>=$limit) break;
  }
  if($out===[] && $plain!=='') $out[]=$plain;
  return $out;
}
function digest_body_with_source_links(array $points,array $items): string {
  $src=[]; $seen=[]; $fallbackSource="\u{0418}\u{0441}\u{0442}\u{043E}\u{0447}\u{043D}\u{0438}\u{043A}";
  foreach($items as $it){
    $u=normalize_url((string)($it['url']??'')); if($u===''||isset($seen[$u])) continue; $seen[$u]=true;
    $label=function_exists('rss_news_source_label')?rss_news_source_label($it):(string)($it['src']??'');
    $src[]=['url'=>$u,'label'=>$label!==''?$label:$fallbackSource];
  }
  $lines=[]; $cnt=count($src);
  foreach($points as $i=>$p){
    $block=trim((string)$p); if($block==='') continue;
    $parts=preg_split('~\R~u',$block,2) ?: [$block];
    $headline=trim((string)($parts[0]??'')); $desc=trim((string)($parts[1]??''));
    if($headline==='') continue;
    $line='<b>'.tg_html_escape($headline).'</b>';
    if($cnt>0){
      $s=$src[$i%$cnt];
      $line.=' - <a href="'.tg_html_escape((string)$s['url']).'">'.tg_html_escape((string)$s['label']).'</a>';
    }
    if($desc!=='') $line.="\n".tg_html_escape($desc);
    $lines[]=$line;
  }
  return implode("\n\n",$lines);
}
function build_ai_digest_message(array $items,int $windowStartTs,int $windowEndTs,string $apiKey,string $baseUrl,string $model,array $fallback,float $temp,int $timeout,int $maxItems,int $bullets,string $customPrompt,string $systemOverride,string $userTemplate,string $project,string $tag): ?string {
  $blob=build_digest_input_blob($items,$maxItems); if($blob==='') return null;
  $system=trim($systemOverride)!==''?trim($systemOverride):'You are a Russian financial news editor. Use only provided facts.';
  $template=trim($userTemplate)!==''?trim($userTemplate):"Pick key news for {window_label}.\nOutput {bullet_count} items max, total up to {max_chars} chars.\nNo intro or conclusion.{extra_requirements}\n\nNews:\n{news}";
  $windowLabel=date('d.m.Y H:i',$windowStartTs).' - '.date('H:i',$windowEndTs).' (Europe/Kyiv)';
  $user=strtr($template,['{window_label}'=>$windowLabel,'{bullet_count}'=>(string)$bullets,'{max_chars}'=>'1500','{extra_requirements}'=>$customPrompt!==''?"\n".$customPrompt:'','{news}'=>$blob]);
  $plain=null; foreach(normalize_model_candidates($model,$fallback) as $m){ $plain=openai_digest($apiKey,$baseUrl,$m,$system,$user,$temp,$timeout,$project,$tag); if($plain!==null){ if($m!==$model) cron_log($tag,'DIGEST model fallback used: '.$m); break; } }
  if($plain===null) return null;
  $points=digest_points($plain,max(1,min(5,$bullets))); if($points===[]) return null;
  $body=digest_body_with_source_links($points,$items); if(trim($body)==='') return null;
  return $body;
}
function fallback_digest_emoji(string $headline): string {
  $text=function_exists('mb_strtolower')?mb_strtolower($headline,'UTF-8'):strtolower($headline);
  $urgent=['war','strike','attack','drone','missile','explosion','sanction','urgent','breaking'];
  $politics=['president','prime minister','parliament','government','election','minister','diplomat'];
  $economy=['market','stocks','ipo','inflation','rate','dollar','euro','oil','gas','economy','bitcoin','crypto'];
  foreach($urgent as $kw){ if($kw!=='' && (function_exists('mb_strpos')?mb_strpos($text,$kw)!==false:strpos($text,$kw)!==false)) return "\u{26A0}\u{FE0F}"; }
  foreach($politics as $kw){ if($kw!=='' && (function_exists('mb_strpos')?mb_strpos($text,$kw)!==false:strpos($text,$kw)!==false)) return "\u{1F3DB}\u{FE0F}"; }
  foreach($economy as $kw){ if($kw!=='' && (function_exists('mb_strpos')?mb_strpos($text,$kw)!==false:strpos($text,$kw)!==false)) return "\u{1F4B9}"; }
  return "\u{1F4F0}";
}
function build_local_fallback_digest_message(array $items,int $windowStartTs,int $windowEndTs,int $bullets): ?string {
  $points=[];
  foreach(array_slice($items,0,max(1,min(5,$bullets))) as $it){
    if(!digest_item_is_informative($it)) continue;
    $t=norm_text((string)($it['title']??'')); if($t==='') continue;
    $emoji=fallback_digest_emoji($t);
    $tRu=translate_to_ru_best_effort($t);
    $h=$emoji.' '.$tRu;
    $d=norm_text((string)($it['desc']??''));
    $d=translate_to_ru_best_effort($d);
    if($d!==''){
      if(function_exists('mb_substr')){ $d=mb_substr($d,0,420,'UTF-8'); } else { $d=substr($d,0,420); }
      $points[]=$h."\n".$d;
    } else {
      $points[]=$h;
    }
  }
  if($points===[]) return null;
  $body=digest_body_with_source_links($points,array_slice($items,0,max(1,min(5,$bullets))));
  if(trim($body)==='') return null;
  return $body;
}
function format_rss_moderation_message(array $item): string {
  $base=format_rss_news_message_block([$item]); if($base==='') return '';
  $ts=(int)($item['pubTs']??0);
  $line='<i>'."\u{0414}\u{0430}\u{0442}\u{0430} \u{0432} \u{0438}\u{0441}\u{0442}\u{043E}\u{0447}\u{043D}\u{0438}\u{043A}\u{0435}".': '.tg_html_escape($ts>0?date('d.m.Y H:i',$ts):"\u{043D}\u{0435} \u{0443}\u{043A}\u{0430}\u{0437}\u{0430}\u{043D}\u{043E}").'</i>';
  return "<b>"."\u{1F7E1} \u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0442}\u{044C}".'</b>'."\n{$line}\n\n".$base;
}
function format_rss_digest_moderation_message(string $digestMessage,int $itemsCount,int $windowStartTs,int $windowEndTs): string {
  $w=date('H:i',$windowStartTs).'-'.date('H:i',$windowEndTs);
  return '<b>'."\u{1F7E1} AI-\u{043F}\u{043E}\u{0434}\u{0431}\u{043E}\u{0440}\u{043A}\u{0430} \u{043D}\u{0430} \u{043C}\u{043E}\u{0434}\u{0435}\u{0440}\u{0430}\u{0446}\u{0438}\u{044E}".'</b>' . "\n" .
    '<i>'."\u{041E}\u{043A}\u{043D}\u{043E}".': '.tg_html_escape($w).'; '."\u{0438}\u{0441}\u{0442}\u{043E}\u{0447}\u{043D}\u{0438}\u{043A}\u{043E}\u{0432}".': '.$itemsCount.'</i>' . "\n\n" . $digestMessage;
}

$cfg=load_json($filtersFile);
$promptFile=trim((string)($cfg['digest_prompt_file'] ?? basename($digestPromptFileDefault))); if($promptFile==='') $promptFile=basename($digestPromptFileDefault); if(!preg_match('~^(?:[A-Za-z]:[\\/]|/)~',$promptFile)) $promptFile=__DIR__.'/'.$promptFile;
$promptCfg=load_json($promptFile);
$SINGLE_LATEST_TEST_MODE = (is_file(__DIR__.'/rss_popular_test_single.php') || basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))==='rss_popular_test_single.php');

$ALLOW=is_array($cfg['allow_keywords']??null)?$cfg['allow_keywords']:[];
$BLOCK=is_array($cfg['block_keywords']??null)?$cfg['block_keywords']:[];
$MAX_SEND=(int)($cfg['max_items_to_send']??0);
$WINDOW_SEC=isset($cfg['window_minutes'])?max(1,(int)$cfg['window_minutes'])*60:30*60;
$MAX_FEEDS_PER_SOURCE=max(1,(int)($cfg['max_feeds_per_source']??4));
$MAX_RUNTIME_SEC=max(15,(int)($cfg['max_runtime_sec']??295));
$MODERATION_ENABLED=(bool)($cfg['moderation_enabled']??true);
$MODERATOR_CHAT_ID=trim((string)($cfg['moderator_chat_id'] ?? (getenv('TELEGRAM_MODERATOR_CHAT_ID')?:'')));
$PUBLISH_CHAT_ID=trim((string)($cfg['publish_chat_id'] ?? TELEGRAM_CHAT_ID));
$FRESH_RUN_CLEANUP=(bool)($cfg['fresh_run_cleanup']??true);

$DIGEST_ENABLED=(bool)($cfg['digest_enabled']??false);
$DIGEST_MODE=strtolower(trim((string)($cfg['digest_mode']??($MODERATION_ENABLED?'moderation':'direct')))); if($DIGEST_MODE!=='moderation'&&$DIGEST_MODE!=='direct') $DIGEST_MODE=$MODERATION_ENABLED?'moderation':'direct'; if($DIGEST_MODE==='moderation'&&(!$MODERATION_ENABLED||$MODERATOR_CHAT_ID==='')) $DIGEST_MODE='direct';
$DIGEST_MODEL=trim((string)($cfg['digest_model']??'gpt-4o-mini')); $DIGEST_MODEL_FALLBACK=is_array($cfg['digest_model_fallback']??null)?$cfg['digest_model_fallback']:[];
$DIGEST_TIMEOUT_SEC=max(5,(int)($cfg['digest_timeout_sec']??25)); $DIGEST_TEMPERATURE=(float)($cfg['digest_temperature']??0.2); $DIGEST_MAX_ITEMS=max(3,(int)($cfg['digest_max_items']??18)); $DIGEST_BULLETS=max(1,min(5,(int)($cfg['digest_bullets']??5))); $DIGEST_LOCAL_FALLBACK=(bool)($cfg['digest_local_fallback']??true);
$DIGEST_PROMPT=trim((string)($cfg['digest_prompt']??'')); $extra=trim((string)($promptCfg['extra_requirements']??'')); if($extra!=='') $DIGEST_PROMPT=trim($extra.($DIGEST_PROMPT!==''?"\n".$DIGEST_PROMPT:''));
$DIGEST_SYSTEM_PROMPT=trim((string)($promptCfg['system_prompt']??'')); $DIGEST_USER_PROMPT_TEMPLATE=trim((string)($promptCfg['user_prompt_template']??''));
$OPENAI_API_KEY=trim((string)($cfg['openai_api_key'] ?? (getenv('OPENAI_API_KEY')?:''))); $OPENAI_BASE_URL=trim((string)($cfg['openai_base_url'] ?? (getenv('OPENAI_BASE_URL')?:'https://api.openai.com/v1'))); $OPENAI_PROJECT=trim((string)($cfg['openai_project'] ?? (getenv('OPENAI_PROJECT')?:'')));

cron_log(TAG,'RUN start; fresh_run_cleanup='.($FRESH_RUN_CLEANUP?'1':'0').'; window_sec='.$WINDOW_SEC.'; single_latest_test_mode='.($SINGLE_LATEST_TEST_MODE?'1':'0').'; digest_enabled='.($DIGEST_ENABLED?'1':'0').'; digest_mode='.$DIGEST_MODE.'; digest_model='.$DIGEST_MODEL);

if($FRESH_RUN_CLEANUP){ $cl=clear_moderation_for_fresh_run($queueFile,$editStateFile,$MODERATOR_CHAT_ID,TAG); cron_log(TAG,'MODERATION startup clear items='.(int)$cl['removed_items'].'; sessions='.(int)$cl['removed_sessions'].'; delete_attempted='.(int)$cl['delete_attempted'].'; delete_ok='.(int)$cl['delete_ok']); }

$sourcesRaw=load_json((isset($cfg['sources_file'])?(__DIR__.'/'.trim((string)$cfg['sources_file'])):$sourcesFileDefault));
$sources=is_array($sourcesRaw['sources']??null)?$sourcesRaw['sources']:[];
$now=time(); $WINDOW_END_TS=(intdiv($now,$WINDOW_SEC)*$WINDOW_SEC); $WINDOW_START_TS=$WINDOW_END_TS-$WINDOW_SEC;
cron_log(TAG,'SOURCES total='.count($sources).'; window_sec='.$WINDOW_SEC.'; window_start='.date('Y-m-d H:i:s',$WINDOW_START_TS).'; window_end='.date('Y-m-d H:i:s',$WINDOW_END_TS).'; max_runtime_sec='.$MAX_RUNTIME_SEC.'; max_feeds_per_source='.$MAX_FEEDS_PER_SOURCE.'; single_latest_test_mode='.($SINGLE_LATEST_TEST_MODE?'1':'0'));

$allItems=[]; $fetched=0; $started=microtime(true);
foreach($sources as $src){ if((microtime(true)-$started)>=$MAX_RUNTIME_SEC){ cron_log(TAG,'STOP runtime budget exceeded'); break; }
  if(!is_array($src)) continue; $name=trim((string)($src['name']??'RSS')); $site=(string)($src['url']??''); $feeds=array_slice(is_array($src['feeds']??null)?$src['feeds']:[],0,$MAX_FEEDS_PER_SOURCE);
  cron_log(TAG,'SOURCE prepared name='.$name.'; site='.$site.'; feeds='.count($feeds));
  foreach($feeds as $feed){ if((microtime(true)-$started)>=$MAX_RUNTIME_SEC){ cron_log(TAG,'STOP runtime budget exceeded during feed loop'); break 2; }
    $feedUrl=normalize_url((string)$feed); if($feedUrl==='') continue; $raw=fetch_feed($feedUrl); cron_log(TAG,'FEED fetch src='.$name.'; url='.$feedUrl.'; http='.(is_string($raw)?200:0).'; err='.(is_string($raw)?'':'fetch_failed')); if(!is_string($raw)) continue;
    $fetched++; $items=parse_feed_items($raw,$name); cron_log(TAG,'FEED parsed src='.$name.'; url='.$feedUrl.'; items='.count($items)); foreach($items as $it) $allItems[]=$it;
  }
}
if($fetched===0){ send_to_telegram('Warning '.TAG.': all RSS feeds are unavailable', TAG); exit; }

$candByUrl=[]; $latestBySource=[];
foreach($allItems as $it){ $url=normalize_url((string)($it['url']??'')); $title=norm_text((string)($it['title']??'')); $desc=norm_text((string)($it['desc']??'')); $src=trim((string)($it['src']??'RSS')); $pub=(int)($it['pubTs']??0); if($title===''||$url===''||$pub<=0) continue;
  if(!$SINGLE_LATEST_TEST_MODE){ if($pub<$WINDOW_START_TS||$pub>=$WINDOW_END_TS) continue; $blob=$title.' '.$desc.' '.$src; if($BLOCK!==[]&&contains_any($blob,$BLOCK)) continue; if($ALLOW!==[]&&!contains_any($blob,$ALLOW)) continue; }
  if($SINGLE_LATEST_TEST_MODE){ if(!isset($latestBySource[$src])||$pub>(int)$latestBySource[$src]['pubTs']) $latestBySource[$src]=['title'=>$title,'desc'=>$desc,'url'=>$url,'src'=>$src,'pubTs'=>$pub]; continue; }
  if(!isset($candByUrl[$url])||$pub>(int)$candByUrl[$url]['pubTs']) $candByUrl[$url]=['title'=>$title,'desc'=>$desc,'url'=>$url,'src'=>$src,'pubTs'=>$pub];
}
$candidates=$SINGLE_LATEST_TEST_MODE?array_values($latestBySource):array_values($candByUrl); usort($candidates, static fn(array $a,array $b): int => ((int)$a['pubTs'] <=> (int)$b['pubTs']));
if($SINGLE_LATEST_TEST_MODE) cron_log(TAG,'TEST_MODE single_latest_per_source='.count($candidates));
cron_log(TAG,'CANDIDATES count='.count($candidates)); if($candidates===[]) exit;
$toSend=$MAX_SEND>0?array_slice($candidates,0,$MAX_SEND):$candidates;

$digestMessage=null; $digestItems=array_values(array_filter($toSend, static fn(array $it): bool => digest_item_is_informative($it)));
if($DIGEST_ENABLED){
  if($digestItems===[]){
    cron_log(TAG,'DIGEST skipped: no informative items after filter');
  } else {
    $digestMessage=build_ai_digest_message($digestItems,$WINDOW_START_TS,$WINDOW_END_TS,$OPENAI_API_KEY,$OPENAI_BASE_URL,$DIGEST_MODEL,$DIGEST_MODEL_FALLBACK,$DIGEST_TEMPERATURE,$DIGEST_TIMEOUT_SEC,$DIGEST_MAX_ITEMS,$DIGEST_BULLETS,$DIGEST_PROMPT,$DIGEST_SYSTEM_PROMPT,$DIGEST_USER_PROMPT_TEMPLATE,$OPENAI_PROJECT,TAG);
    if($digestMessage===null && $DIGEST_LOCAL_FALLBACK){
      $digestMessage=build_local_fallback_digest_message($digestItems,$WINDOW_START_TS,$WINDOW_END_TS,$DIGEST_BULLETS);
      if($digestMessage!==null) cron_log(TAG,'DIGEST local fallback generated');
    }
  }
  if($digestMessage===null) cron_log(TAG,'DIGEST failed; fallback_to_regular=1'); else cron_log(TAG,'DIGEST ready; mode='.$DIGEST_MODE.'; items='.count($digestItems).'; chars='.mb_strlen($digestMessage,'UTF-8'));
}

if($digestMessage!==null && $DIGEST_MODE==='direct'){ $resp=telegram_send_html_message($PUBLISH_CHAT_ID,$digestMessage,TAG); $ok=$resp!==null; cron_log(TAG,$ok?'DIGEST_SEND ok; count='.count($digestItems):'DIGEST_SEND failed'); exit; }

if($MODERATION_ENABLED && $MODERATOR_CHAT_ID!==''){
  $queue=load_moderation_queue($queueFile); prune_moderation_queue($queue,$now,$WINDOW_SEC); $queued=0;
  foreach($toSend as $item){ $url=(string)$item['url']; if(!$SINGLE_LATEST_TEST_MODE&&queue_has_pending_for_url($queue,$url)) continue; $txt=format_rss_moderation_message($item); if($txt==='') continue;
    $id=generate_moderation_id($url,$now); $resp=telegram_send_html_message($MODERATOR_CHAT_ID,$txt,TAG,moderation_reply_markup($id)); if($resp===null){ cron_log(TAG,'MODERATION send failed; url='.$url); continue; }
    $mid=(int)($resp['result']['message_id']??0); $queue['items'][$id]=['id'=>$id,'title'=>(string)$item['title'],'desc'=>(string)($item['desc']??''),'url'=>$url,'src'=>(string)$item['src'],'pubTs'=>(int)$item['pubTs'],'status'=>'pending','createdAt'=>$now,'moderatorChatId'=>$MODERATOR_CHAT_ID,'moderationMessageId'=>$mid,'publishChatId'=>$PUBLISH_CHAT_ID];
    $queued++;
  }
  $queuedDigest=0;
  if($digestMessage!==null && $DIGEST_MODE==='moderation'){
    $dId=generate_moderation_id('digest|'.$WINDOW_END_TS,$now); $dTxt=format_rss_digest_moderation_message($digestMessage,count($digestItems),$WINDOW_START_TS,$WINDOW_END_TS);
    $r=telegram_send_html_message($MODERATOR_CHAT_ID,$dTxt,TAG,moderation_reply_markup($dId)); if($r===null){ cron_log(TAG,'MODERATION digest send failed'); } else { $mid=(int)($r['result']['message_id']??0); $queue['items'][$dId]=['id'=>$dId,'title'=>'AI digest','desc'=>'','url'=>'','src'=>'AI','pubTs'=>(int)$WINDOW_END_TS,'publishText'=>$digestMessage,'status'=>'pending','createdAt'=>$now,'moderatorChatId'=>$MODERATOR_CHAT_ID,'moderationMessageId'=>$mid,'publishChatId'=>$PUBLISH_CHAT_ID]; $queuedDigest=1; }
  }
  save_json_atomic($queueFile,$queue); cron_log(TAG,'MODERATION queued='.$queued.'; queued_digest='.$queuedDigest); exit;
}

$msg=format_rss_news_message_block($toSend); if($msg===''){ cron_log(TAG,'SEND skipped; empty formatter output'); exit; }
$resp=telegram_send_html_message($PUBLISH_CHAT_ID,$msg,TAG); $ok=$resp!==null; cron_log(TAG,$ok?'SEND ok; count='.count($toSend):'SEND failed');
