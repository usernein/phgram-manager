<?php
function handle($bot, $db, $lang, $args) {
	extract($args);
	
	$type = $bot->getUpdateType();
	$data = array_values($bot->getData())[1];
	
	if ($type == 'inline_query') {
		$iq = $bot->InlineQuery();
		$query = $iq['query'];
		
		if (preg_match('#^[\w\.]+$#', $query)) {
			$results = [
				['id' => $query, 'thumb_url' => 'https://souunick.com/thumbs/search.png', "🔎 Search for $query in the files...", "🔎 <i>Searching</i>", ikb([ [ ['...', 'im searching boii'] ] ])],
			];
			$results = toInline($results);
			$bot->answer_inline($results);
		} else {
			$results = [
				['thumb_url' => 'https://souunick.com/thumbs/search.png', "Invalid query :(", "Only <b>letters, numbers, underscore and dot</b> characters are accepted for searching."],
			];
			$results = toInline($results);
			$bot->answer_inline($results);
		}
	}
	else if ($type == 'chosen_inline_result') {
		if (!isset($data['inline_message_id'])) return;
		$inline_id = $data['inline_message_id'];
		$query = $data['result_id'];
		if (!$query) return;
		$user_id = $bot->UserID();
		
		$files = rglob($query);
		$keyb = [];
		foreach ($files as $file) {
			$dir = is_dir($file);
			$keyb[] = [ [($dir? "📁 " : "📃 ").$file, $dir? "list $file" : "file $file"] ];
		}
		
		$query = htmlspecialchars($query);
		if ($files) {
			$keyb = i_ikb($keyb);
			$bot->edit("🗂 <b>Results for</b> \"<code>$query</code>\":", ['inline_message_id' => $inline_id, 'reply_markup' => $keyb]);
		} else {
			$bot->edit("<b>No results for</b> \"<code>$query</code>\" :(", ['inline_message_id' => $inline_id]);
		}
	}
	else if ($type == 'callback_query') {
		$call = $data['data'];
		$call_id = $data['id'];
		$user_id = $data['from']['id'];
		$user = $db->query("SELECT * FROM users WHERE id={$user_id}")->fetch();
		$message_id = $bot->MessageID();
		$call = realcall($call);
		
		if (preg_match('#^list (?<path>.+)#', $call, $match)) {
			$path = $match['path'] ?? '.';
			list_dir:
			$path = fakepath($path);
			
			$dirs = $files = [[]];
			$dirs_line = $files_line = 0;
			
			foreach (scandir($path) as $filename) {
				if (in_array($filename, ['.', '..'])) continue;
				
				$path_to_file = $path.'/'.$filename;
				if (@is_dir($path_to_file)) {
					if ($cfg->grouped_list && count($dirs[$dirs_line]) != $cfg->grouped_columns) {
						$dirs[$dirs_line][] = ["📁 {$filename}", "list {$path_to_file}"];
					} else {
						$dirs_line++;
						$dirs[$dirs_line] = [ ["📁 {$filename}", "list {$path_to_file}"] ];
					}
				} else {
					if ($cfg->grouped_list && count($files[$files_line]) != $cfg->grouped_columns) {
						$files[$files_line][] = ["📄 {$filename}", "file {$path_to_file}"];
					} else {
						$files_line++;
						$files[$files_line] = [ ["📄 {$filename}", "file {$path_to_file}"] ];
					}
				}
			}
				
			$options = array_merge($dirs, $files);
			$tools = [ ['➕', "mkdir {$path}"], ['⏫', "upload {$path}"], ['📦', "zip {$path}"] ];
			# if (!$bot->is_private()) unset($tools[1]);
			if ($user['show_rmdir']) {
				$tools[] = ['🗑', "rmdir {$path}"];
			}
			$options[] = $tools;
			if (@is_dir($path.'/..')) {
				$options[] = [ ['..', "list {$path}/.."] ];
			}
			
			$keyboard = i_ikb($options);
			$bot->edit("📁 <b>Listing</b> <code>{$path}</code>", ['reply_markup' => $keyboard]);
		}
	
		else if (preg_match('#^file (?<path>.+)#', $call, $match)) {
			$file = $match['path'];
			$dir = dirname($file);
			$name = basename($file);
			
			$size = filesize($file);
			$size = format_size($size);
			
			$modified_time = new DateTime('@'. filemtime($file));
			$now = new DateTime('now');
			$d = $modified_time->diff($now, true);
			if ($d->d > 1) $last_modify = "{$d->d} days ago";
			else if ($d->h > 1) $last_modify = "{$d->h} hours ago";
			else if ($d->i > 1) $last_modify = "{$d->i} minutes ago";
			else if ($d->s >= 1) $last_modify = "{$d->s} seconds ago";
			else if ($d->s == 0) $last_modify = "right now";
			else $last_modify = "at ".date('d.m.y, H:i')." (".date_default_timezone_get().")";
			
			$owner = function_exists('posix_getpwuid')? posix_getpwuid(fileowner($file))['name'] : '-';
			$gowner = function_exists('posix_getpwuid')? posix_getgrgid(filegroup($file))['name'] : '-';
			
			$accessed_time = new DateTime('@'. fileatime($file));
			$now = new DateTime('now');
			$d = $accessed_time->diff($now, true);
			if ($d->d > 1) $last_access = "{$d->d} days ago";
			else if ($d->h > 1) $last_access = "{$d->h} hours ago";
			else if ($d->i > 1) $last_access = "{$d->i} minutes ago";
			else if ($d->s >= 1) $last_access = "{$d->s} seconds ago";
			else if ($d->s == 0) $last_access = "right now";
			else $last_access = "at ".date('d.m.y, H:i')." (".date_default_timezone_get().")";
			
			$perms = substr(sprintf('%o', fileperms($file)), -4);
			$lperms = get_perms($file);
			$mime = mime_content_type($file);
			$mime1 = explode('/', $mime)[0];
			
			$str = "📄 <b>{$name}</b>:
- Path: <pre>{$file}</pre>
- Permissions: {$lperms} ({$perms})
- Owner: {$owner}:{$gowner}
- Mime type: {$mime}
- Size: {$size}
- Last modified {$last_modify}
- Last accessed {$last_access}";
			$lines = [
				[ ['⏬', "download {$file}"], ['🗑', "delete {$file}"] ]
			];
			if ($mime1 == 'video') { # if the file is a file and shell_exec is supported
				$lines[] = [ ['⏬🎞 (streaming)', "download_vid {$file}"] ];
			} else if ($mime1 == 'image') {
				$lines[] = [ ['⏬🖼', "download_img {$file}"] ];
			}
			$lines[] = [ ['«', "list {$dir}"] ];
			$keyb = i_ikb($lines);
			$bot->edit($str, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^download (?<path>.+)#', $call, $match)) {
			$mp = new MPSend($bot->bot_token);
			$res = $mp->doc($match['path']);
			if (!$res['ok']) {
				$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $res->err->getMessage(), 'show_alert' => TRUE]);
			}
		}
		
		else if (preg_match('#^download_vid (?<path>.+)#', $call, $match)) {
			#Downloading getID3
			if (!file_exists('manager/getid3')) {
				$bot->send('Installing getID3... Should happen only now');
				$name = 'getid3'.time().'.zip';
				copy('https://github.com/JamesHeinrich/getID3/archive/master.zip', $name);
				$zip = new ZipArchive;
				$zip->open($name);
				$files = [];
				for ($i=0; $i < $zip->numFiles; $i++) {
					$entry = $zip->getFromIndex($i);
					$entry_name = $zip->getNameIndex($i);
					if (strpos($entry_name, '/getid3/')) {
						if ($entry) {
							$savename = 'manager/getid3/'.substr($entry_name, strpos($entry_name, '/getid3/')+8);
							$dirname = dirname($savename);
							if (!is_dir($dirname)) mkdir($dirname, 0755, true);
							file_put_contents($savename,  $entry);
						}
					}
				}
				$zip->close();
				unlink($name);
			}
			
			require_once 'manager/getid3/getid3.php';
			$mp = new MPSend($bot->bot_token);
			$res = $mp->vid($match['path']);
			if (!$res['ok']) {
				$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $res->err->getMessage(), 'show_alert' => TRUE]);
			}
		}
		
		else if (preg_match('#^download_img (?<path>.+)#', $call, $match)) {
			$mp = new MPSend($bot->bot_token);
			$res = $mp->img($match['path']);
			if (!$res['ok']) {
				$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $res->err->getMessage(), 'show_alert' => TRUE]);
			}
		}
		
		else if (preg_match('#^delete (?<path>.+)#', $call, $match)) {
			ini_set('track_errors', 'on');
			if (unlink($match['path'])) {
				$path = dirname($match['path']);
				goto list_dir;
			} else {
				@$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $php_errormsg, 'show_alert' => true]);
			}
			ini_set('track_errors', 'off');
		}
		
		else if (preg_match('#^mkdir (?<dir>.+)#', $call, $match)) {
			$dir = $match['dir'];
			$keyboard = i_ikb([
				[ ['❌ Cancel', "cancel mkdir {$dir}"] ]
			]);
			$path = $dir;
			$dir = basename(realpath($dir));
			$bot->edit("Now send the name for the new directory, to add inside {$dir}.", ['reply_markup' => $keyboard]);
			$db->query("UPDATE users SET waiting_for='mkdir_name', waiting_param='{$path}', waiting_back='list {$path}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^cancel (?<cmd>.+) (?<param>.+)#', $call, $match)) {
			extract($match);
			$db->query("UPDATE users SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
			if ($cmd == 'mkdir') {
				$path = $param;
				goto list_dir;
			} else if ($cmd == 'auto-upload') {
				$db->query("UPDATE users SET auto_upload=0, upload_path='.' WHERE id={$user_id}");
				$path = $param;
				goto list_dir;
			}
		}
		
		else if (preg_match('#^upload (?<dir>.+)#', $call, $match)) {
			if ($bot->is_private()) {
				$dir = $match['dir'];
				$db->query("UPDATE users SET auto_upload=1, upload_path='{$dir}' WHERE id={$user_id}");
				load_upload:
				$php_check_active = $db->querySingle("SELECT php_check FROM users WHERE id={$user_id}");
				$keyboard = i_ikb([
					[ ['❌ Stop', "cancel auto-upload {$dir}"] ],
					[ [($php_check_active? '❌ Dis' : '✔ En').'able php linter', "switch php_check {$dir}"] ],
				]);
				
				$bot->edit("The <b>auto-upload</b> feature has been activated.
Send any documents, as many as you want, and it will be automatically uploaded to the folder <b>{$dir}</b>", ['reply_markup' => $keyboard]);
			} else {
				$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => 'Private-only command!', 'show_alert' => true]);
			}
		}
		
		else if (preg_match('#^switch (?<option>.+) (?<param>.+)#', $call, $match)) {
			extract($match);
			if ($option == 'php_check') {
				$db->query("UPDATE users SET php_check=(php_check <> 1) WHERE id={$user_id}");
				$dir = $param;
				goto load_upload;
			}
		}
		
		else if (preg_match('#^rmdir (?<path>.+)#', $call, $match)) {
			$path = $match['path'];
			$text = "‼️ <b>Are you sure you want to delete this entire directory? This action is recursive (will also delete all subcontents) and irreversible.</b>";
			$keyb = i_ikb([
				[ ['🗑 Yes, delete the folder!', "confirm_rmdir $path"] ],
				[ ['📁 No, cancel please.', "list $path"] ]
			]);
			$bot->edit($text, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^confirm_rmdir (?<path>.+)#', $call, $match)) {
			$path = $match['path'];
			$name = basename(realpath($path)).'.zip';
			$zip = zipDir($path, $name);
			#$bot->send(print_r($zip, 1));
			$name = $zip->filename;
			$zip->close();
			$mp = new MPSend($bot->bot_token);
			$mp->doc($name, ['caption' => "📦 Backup of $path"]);
			unlink($name);
			try {
				delTree($path);
			} catch (Throwable $t) {
				$bot->log(/*Throwable*/$t);
			}
			$path = dirname($match['path']);
			goto list_dir;
		}
		
		else if (preg_match('#^zip (?<path>.+)#', $call, $match)) {
			$path = $match['path'];
			if (file_exists($path) && is_dir($path)) {
				$name = basename(realpath($path)).'.zip';
				$msg = $bot->send("Zipping $path into $name...");
				$zip = zipDir(realpath($path), $name);
				$msg->edit("$path has been zipped into $name!");
				$name = $zip->filename;
				$zip->close();
				$mp = new MPSend($bot->bot_token);
				$mp->doc($name);
				unlink($name);
				$msg->delete();
			} else {
				$bot->send("❕ {$path} isn't available.");
			}
		}
		
		else if ($call == 'upgrade') {
			goto upgrade;
		}
		
		else if ($call == 'confirm_upgrade') {
			$bot->answer_callback('❕ Upgrading...');
			$msg = $bot->send('❕ Upgrading...');
			$upgrade = parse_ini_string(file_get_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/update/update.ini'));
			$my_date_timestamp = strtotime(PHM_DATE);
			$files_changed = array_filter($upgrade['filemtimes'], function ($filemtime) use ($my_date_timestamp) {
				return $filemtime > $my_date_timestamp;
			});
			$str = "\n";
			foreach ($files_changed as $filename => $filemtime) {
				$success = $str."\n- \"$filename\" updated!";
				$fail = $str."\n- Failed to update \"$filename\"";
				$msg->append(copy('https://raw.githubusercontent.com/usernein/phgram-manager/master/'.$filename, $filename)? $success : $fail);
				$str = '';
			}
			$bot->editMessageReplyMarkup(['chat_id' => $bot->ChatID(), 'message_id' => $bot->MessageID(), 'reply_markup' => i_ikb([])]);
			$bot->append("\n\n✅ Done");
		}
		
		else if (preg_match('#^add (?<path>.+)#', $call, $match)) {
			$replied = $bot->ReplyToMessage();
			if (!$replied->message_id) throw new Error('Bad replied message id');
			$mp = new MPSend($bot->bot_token);
			$msg = $mp->mp->messages->getMessages(['id' => [$replied->message_id]]);
			$media = $msg['messages'][0]['media'];
			$contents = $old_content = false; # default values
			
			$name = $match['path'];
			$file_name = basename($name);
			try {
				$old_content = false;
				$oldB = @filesize($name);
				if ($oldB <= 50*1024*1024) {
					$old_content = @file_get_contents($name);
				}
				
				$info = $mp->get($media, $name);
				if (!$info['ok']) {
					$bot->send('Failed to add the file');
					exit;
				}
				$duration = convert_time($info['duration']);
				$info = $info['info'];
				$newB = $info['size'];
				if ($newB <= 50*1024*1024) {
					$contents = @file_get_contents($name);
				}
				
				if ($db->querySingle("SELECT php_check FROM users WHERE id={$user_id}") && preg_match('#\.php$#', $file_name)) {
					$supported = shell_exec('php -r "echo \'a\';"') == 'a';
					if ($supported) {
						$result = shell_exec("php -l {$name}") ?? '';
						if ($result && stripos($result, 'errors parsing') !== false) {
							@$bot->answer_callback($result, ['show_alert' => true]);
							if ($oldB) {
								file_put_contents($name, $old_content);
							} else {
								unlink($name);
							}
							exit;
						}
					}
				}
			
				$diff = $newB - $oldB;
				$diffSize = format_size($diff);
			
				$changed = ($old_content !== false && $contents !== false && ($old_content != $contents));
				if ($changed || $old_content === false || $contents === false) {
					$changes = '';
				} else {
					$changes = 'File unchanged.';
				}
				
				$bot->answer_callback("File saved as {$name} in {$duration}
Bytes difference: {$diff} ({$diffSize})

$changes", ['show_alert' => true]);
				goto show_find_paths_add;
			} catch (Throwable $t) {
				$bot->indoc("Failed: {$t->getMessage()} on {$t->getFile()} at {$t->getLine()}\n\n{$t->getTraceAsString()}");
			}
		}
		
		else {
			$bot->answer_callback($call);
		}
		@$bot->answer_callback($call);
	}
	
########################################.
########################################.
	else if ($type == 'message') {
		$text = $bot->Text();
		$chat_id = $bot->ChatID();
		$user_id = $bot->UserID();
		$message_id = $bot->MessageID();
		$replied = $bot->ReplyToMessage();
		$reply = $replied['text'] ?? $replied['caption'] ?? null;
		$user = $db->query("SELECT * FROM users WHERE id={$user_id}")->fetch();
		$botun = $bot->getMe()->username;
		
		$text = preg_replace('#^(/\w+)@'.$botun.'#isu', '$1', $text);
		
		if ($user['waiting_for'] != NULL) {
			$waiting_for = $user['waiting_for'];
			$param = $user['waiting_param'];
			$waiting_back = $user['waiting_back'];
			
			if ($text == '/cancel') {
				$db->query("UPDATE users SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				$keyboard = i_ikb([]);
				if ($waiting_back) {
					$keyboard = i_ikb([
						[ ['«', $waiting_back] ],
					]);
				}
				$bot->send("Command {$waiting_for} canceled.", ['reply_markup' => $keyboard]);
			}
			else if ($waiting_for == 'mkdir_name') {
				if (strpbrk($text, "\\/?%*:|\"<>")) {
					$bot->send('Invalid file name. Try again with a legal name.');
				} else if (file_exists($param.'/'.$text)) {
					if (@is_dir($param.'/'.$text)) {
						$bot->send('This directory already exists.');
					} else {
						$bot->send('This name is already on use by a file.');
					}
				} else {
					$name = $param.'/'.$text;
					mkdir($name, 0777, true);
					$path = $param;
					$db->query("UPDATE users SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
					goto send_dir;
				}
			}
		} else if ($user['auto_upload'] && $bot->is_private()) {
			$bot->action();
			$message = $bot->Message();
			$contents = $old_content = false; # default values
			$file_id = $message->find('file_id');
			
			if ($file_id) {
				$mp = new MPSend($bot->bot_token);
				$msg = $mp->mp->messages->getMessages(['id' => [$message->message_id]]);
				$media = $msg['messages'][0]['media'];
				$file_id = array_values($media)[1]['id'];
				$info = $mp->mp->get_download_info($media);
				$filename = "{$info['name']}{$info['ext']}";
				if (isset($info['MessageMedia']['document']['attributes'])) {
					$attributes = array_column($info['MessageMedia']['document']['attributes'], null, '_');
					if (isset($attributes['documentAttributeFilename'])) {
						$filename = $attributes['documentAttributeFilename']['file_name'];
					}
				}
				
				$name = $message->find('file_name') ?? $filename;
				$name = $user['upload_path'].'/'.$name;
				$name = preg_replace(['#/$#', '#^~#'], ["/{$filename}", $_SERVER['DOCUMENT_ROOT']], $name);
				
				if (@is_dir($name)) {
					$bot->reply("The path for saving the file corresponds to an existing directory. If you want to add the file to this directory, use <pre>/add {$name}/</pre>.");
					exit;
				}
			
				try {
					$old_content = false;
					$oldB = @filesize($name);
					if ($oldB <= 50*1024*1024) {
						$old_content = @file_get_contents($name);
					}
					
					$info = $mp->get($media, $name);
					if (!$info['ok']) {
						$bot->send('Failed to add the file');
						exit;
					}
					$duration = convert_time($info['duration']);
					$info = $info['info'];
					$newB = $info['size'];
					if ($newB <= 50*1024*1024) {
						$contents = @file_get_contents($name);
					}
					
					if ($db->querySingle("SELECT php_check FROM users WHERE id={$user_id}") && preg_match('#\.php$#', $name)) {
						$supported = shell_exec('php -r "echo \'a\';"') == 'a';
						if ($supported) {
							$result = shell_exec("php -l {$name}") ?? '';
							if ($result && stripos($result, 'errors parsing') !== false) {
								@$bot->reply($result, ['parse_mode' => null]);
								if ($oldB) {
									file_put_contents($name, $old_content);
								} else {
									unlink($name);
								}
								exit;
							}
						}
					}
				
					$diff = $newB - $oldB;
					$diffSize = format_size($diff);
				
					$changed = ($old_content !== false && $contents !== false && ($old_content != $contents));
					if ($changed || $old_content === false || $contents === false) {
						$changes = '';
					} else {
						$changes = 'File unchanged.';
					}
					
					$dir = $user['upload_path'];
					$keyboard = i_ikb([
						[ ['❌ Stop auto-upload', "cancel auto-upload {$dir}"] ],
					]);
					
					$bot->reply("File saved as <i>{$name}</i> in {$duration}
Bytes difference: {$diff} ({$diffSize})

$changes", ['reply_markup' => $keyboard]);
				} catch (Throwable $t) {
					$bot->reply("Failed: $t");
				}
			} else if ($text == '/stop') {
				$db->query("UPDATE users SET auto_upload=0, upload_path='.' WHERE id={$user_id}");
				$bot->send('Auto-upload has been stopped.');
			} else {
				$bot->send('Auto-upload is active. Use /stop to stop and free other commands.');
			}
		} else if (preg_match('#^/(\s+|ev(al)?\s+)(?<code>.+)#isu', $text, $match)) {
			$bot->action();
			
			BotErrorHandler::$admin = $chat_id;
			$bot->debug = true;
			$bot->debug_admin = $chat_id;
			
			ob_start();
			try {
				eval($match['code']);
			} catch (Throwable $t) {
				echo $t;
			}
			$out = ob_get_contents();
			ob_end_clean();
			if ($out) {
				if (@$bot->reply($out, ['parse_mode' => null])->ok != true) {
					$mp = new MPSend($bot->bot_token);
					$mp->indoc($out, 'eval.txt');
				}
			}
			BotErrorHandler::restore();
		}
		
		else if (preg_match('#^/add( (?<file>\S+))?#isu', $text, $match)) {
			#protect();
			$bot->action();
			$contents = $old_content = false; # default values
			$file_id = $replied? $replied->find('file_id') : null;
			if (!$replied) {
				$args = explode(' ', $text);
				if (count($args) >= 3) {
					$contents = join(' ', array_slice($args, 2));
					$name = $args[1];
					#fpc = use file_put_contents
					$fpc = true;
				} else {
					$bot->send('Reply to a media or a text.');
					exit;
				}
			} else if (!$file_id) {
				if (isset($replied['text'])) {
					if (!isset($match['file'])) {
						$bot->send('When adding a new file from a text, you should specify a path to write it.');
						exit();
					}
					$contents = $reply;
					$name = $match['file'];
					#fpc = use file_put_contents
					$fpc = true;
				} else {
					$bot->send('Reply to a media or a text.');
					exit();
				}
			} else if ($file_id) {
				#$file_id = $replied->find('file_id');
				#$contents = $bot->read_file($replied['document']['file_id']);
				$mp = new MPSend($bot->bot_token);
				$msg = $mp->mp->messages->getMessages(['id' => [$replied->message_id]]);
				$media = $msg['messages'][0]['media'];
				$file_id = array_values($media)[1]['id'];
				$info = $mp->mp->get_download_info($media);
				$filename = "{$info['name']}{$info['ext']}";
				if (isset($info['MessageMedia']['document']['attributes'])) {
					$attributes = array_column($info['MessageMedia']['document']['attributes'], null, '_');
					if (isset($attributes['documentAttributeFilename'])) {
						$filename = $attributes['documentAttributeFilename']['file_name'];
					}
				}
		
				$name = $match['file'] ?? $replied->find('file_name') ?? $filename;
				#fpc = use file_put_contents
				$fpc = false;
			} else {
				$bot->send('Reply to a media or a text.');
				exit();
			}
				
			if (!$name) {
				$bot->send('Could not get the file name. Please pass one as argument of /add.');
				exit;
			}
			$name = preg_replace(['#/$#', '#^~#'], ["/{$filename}", $_SERVER['DOCUMENT_ROOT']], $name);
			
			if (@is_dir($name)) {
				$bot->reply("The selected path for saving the file corresponds to an existing directory. If you want to add the file to this directory, add <pre>/</pre> at the end.");
				exit;
			}
			
			#$bot->send(dump(compact('file_id', 'name')));
			
			try {
				$old_content = false;
				$oldB = @filesize($name);
				if ($oldB <= 50*1024*1024) {
					$old_content = @file_get_contents($name);
				}
				
				if ($fpc) {
					$start = microtime(1);
					$newB = file_put_contents($name, $contents);
					$end = microtime(1);
					$duration = convert_time($end-$start);
				} else {
					$info = $mp->get($media, $name);
					if (!$info['ok']) {
						$bot->send('Failed to add the file');
						exit;
					}
					$duration = convert_time($info['duration']);
					$info = $info['info'];
					$newB = $info['size'];
					if ($newB <= 50*1024*1024) {
						$contents = @file_get_contents($name);
					}
				}
				
				if ($db->querySingle("SELECT php_check FROM users WHERE id={$user_id}") && preg_match('#\.php$#', $name)) {
					$supported = shell_exec('php -r "echo \'a\';"') == 'a';
					if ($supported) {
						$result = shell_exec('php -l '.escapeshellarg($name)) ?? '';
						if ($result && stripos($result, 'errors parsing') !== false) {
							@$bot->reply($result, ['parse_mode' => null]);
							if ($oldB) {
								file_put_contents($name, $old_content);
							} else {
								unlink($name);
							}
							exit;
						}
					}
				}
			
				$diff = $newB - $oldB;
				$diffSize = format_size($diff);
			
				$changed = ($old_content !== false && $contents !== false && ($old_content != $contents));
				if ($changed || $old_content === false || $contents === false) {
					$changes = '';
				} else {
					$changes = 'File unchanged.';
				}
			 
				$bot->reply("File saved as <i>{$name}</i> in $duration
Bytes difference: {$diff} ({$diffSize})
		
$changes");
			} catch (Throwable $t) {
				$bot->reply("Failed: $t");
			}
		}
		
		else if (preg_match('#^/list( (?<path>.+))?#', $text, $match)) {
			# $protection = protect();
			$bot->action();
			$path = $match['path'] ?? '.';
			send_dir:
			$path = fakepath($path);
			
			if (!file_exists($path)) {
				$output = "❕{$path} doesn't exist.";
				$bot->send($output);
			} else if (@is_dir($path) != true) {
				$output = "❕{$path} isn't a directory or is not accessible.";
				$bot->send($output);
			} else {
				$dirs = $files = [[]];
				$dirs_line = $files_line = 0;
				
				$scan = scandir($path);
				foreach ($scan as $filename) {
					if (in_array($filename, ['.', '..'])) continue;
					
					$path_to_file = fakepath($path.'/'.$filename);
					#$bot->send(dump($path_to_file));
					if (@is_dir($path_to_file)) {
						if ($cfg->grouped_list && count($dirs[$dirs_line]) != $cfg->grouped_columns) {
							$dirs[$dirs_line][] = ["📁 {$filename}", "list {$path_to_file}"];
						} else {
							$dirs_line++;
							$dirs[$dirs_line] = [ ["📁 {$filename}", "list {$path_to_file}"] ];
						}
					} else {
						if ($cfg->grouped_list && count($files[$files_line]) != $cfg->grouped_columns) {
							$files[$files_line][] = ["📄 {$filename}", "file {$path_to_file}"];
						} else {
							$files_line++;
							$files[$files_line] = [ ["📄 {$filename}", "file {$path_to_file}"] ];
						}
					}
				}
				$options = array_merge($dirs, $files);
				$tools = [ ['➕', "mkdir {$path}"], ['⏫', "upload {$path}"], ['📦', "zip {$path}"] ];
				# if (!$bot->is_private()) unset($tools[1]);
				if ($user['show_rmdir']) {
					$tools[] = ['🗑', "rmdir {$path}"];
				}
				$options[] = $tools;
				if (@is_dir($path.'/..')) {
					$options[] = [ ['..', "list {$path}/.."] ];
				}
				
				$keyboard = i_ikb($options);
				$bot->send("📁 <b>Listing {$path}</b>", ['reply_markup' => $keyboard]);
			}
		}
		
		else if (preg_match('#^/del( (?<file>.+))?#', $text, $match)) {
			#protect();
			$bot->action();
			
			$match['file'] = preg_replace('#^~#', $_SERVER['DOCUMENT_ROOT'], $match['file']);
			$return = '';
			foreach (glob($match['file'], GLOB_BRACE) as $result) {
				if (is_dir($result)) continue;
				if (unlink($result)){
					$return .= "$result deleted!\n";
				}
			}
			if (!$return) $return = "No file matches the given pattern.";
			$bot->reply($return);
		}
		
		else if (preg_match('#^/get (?<file>.+)#', $text, $match)) {
			protect();
			
			$match['file'] = preg_replace('#^~#', $_SERVER['DOCUMENT_ROOT'], $match['file']);
			$return = false;
			foreach (glob($match['file'], GLOB_BRACE) as $result) {
				$return = true;
				if (is_dir($result)) continue;
				$modified_time = new DateTime('@'. filemtime($result));
				$now = new DateTime('now');
				$d = $modified_time->diff($now, true);
				if ($d->d > 1) $last_modify = "{$d->d} days ago";
				else if ($d->h > 1) $last_modify = "{$d->h} hours ago";
				else if ($d->i > 1) $last_modify = "{$d->i} minutes ago";
				else if ($d->s >= 1) $last_modify = "{$d->s} seconds ago";
				else if ($d->s == 0) $last_modify = "right now";
				else $last_modify = "at ".date('d.m.y, H:i')." (".date_default_timezone_get().")";
				$caption = "Last modify ".$last_modify;
				$mp = new MPSend($bot->bot_token);
				$mp->doc($result, ['caption' => $caption]);
			}
			if (!$return) {
				$return = "No file matches the given pattern.";
				$bot->send($return);
			}
		}
		
		else if ($text == '/time') {
			$output = '';
			$result = $db->query('SELECT timezone, id FROM users');
			while ($set = $result->fetch()) {
				date_default_timezone_set($set['timezone']);
				$output .= "<b>{$bot->mention($set['id'])}</b>'s time: <i>". date('H\hi d/m/y') ."</i>\n";
			}
			$bot->send($output);
		}
		
		else if (preg_match('#^/zip (?<path>.+)#', $text, $match)) {
			$path = $match['path'];
			if (file_exists($path) && is_dir($path)) {
				$name = basename(realpath($path)).'.zip';
				$msg = $bot->send("Zipping $path into $name...");
				$zip = zipDir(realpath($path), $name);
				$msg->edit("$path has been zipped into $name!");
				$name = $zip->filename;
				$zip->close();
				$mp = new MPSend($bot->bot_token);
				$mp->doc($name);
				unlink($name);
				$msg->delete();
			} else {
				$bot->send("❕ {$path} isn't available.");
			}
		}
		
		else if (preg_match('#^/unzip (?<path>.+)#', $text, $match)) {
			$path = $match['path'];
			if (isset($replied['document'])) {
				$mp = new MPSend($bot->bot_token);
				$msg = $mp->mp->messages->getMessages(['id' => [$replied->message_id]]);
				$media = $msg['messages'][0]['media'];
				$file_id = array_values($media)[1]['id'];
				$info = $mp->mp->get_download_info($media);
				$filename = "{$info['name']}{$info['ext']}";
				if (isset($info['MessageMedia']['document']['attributes'])) {
					$attributes = array_column($info['MessageMedia']['document']['attributes'], null, '_');
					if (isset($attributes['documentAttributeFilename'])) {
						$filename = $attributes['documentAttributeFilename']['file_name'];
					}
				}
		
				$name = $replied->find('file_name') ?? $filename;
				
				$file = $replied['document'];
				if (preg_match('#\.zip$#', $file->file_name)) {
					$new_filename = time() . $file->file_name;
					$res = $mp->get($media, $new_filename);
					if (!$res['ok']) {
						return $bot->send('Failed');
					}
					unzipDir($new_filename, $path);
					unlink($new_filename);
					$keyb = i_ikb([
						[ ['📂 Open the directory', "list $path"] ],
					]);
					$bot->send("✅ Done!", ['reply_markup' => $keyb]);
				} else {
					$bot->send("Reply to a .zip file!");
				}
			} else {
				$bot->send("Reply to a .zip file!");
			}
		}
		
		else if (preg_match('#^/sql (?<sql>.+)#isu', $text, $match)) {
			extract($match);
			$s = $db->query($sql)->fetchAll();
			$bot->send(json_encode($s, 480));
		}
		
		else if ($text == '/upgrade') {
			upgrade:
			$upgrade = parse_ini_string(file_get_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/update/update.ini'));
			if (($upgrade_date = strtotime($upgrade['date'])) > ($my_date_timestamp = strtotime(PHM_DATE))) {
				$upgrade_date = date('d/m/Y H:i:s', $upgrade_date);
				$my_date = date('d/m/Y H:i:s', $my_date_timestamp);
				$my_version = PHM_VERSION;
				$files_changed = array_filter($upgrade['filemtimes'], function ($filemtime) use ($my_date_timestamp) {
					return $filemtime > $my_date_timestamp;
				});
				$files_changed = join(', ', $files_changed) ?: '---';
				$str = "🆕 There's a new upgrade available of <a href='https://github.com/usernein/phgram-manager'>phgram-manager</a>!
🏷 <b>Version</b>: {$upgrade['version']} <i>(current: {$my_version})</i>
🕚 <b>Date</b>: {$upgrade_date} <i>(current: {$my_date})</i>
🗂 <b>Files changed</b>: {$files_changed}
📃 <b>Changelog</b>: {$upgrade['changelog']}";
				
				if ($bot->update_type == 'callback_query') {
					$str .= "\n\n🔄 Message refreshed at ".date('d/m/Y H:i:s');
				}
				$i_ikb = i_ikb([
					[ ['🔄 Refresh', 'upgrade'] ],
					[ ['⏬ Upgrade now', 'confirm_upgrade'] ],
				]);
				$bot->act($str, ['reply_markup' => $i_ikb]);
			} else {
				$bot->act('✅ Already up-to-date!');
			}
		}
		
		else if (($doc = $bot->Document()) && $bot->is_private()) {
			#$bot->log($doc);
			$ask = $db->querySingle("SELECT ask_upload FROM users WHERE id={$user_id}");
			if (!$ask) return;
			$file_name = $doc->file_name;
			show_find_paths_add:
			$files = rglob($file_name);
			if (!$files) {
				$i_ikb = i_ikb([
					[ ['⏫ Add in the current directory', "add {$file_name}"] ]
				]);
				$dir = __DIR__;
				return @$bot->act("There's no files named \"{$doc->file_name}\" under $dir.", ['reply_to_message_id' => $message_id, 'reply_markup' => $i_ikb]);
			}
			$count = count($files);
			$str = "🔎 {$file_name} has been found in {$count} paths:\n";
			$keyb = [];
			foreach ($files as $file) {
				$size = filesize($file);
				$size = format_size($size);
				
				$modified_time = new DateTime('@'. filemtime($file));
				$now = new DateTime('now');
				$d = $modified_time->diff($now, true);
				if ($d->d > 1) $last_modify = "{$d->d} days ago";
				else if ($d->h > 1) $last_modify = "{$d->h} hours ago";
				else if ($d->i > 1) $last_modify = "{$d->i} minutes ago";
				else if ($d->s >= 1) $last_modify = "{$d->s} seconds ago";
				else if ($d->s == 0) $last_modify = "right now";
				else $last_modify = "at ".date('d.m.y, H:i')." (".date_default_timezone_get().")";
				$keyb[] = [ ["⏫ {$file}", "add {$file}"] ];
				$str .= "\n  - <code>$file</code> <i>({$size}, last modified {$last_modify})</i>";
			}
			$keyb = i_ikb($keyb);
			@$bot->act($str, ['reply_to_message_id' => $message_id, 'reply_markup' => $keyb]);
		}
	}
}