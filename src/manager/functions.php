<?php
# Functions
function json($value) {
	if (is_array($value) || is_object($value)) {
		return json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
	} else {
		return json_decode($value, true);
	}
}
function dump($value) {
	ob_start();
	var_dump($value);
	$output = ob_get_contents();
	ob_end_clean();
	return $output;
}
function protect() {
	global $bot;
	$protection = "{$bot->UpdateID()}.run";
	if (!file_exists($protection)) file_put_contents($protection, '1');
	else exit;
	$protection = realpath($protection);
	$string = "register_shutdown_function(function() { @unlink('{$protection}'); });";
	eval($string);
	return $protection;
}
function fakepath($path) {
	$path = realpath($path);
	$path = relativePath($path);
	$path = $path == ''? '.' : $path;
	return $path;
}
/**
	* Return relative path between two sources
	* @param $to
	* @param $from
	* @param string $separator
	* @return string
	*/
function relativePath($to, $from = null, $separator = DIRECTORY_SEPARATOR) {
	if (!$from) $from = realpath('.');
	$from = str_replace(array('/', '\\'), $separator, $from);
	$to = str_replace(array('/', '\\'), $separator, $to);

	$arFrom = explode($separator, rtrim($from, $separator));
	$arTo = explode($separator, rtrim($to, $separator));
	while(count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])) {
		array_shift($arFrom);
		array_shift($arTo);
	}

	return str_pad("", count($arFrom) * 3, '..'.$separator).implode($separator, $arTo);
}
function linter($code, $name) {
	global $bot;
	$filename = "{$name}_{$bot->UpdateID()}.php";
	file_put_contents($filename, $code);
	$lint = shell_exec('php -l '.$filename);
	$lint = str_replace($filename, $name, $lint);
	unlink($filename);
	return $lint;
}
function format_size($bytes, $precision = 2) {
	$units = array(
		'B',
		'KB',
		'MB',
		'GB',
		'TB'
	);
	if (($bytes > 0 && $bytes < 1) || ($bytes < 0 && $bytes > -1)) {
		return $bytes.' B';
	}
	#$bytes = max($bytes, 0); # if $bytes is negative, max return 0
	if ($negative = ($bytes < 0)) {
		$bytes *= -1;
	}
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return ($negative? '-' : '').round($bytes, $precision) . ' ' . $units[$pow];
}
function get_perms($file) {
	$perms = fileperms($file);
	switch ($perms & 0xF000) {
		case 0xC000: // socket
			$info = 's';
			break;
		case 0xA000: // symbolic link
			$info = 'l';
			break;
		case 0x8000: // regular
			$info = 'r';
			break;
		case 0x6000: // block special
			$info = 'b';
			break;
		case 0x4000: // directory
			$info = 'd';
			break;
		case 0x2000: // character special
			$info = 'c';
			break;
		case 0x1000: // FIFO pipe
			$info = 'p';
			break;
		default: // unknown
			$info = 'u';
	}
	
	// Owner
	$info .= (($perms & 0x0100) ? 'r' : '-');
	$info .= (($perms & 0x0080) ? 'w' : '-');
	$info .= (($perms & 0x0040) ?
		(($perms & 0x0800) ? 's' : 'x' ) :
		(($perms & 0x0800) ? 'S' : '-'));
	
	// Group
	$info .= (($perms & 0x0020) ? 'r' : '-');
	$info .= (($perms & 0x0010) ? 'w' : '-');
	$info .= (($perms & 0x0008) ?
		(($perms & 0x0400) ? 's' : 'x' ) :
		(($perms & 0x0400) ? 'S' : '-'));
	
	// World
	$info .= (($perms & 0x0004) ? 'r' : '-');
	$info .= (($perms & 0x0002) ? 'w' : '-');
	$info .= (($perms & 0x0001) ?
		(($perms & 0x0200) ? 't' : 'x' ) :
		(($perms & 0x0200) ? 'T' : '-'));
	
	return $info;
}
function mp($token = null) {
	global $mp;
	if (!file_exists('madeline/madeline.php')) {
		if (!file_exists('madeline')) {
			mkdir('madeline');
		} else if (!is_dir('madeline')) {
			copy('madeline', 'old_madeline');
			mkdir('madeline');
		}
		copy('https://phar.madelineproto.xyz/madeline.php', 'madeline/madeline.php');
	}
	include_once 'madeline/madeline.php';
	$settings = [
		'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
		'logger'	=> ['logger' => 2, 'logger_param' => 'madeline/bot.log', 'logger_level' => \danog\MadelineProto\Logger::ULTRA_VERBOSE]
	]; 
	$is_logged = file_exists('madeline/bot.session');
	$mp = new danog\MadelineProto\API('madeline/bot.session', $settings);
	
	if (!$is_logged) { #|| $mp->get_self()['id'] != json_decode(file_get_contents("https://api.telegram.org/bot{$token}/getMe"))->result->id) {
		if (!$token)
			throw new Error("The token passed to mp() is invalid");
		$mp->bot_login($token);
	}
	return $mp;
}
class MPSend {
	public $mp;
	
	public function __construct($token = null) {
		$this->mp = mp($token);
	}
	
	public function doc($path, $params = []) {
		$caption = '';
		extract($params);
		global $bot;
		$chat_id = $bot->ChatID() ?? $bot->UserID();
		
		$name = basename($path);
		$filesize = filesize($path);
		$size = format_size($filesize);
		
		$msg_text = "Uploading <i>{$name}</i> ({$size})...";
		if (isset($postname)) {
			$realname = $name;
			$name = $postname;
			$msg_text = "Uploading <i>{$realname}</i> ({$size}) as <i>{$name}</i>...";
		}
		$msg = $bot->send($msg_text);
		$start_time = microtime(1);
		$progress = function ($progress) use ($name, $msg_text, $msg, $size, $start_time, $filesize) {
			static $last_time = 0;
			if ((microtime(true) - $last_time) < 1) return;
			$uploaded = ($filesize/100)*$progress;
			$speed = $uploaded/(microtime(1) - $start_time); # bytes per second
			$speed = format_size($speed).'/s';
			$round = round($progress, 2);
			$msg->edit("{$msg_text} {$round}%\n\nSpeed: {$speed}");
			$last_time = microtime(true);
		};
		try {
			$this->mp->messages->setTyping([
				'peer' => $chat_id,
				'action' => ['_' => 'sendMessageUploadDocumentAction', 'progress' => 0],
			]);
			
			$start = microtime(1);
			$sentMessage = $this->mp->messages->sendMedia([
				'peer' => $chat_id,
				'media' => [
					'_' => 'inputMediaUploadedDocument',
					'file' => new danog\MadelineProto\FileCallback($path, $progress),
					'attributes' => [
						['_' => 'documentAttributeFilename', 'file_name' => $name],
					]
				],
				'message' => $caption,
				'parse_mode' => 'HTML'
			]);
			$end = microtime(1);
		} catch (Throwable $t) {
			$msg->edit("Failed: {$t->getMessage()} on line {$t->getLine()} of {$t->getFile()}", ['parse_mode' => null]);
			$bot->log("{$t->getMessage()} on line {$t->getLine()} of {$t->getFile()}");
			return ['ok' => false, 'err' => $t];
		}
		$msg->delete();
		return ['ok' => true, 'duration' => ($end-$start)];
	}
	
	public function vid($path, $params = []) {
		$caption = '';
		extract($params);
		global $bot;
		$chat_id = $bot->ChatID() ?? $bot->UserID();
		
		$name = basename($path);
		$filesize = filesize($path);
		$size = format_size($filesize);
		
		$msg_text = "Uploading <i>{$name}</i> ({$size})...";
		if (isset($postname)) {
			$realname = $name;
			$name = $postname;
			$msg_text = "Uploading <i>{$realname}</i> ({$size}) as <i>{$name}</i>...";
		}
		$msg = $bot->send($msg_text);
		$start_time = microtime(1);
		$progress = function ($progress) use ($name, $msg_text, $msg, $size, $start_time, $filesize) {
			static $last_time = 0;
			if ((microtime(true) - $last_time) < 1) return;
			$uploaded = ($filesize/100)*$progress;
			$speed = $uploaded/(microtime(1) - $start_time); # bytes per second
			$speed = format_size($speed).'/s';
			$round = round($progress, 2);
			$msg->edit("{$msg_text} {$round}%\n\nSpeed: {$speed}");
			$last_time = microtime(true);
		};
		try {
			$this->mp->messages->setTyping([
				'peer' => $chat_id,
				'action' => ['_' => 'sendMessageUploadDocumentAction', 'progress' => 0],
			]);
			
			if (!file_exists('ffmpeg.phar')) {
				copy('ffmpeg.phar', 'ffmpeg.phar');
			}
			require_once 'ffmpeg.phar';
			$ffprobe = FFMpeg\FFProbe::create();
			$duration = $ffprobe
				->format($path) // extracts file informations
				->get('duration');             // returns the duration property
				
			$sec = round($duration/4);
			
			$thumbnail = $path.time().'.png';
			$ffmpeg = FFMpeg\FFMpeg::create();
			$video = $ffmpeg->open($path);
			$frame = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($sec));
			$frame->save($thumbnail);
			
			$start = microtime(1);
			$sentMessage = $this->mp->messages->sendMedia([
				'peer' => $chat_id,
				'media' => [
					'_' => 'inputMediaUploadedDocument',
					'file' => new danog\MadelineProto\FileCallback($path, $progress),
					'thumb' => $thumbnail,
					'mime_type' => mime_content_type($path),
					'attributes' => [
						['_' => 'documentAttributeVideo', 'supports_streaming' => true, 'duration' => $duration],
					]
				],
				'message' => $caption,
				'parse_mode' => 'HTML'
			]);
			$end = microtime(1);
		} catch (Throwable $t) {
			$msg->edit("Failed: {$t->getMessage()} on line {$t->getLine()} of {$t->getFile()}", ['parse_mode' => null]);
			$bot->log("{$t->getMessage()} on line {$t->getLine()} of {$t->getFile()}");
			return ['ok' => false, 'err' => $t];
		}
		$msg->delete();
		@unlink($thumbnail);
		return ['ok' => true, 'duration' => ($end-$start)];
	}
	
	public function indoc($text, $name = null) {
		if (!is_string($text) && !is_int($text)) {
			ob_start();
			var_dump($text);
			$text = ob_get_contents();
			ob_end_clean();
		}
		
		for ($i=0; $i<50; $i++) {
			$tempname = "indoc_{$i}.txt";
			if (!file_exists($tempname)) break;
		}
		
		file_put_contents($tempname, $text);
		$res = $this->doc($tempname, ['postname' => $name]);
		unlink($tempname);
		return $res;
	}
	
	public function get($file_id, $name) {
		global $bot;
		$chat_id = $bot->ChatID() ?? $bot->UserID();
		
		@unlink($name);
		$info = $this->mp->get_download_info($file_id);
		$filename = "{$info['name']}{$info['ext']}";
		$size = $info['size'];
		$filesize = format_size($size);
		$msg_text = "Downloading <i>{$filename}</i> ({$filesize}) to the server...";
		if ($filename != $name) {
			$msg_text = "Downloading <i>{$filename}</i> ({$filesize}) to the server as <code>{$name}</code>...";
		}
		$msg = $bot->send($msg_text);
		$start_time = microtime(1);
		$progress = function ($progress) use ($name, $msg_text, $msg, $filename, $filesize, $start_time, $size) {
			static $last_time = 0;
			if ((microtime(true) - $last_time) < 1) return;
			$uploaded = ($size/100)*$progress;
			$speed = $uploaded/(microtime(1) - $start_time); # bytes per second
			$speed = format_size($speed).'/s';
			$round = round($progress, 2);
			$msg->edit("{$msg_text} {$round}%\n\nSpeed: {$speed}");
			$last_time = microtime(true);
		};
		
		try {
			$start = microtime(1);
			$path = $this->mp->download_to_file($file_id, new danog\MadelineProto\FileCallback($name, $progress));
			$end = microtime(1);
		} catch (Throwable $t) {
			$msg->edit("Failed: {$t->getMessage()} on line {$t->getLine()} of {$t->getFile()}", ['parse_mode' => null]);
			return ['ok' => false, 'err' => $t];
		}
		$msg->delete();
		return ['ok' => true, 'path' => $path, 'info' => $info, 'duration' => ($end-$start)];
	}
}
/*
function post_without_wait($url, $params = []) {
	$post_params = [];
	foreach ($params as $key => &$val) {
		if (is_array($val)) $val = implode(',', $val);
		$post_params[] = $key.'='.urlencode($val);
	}
	$post_string = implode('&', $post_params);

	$parts = parse_url($url);

	$fp = fsockopen($parts['host'],
		isset($parts['port'])?$parts['port']:80,
		$errno, $errstr, 30);

	$out = "POST {$parts['path']} HTTP/1.1\r
Host: {$parts['host']}\r
Content-Type: application/x-www-form-urlencoded\r
Content-Length: ".strlen($post_string)."\r
Connection: Close\r\n\r\n";
	if (isset($post_string)) $out.= $post_string;

	fwrite($fp, $out);
	fclose($fp);
}
*/
function post_without_wait($url, $params = []) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_exec($ch);
}
function fast_request($url, $params)
{
	foreach ($params as $key => &$val) {
	  if (is_array($val)) $val = implode(',', $val);
		$post_params[] = $key.'='.urlencode($val);
	}
	$post_string = implode('&', $post_params);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_USERAGENT, 'curl');
	curl_setopt($ch, CURLOPT_TIMEOUT, 1);
	$result = curl_exec($ch);
	curl_close($ch);
}
define('DS', '/');	
function delTree($folder, $del_root=true) {
	$folder = trim($folder, DS) . DS;
	$files = glob($folder.'*', GLOB_MARK);
	if (count($files) > 0) {
		foreach($files as $element) {
			if (is_dir($element)) {
				delTree($element);
			} else {
				unlink($element);
			}
		}
	}
	if ($del_root) rmdir($folder);

	return !file_exists($folder);
}

function glob_recursive($pattern, $flags = 0, $startdir = '') {
	$files = glob($startdir.$pattern, $flags);
	foreach (glob($startdir.'*', GLOB_ONLYDIR|GLOB_NOSORT|GLOB_MARK) as $dir) {
		$files = array_merge($files, glob_recursive($pattern, $flags, $dir));
	}
	sort($files);
	return $files;
}
function folderToZip($folder, &$zipFile, $exclusiveLength, array $except = []) {
	$files = glob_recursive($folder.'/*');
	$except = array_merge($except, ['..', '.']);
	foreach ($files as $filePath) {
		if (in_array(basename($filePath), $except)) continue;
		// Remove prefix from file path before add to zip. 
		$localPath = substr($filePath, $exclusiveLength); 
		if (is_file($filePath)) {
			$zipFile->addFile($filePath, $localPath);
		} else if (is_dir($filePath)) {
			// Add sub-directory. 
			$zipFile->addEmptyDir($localPath); 
			folderToZip($filePath, $zipFile, $exclusiveLength, $except);
		}
	}
}

function zipDir($sourcePath, $outZipPath, array $except = []) {
	global $bot;
	#$bot->send(json_encode(compact('sourcePath', 'outZipPath', 'except'), 480));
	@unlink($outZipPath);
	$zip = new ZipArchive(); 
	$res = $zip->open($outZipPath, ZIPARCHIVE::CREATE);
	#global $bot; $bot->send($res);
	folderToZip($sourcePath, $zip, strlen($sourcePath), $except); 
	#$zip->close();
	return $zip;
}

function unzipDir($zipPath, $outDirPath) {
	if (@file_exists($outDirPath) != true) {
		mkdir($outDirPath, 0777, true);
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath)) {
		$zip->extractTo($outDirPath);
	}
	$zip->close();
}
function convert_time($time) {
	if ($time == 0) {
		return 0;
	}
	// valid units
	$unit=array(-4=>'ps', -3=>'ns',-2=>'mcs',-1=>'ms',0=>'s');
	// logarithm of time in seconds based on 1000
	// take the value no more than 0, because seconds, we have the last thousand variable, then 60
	$i=min(0,floor(log($time,1000)));

	// here we divide our time into a number corresponding to units of measurement i.e. per million for seconds,
	// per thousand for milliseconds
	$t = @round($time/pow(1000,$i) , 1);
	if ($i === 0 && $t >= 60) {
		$minutes = floor($t/60);
		$remaining_s = round($t-($minutes*60));
		if ($remaining_s) {
			return "{$minutes}m{$remaining_s}s";
		} else {
			return "{$minutes}min";
		}
	}
	return $t.$unit[$i];
}