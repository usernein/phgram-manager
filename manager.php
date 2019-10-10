<?php
define('PHM_VERSION', '1.1.1');
define('PHM_DATE', '2019-10-10T06:24:14-03:00');
# breakfile src/phgram/arrayobj.class.php

class ArrayObj implements ArrayAccess, JsonSerializable {
	public $data = [];

	public function __construct($obj) {
		$this->setData($obj);
	}
	public function setData($obj) {
		$this->data = (array)$obj;
		if (is_array($obj) || is_object($obj)) {
			foreach ($this->data as &$item) {
				if (is_array($item) || is_object($item)) {
					$item = new ArrayObj($item);
				}
			}
		}
	}
	public function __get($key) {
		return ($this->data[$key]);
	}
	public function __set($key, $val) {
		$this->data[$key] = $val;
	}
	public function __isset($key) {
		return isset($this->data[$key]);
	}
	public function __unset($key) {
		unset($this->data[$key]);
	}
	public function offsetGet($offset) {
		return $this->data[$offset];
	}
	public function offsetSet($offset, $value) {
		if (is_null($offset)) {
			$this->data[] = $value;
		} else {
			$this->data[$offset] = $value;
		}
	}
	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}
	public function offsetUnset($offset) {
		if ($this->offsetExists($offset)) {
			unset($this->data[$offset]);
		}
	}
	public function __invoke() {
		return $this->asArray();
	}
	public function &asArray() {
		$data = $this->data;
		foreach ($data as $key => &$item) {
			if (is_a($item, __CLASS__, true)) {
				$item = $item->asArray();
			}
		}
		return $data;
	}
	public function jsonSerialize() {
		return $this->data;
	}
	public function __toString() {
		return json_encode($this->data);
	}
	public function find($needle) {
		$haystack = $this->asArray();
		$iterator  = new RecursiveArrayIterator($haystack);
		$recursive = new RecursiveIteratorIterator(
			$iterator,
			RecursiveIteratorIterator::SELF_FIRST
		);
		$return = null;
		foreach ($recursive as $key => $value) {
			if ($key === $needle) {
				$return = $value;
				break;
			}
		}
		
		if (is_array($return) || is_object($return)) {
			$return = new ArrayObj($return);
		}
		return $return;
	}
}

# breakfile src/phgram/bot.class.php

/**
 * Class to help Telegram bot development with PHP.
 *
 * Based on TelegramBotPHP (https://github.com/Eleirbag89/TelegramBotPHP)
 *
 * @author Cezar Pauxis (https://t.me/usernein)
 * @license https://github.com/usernein/phgram/blob/master/LICENSE
*/
class Bot {
	# The bot token
	private $bot_token = '';
	
	# The array of the update
	private $data = [];
	
	# Type of the current update
	private $update_type = '';
	
	# Values for error reporting
	public $debug = FALSE;
	public $debug_admin;
	
	# Execution properties
	public $report_mode = 'message';
	public $report_show_view = 0;
	public $report_show_data = 1;
	public $report_obey_level = 1;
	public $default_parse_mode = 'HTML';
	public $report_max_args_len = 300;
	
	# cURL connection handler
	private $ch;
	
	# MethodResult of the last method result
	public $lastResult;
	
	# Data type for getters
	public $data_type = 'ArrayObj';
	
	/**
	 * The object constructor.
	 *
	 * The only required parameter is $bot_token. Pass a chat id as second argument to enable error reporting.\
	 *
	 * @param string $bot_token The bot token
	 * @param $debug_chat Chatbid which the errors will be sent to
	 */
	public function __construct(string $bot_token, $debug_chat = FALSE) {
		$this->bot_token = $bot_token;
		$this->data = $this->getData();
		$this->update_type = @array_keys($this->data)[1];
		if ($debug_chat) {
			$this->debug_admin = $debug_chat;
			$this->debug = TRUE;
		}
		
		# Setting cURl handler
		$this->ch = curl_init();
		$opts = [
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_POST => TRUE,
		];
		curl_setopt_array($this->ch, $opts);
	}	
	
	/**
	 * Makes the request to BotAPI.
	 *
	 * @param string $url URl of api, already including the method name
	 * @param array $content Associative array of arguments
	 *
	 * @return string
	 */
	private function sendAPIRequest(string $url, array $content) {
		$opts = [
			CURLOPT_URL => $url,
			CURLOPT_POSTFIELDS => $content,
		];
		curl_setopt_array($this->ch, $opts);
		$result = curl_exec($this->ch);
		return $result;
	}	
	
	/**
	 * Handle calls of unexistent methods. i.e. BotAPI methods, which aren't set on this file.
	 *
	 * Every unexistent method and its arguments will be handled by __call() when called. Because of this, methods calls are case-insensitive. 
	 *
	 * @param string $method Method name
	 * @param array $arguments Associative array with arguments
	 *
	 * @return MethodResult
	 */
	public function __call(string $method, array $arguments = NULL) {
		global $lastResult;
		if (!$arguments) {
			$arguments = [[]];
		}
		
		$url = "https://api.telegram.org/bot{$this->bot_token}/{$method}";
		$response = $this->sendAPIRequest($url, $arguments[0]);
		
		$result = $this->lastResult = new MethodResult($response, $arguments[0], $this);
		if (!$result['ok'] && $this->debug && ($this->report_obey_level xor error_reporting() <= 0)) {
			$debug = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
			$class = @$debug['class'];
			$type = @$debug['type'];
			$function = ($class? 'method ' : 'function ').$class.$type.$debug['function'];
			$debug['method'] = $method;
			
			$error_info = "Error thrown by the method {$debug['method']}, in {$debug['file']} on line {$debug['line']}, while calling the {$function}";
			$text_log = "{$result->json}\n\n{$error_info}";
			if ($this->report_show_data) {
				$data = $this->data;
				$type = @array_keys($data)[1];
				$data = @array_values($data)[1];
				$max_len = $this->report_max_args_len;
				$data = array_map(function($item) use ($max_len) {
					if (is_string($item)) {
						return substr($item, 0, $max_len);
					}
				}, $data);
				$text = $data['data'] ?? $data['query'] ?? $data['text'] ?? $data['caption'] ?? $data['result_id'] ?? $type;
	
				$sender = $data['from']['id'] ?? null;
				$sender_name = $data['from']['first_name'] ?? null;
		
				$chat = $data['chat'] ?? $data['message']['chat'] ?? null;
				$chat_id = $chat['id'] ?? null;
				$message_id = $data['message_id'] ?? $data['message']['message_id'] ?? null;
				if ($chat['type'] == 'private') {
					$chat_mention = isset($chat['username'])? "@{$chat['username']}" : "<a href='tg://user?id={$sender}'>{$sender_name}</a>";
				} else {
					$chat_mention = isset($chat['username'])? "<a href='t.me/{$chat['username']}/{$message_id}'>@{$chat['username']}</a>" : "<i>{$chat['title']}</i>";
				}
				
				if ($this->report_mode == 'message' && $this->default_parse_mode == 'HTML') {
					$text_log .= htmlspecialchars("\n\n\"{$text}\", ").
						($sender? "sent by <a href='tg://user?id={$sender}'>{$sender_name}</a>, " : '').
						($chat? "in {$chat_id} ({$chat_mention})." : '')." Update type: '{$type}'.";
				} else {
					$chat_mention = isset($chat['username'])? "@{$chat['username']}" : '';
					$text_log .= ("\n\n\"{$text}\", ").
						($sender? "sent by {$sender} ({$sender_name}), " : '').
						($chat? "in {$chat_id} ({$chat_mention})." : '')." Update type: '{$type}'.";
				}
			}
			if ($this->report_show_view) {
				$text_log .= "\n\n". phgram_pretty_debug(2);
			}
			
			if ($this->report_mode == 'message') {
				@$this->log($text_log, 'HTML');
			} else if ($this->report_mode == 'notice') {
				trigger_error($text_log);
			}
		}
		
		if ($this->data_type == 'array') {
			return $result->asArray();
		} else if ($this->data_type == 'object') {
			return (object)$result->asArray();
		}
		return $result;
	}
	
	/**
	 * Sends a message to $debug_admin or all its elements, if it is an array
	 *
	 * @param $text The message text
	 */
	public function log($value, $parse_mode = null) {
		# using sendAPIRequest to avoid recursion in __call()
		$url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
		$text = $value;
		if ($value instanceof Throwable) {
			$text = (string)$value;
		} else if (!is_string($value) && !is_int($value)) {
			ob_start();
			var_dump($value);
			$text = ob_get_contents();
			ob_end_clean();
			
			$text = $text ?? print_r($value, 1) ?? 'undefined';
		}
		$params = ['parse_mode' => $parse_mode, 'disable_web_page_preview' => TRUE, 'text' => $text];
		
		if (mb_strlen($text) > 4096) {
			$logname = 'phlog_'.$this->UpdateID().'.txt';
			
			file_put_contents($logname, $text);
			
			$url = "https://api.telegram.org/bot{$this->bot_token}/sendDocument";
			$params = [];
			$document = curl_file_create(realpath($logname));
			$params['document'] = $document;
		}
		
		if (is_array($this->debug_admin)) {
			foreach ($this->debug_admin as $admin) {
				$params['chat_id'] = $admin;
				$this->sendAPIRequest($url, $params);
			}
		} else {
			$params['chat_id'] = $this->debug_admin;
			$res = $this->sendAPIRequest($url, $params);
			if (!json_decode($res)->ok)
				file_put_contents('phlog_error', $text."\n\n".$res);
		}
		
		if (isset($logname)) unlink($logname);
		return $value;
	}
	
	/**
	 * Casts the object to a string
	 */
	public function __toString() {
		return json_encode($this->getMe()->result);
	}
	
	/**
	 * Magic method to get private properties and avoid its override
	 */
	public function __get($key) {
		return $this->$key;
	}	
	
	/**
	 * Responds directly the webhook with a method and its arguments
	 *
	 * @param string $method Method name
	 * @param array $arguments Associative array with arguments
	 *
	 * @return void
	 */
	public static function respondWebhook(array $arguments = [], int $time_limit = 60) {
		ignore_user_abort(true);
		@set_time_limit($time_limit);
		
		ob_start();
		// do initial processing here
		header("Content-Type: application/json");
		echo json_encode($arguments); // send the response
		header('Connection: close');
		header('Content-Length: '.ob_get_length());
		ob_end_flush();
		ob_flush();
		flush();
	}	
	
	/**
	 * Downloads a remote file hosted on Telegram servers to a relative path, by its file_id.
	 *
	 * This function doesn't work with files bigger than 20MB.
	 *
	 * @param string $file_id The file id
	 * @param string $local_file_path A relative path which will be used to save the file. Optional. If omitted, the file will be saved as its file name in the current working directory.
	 *
	 * @return False or integer (number of written bytes)
	 */
	public function download_file($file_id, string $local_file_path = NULL) {
		if ($file_id instanceof ArrayObj) {
			$file_id = $file_id->find('file_id');
		}
		$contents = $this->read_file($file_id);
		if (!$local_file_path) {
			$local_file_path = @$this->getFile(['file_id' => $file_id])->file_name;
		}
		if (!$local_file_path) {
			return false;
		}
		return file_put_contents($local_file_path, $contents);
	}	
	
	/**
	 * Reads and return the contents of a remote file.
	 *
	 * This function doesn't work with files bigger than 20MB.
	 *
	 * @param $file_id The file id or an instance of ArrayObj with a file_id
	 *
	 * @return string
	 */
	public function read_file($file_id) {
		if ($file_id instanceof ArrayObj) {
			$file_id = $file_id->find('file_id');
		}
		$file_path = $this->getFile(['file_id' => $file_id])->result->file_path;
		$file_url = "https://api.telegram.org/file/bot{$this->bot_token}/{$file_path}";
		return file_get_contents($file_url);
	}	
	
	/**
	 * Quick way to send a message.
	 *
	 * Check out the Shortcuts section at the README.
	 *
	 * @param string $text The text to send
	 * @param array $params Associative array with additional parameters to sendMessage. Optional.
	 *
	 * @return MethodResult
	 */
	public function send(string $text, array $params = []) {
		$default = ['chat_id' => $this->ChatID() ?? $this->UserID(), 'parse_mode' => $this->default_parse_mode, 'disable_web_page_preview' => TRUE, 'text' => $text];
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		return $this->sendMessage($default);
	}	
	
	/**
	 * Quick way to reply the received message.
	 *
	 * Check out the Shortcuts section at the README.
	 *
	 * @param string $text The text to send
	 * @param array $params Associative array with additional parameters to sendMessage. Optional.
	 *
	 * @return MethodResult
	 */
	public function reply(string $text, array $params = []) {
		$default = ['chat_id' => $this->ChatID() ?? $this->UserID(), 'parse_mode' => $this->default_parse_mode, 'disable_web_page_preview' => TRUE, 'reply_to_message_id' => $this->MessageID(), 'text' => $text];
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		$default['text'] = $text;
		return $this->sendMessage($default);
	}	
	
	/**
	 * Quick way to edit a message.
	 *
	 * Check out the Shortcuts section at the README.
	 *
	 * @param string $text The new text for the message
	 * @param array $params Associative array with additional parameters to editMessageText. Optional.
	 *
	 * @return MethodResult
	 */
	public function edit(string $text, array $params = []) {
		$default = ['chat_id' => $this->ChatID() ?? $this->UserID(), 'parse_mode' => $this->default_parse_mode, 'disable_web_page_preview' => TRUE, 'text' => $text, 'message_id' => $this->MessageID()];
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		$default['text'] = $text;
		return $this->editMessageText($default);
	} 
	
	/**
	 * Quick way to send a file as document.
	 *
	 * Check out the Shortcuts section at the README.
	 *
	 * @param string $filename The file name
	 * @param array $params Associative array with additional parameters to sendDocument. Optional.
	 *
	 * @return MethodResult
	 */
	public function doc(string $filename, array $params = []) {
		@$this->action("upload_document");
		$default = ['chat_id' => $this->ChatID() ?? $this->UserID(), 'parse_mode' => 'HTML', 'disable_web_page_preview' => TRUE];
		
		if (file_exists(realpath($filename)) && !is_dir(realpath($filename))) {
			$document = curl_file_create(realpath($filename));
			if (isset($params['postname'])) {
				$document->setPostFilename($params['postname']);
				unset($params['postname']);
			}
			$default['document'] = $document;
		} else {
			$default['document'] = $filename;
		}
		
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		return $this->sendDocument($default);
	}
	
	/**
	 * Quick way to send a ChatAction.
	 *
	 * Check out the Shortcuts section at the README.
	 *
	 * @param string $action The chat action
	 * @param array $params Associative array with additional parameters to sendChatAction. Optional.
	 *
	 * @return MethodResult
	 */
	public function action(string $action = 'typing', array $params = []) {
		$default = ['chat_id' => $this->ChatID() ?? $this->UserID(), 'action' => $action];
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		return $this->sendChatAction($default);
	}	
	
	/**
	 * Dinamically generates a mention to a user.
	 *
	 * If the user has an username, then it is returned (with '@'). If not, a inline mention is generated using the passed user id and the first name of the user. You can choose the markup language (parse mode) using the second parameter. The default value for it is HTML.
	 *
	 * @param $user_id The user id
	 * @param $parse_mode The parse mode. Optional. The default value is HTML.
	 *
	 * @return string or integer
	 */
	public function mention($user_id, $parse_mode = 'html', $use_last_name = false) {
		$parse_mode = strtolower($parse_mode);
		$info = @$this->Chat($user_id);
		if (!$info || !$info['first_name']) {
			return $user_id;
		}
		if ($use_last_name) $info['first_name'] .= (isset($info['last_name']) && !is_null($info['last_name'])? " {$info['last_name']}" : '');
		
		$mention = isset($info['username'])? "@{$info['username']}" : ($parse_mode == 'html'? "<a href='tg://user?id={$user_id}'>".htmlspecialchars($info['first_name'])."</a>" : "[{$info['first_name']}](tg://user?id={$user_id})");
		return $mention;
	}
	
	/**
	 * Send file with a text
	 *
	 * @param $text The text to send. If it isn't a string, the result of var_dump($text) will be used
	 * @param $name A custom name for the file. Optional.
	 *
	 * @return MethodResult of sendDocument
	 */
	public function indoc($text, $name = null) {
		if (!is_string($text) && !is_int($text)) {
			ob_start();
			var_dump($text);
			$text = ob_get_contents();
			ob_end_clean();
		}
		
		for ($i=0; $i<50; $i++) {
			#$hash = phgram_toBase($i);
			$tempname = "indoc_{$i}.txt";
			if (!file_exists($tempname)) break;
		}
		
		file_put_contents($tempname, $text);
		$res = $this->doc($tempname, ['postname' => $name]);
		unlink($tempname);
		return $res;
	}
	
	/**
	 * Answer a InlineQuery with the $results
	 *
	 * @param $results array with the lines of the results
	 * @param array $params Associative array with additional parameters to answerInlineQuery. Optional.
	 */
	public function answer_inline(array $results = [], array $params = []) {
		$default = ['inline_query_id' => $this->InlineQuery()['id'], 'cache_time' => 0];
		
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		$default['results'] = json_encode($results);
		return $this->answerInlineQuery($default);
	}
	
	/**
	 * Answer a CallbackQuery with the $text
	 *
	 * @param $text the text of the response. Optional
	 * @param array $params Associative array with additional parameters to answerCallbackQuery. Optional.
	 */
	public function answer_callback($text = '', array $params = []) {
		$default = ['callback_query_id' => $this->CallbackQuery()['id']];
		
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		$default['text'] = $text;
		return $this->answerCallbackQuery($default);
	}
	
	/**
	 * Function to delete a message
	 *
	 * @param $message_id
	 * @param $chat_id
	 */
	public function delete($message_id = null, $chat_id = null) {
		$message_id = $message_id ?? $this->MessageID();
		$chat_id = $chat_id ?? $this->ChatID();
		return $this->deleteMessage(['chat_id' => $chat_id, 'message_id' => $message_id]);
	}
	
	/**
	 * Returns the current value for the data (used by data shortcuts)
	 *
	 * Use setData() to overwrite this value
	 *
	 *
	 * @return array
	 */
	public function getData() {
		if (!$this->data) {
			$update_as_json = file_get_contents('php://input') ?: '[]';
			$this->data = json_decode($update_as_json, TRUE);
		}
		
		return $this->data;
	} 
	
	/**
	 * Overwrites a new data to the object.
	 *
	 * The value set is used by all data shortcuts, getUpdateType() and getData()
	 *
	 * @param array $data The new data
	 *
	 * @return void
	 */
	public function setData($data) {
		if (!is_array($data) && !($data instanceof ArrayObj)) {
			throw new Exception('Bad data type passed to setData');
			return false;
		}
		if ($data instanceof ArrayObj)
			$data = $data->asArray();
		
		$this->data = $data;
		$this->update_type = @array_keys($this->data)[1];
	}	
	
	/**
	 * Returns the current update type.
	 *
	 * It is the second value of the Update object ('message', 'edited_message', 'callback_query', 'inline_query',...).
	 *
	 *
	 * @return string
	 */
	public function getUpdateType() {
		return $this->update_type;
	}	
	
	/**
	 * Return a value inside the update data.
	 *
	 * The priority is given to the data outside 'message' field. If the value is not found, the second search for the same field will be made inside 'message' (given in callback_query updates). If not found, NULL is returned.
	 *
	 * @param string $search The field to search for
	 *
	 * @return array, integer or string
	 */
	public function getValue(string $search) {
		$value = $this->data[$this->update_type][$search] ?? $this->data[$this->update_type]['message'][$search] ?? null;
		if (!$value) return $value;
		
		switch ($this->data_type) {
			case 'ArrayObj':
				if ((is_array($value) || is_object($value))) {
					$obj = new ArrayObj($value);
					return $obj;
				} else {
					return $value;
				}
			break;
			case 'array':
				if (is_object($value)) {
					return (array)$value;
				} else {
					return $value;
				}
			break;
			case 'object':
				if (is_array($value)) {
					return (object)$value;
				} else {
					return $value;
				}
			break;
		}
	}
	
	/**
	 * Checks if an user is member of the specified chat.
	 *
	 * @param int $user_id The user id
	 * @param $chat_id The chat id
	 *
	 * @return boolean
	 */
	public function in_chat(int $user_id, $chat_id) {
		$member = @$this->getChatMember(['chat_id' => $chat_id, 'user_id' => $user_id]);
		if (!$member['ok'] || in_array($member['result']['status'], ['left', 'kicked'])) {
			return FALSE;
		}
		
		return TRUE;
	}	
	
	/**
	 * Check if the current chat is a supergroup
	 *
	 *
	 * @return boolean
	 */
	public function is_group() {
		$chat = $this->getValue('chat');
		if (!$chat) {
			return FALSE;
		}
		return ($chat['type'] == 'supergroup') || ($chat['type'] == 'group');
	}	
	
	/**
	 * Check if the current chat is a supergroup
	 *
	 *
	 * @return boolean
	 */
	public function is_private() {
		$chat = $this->getValue('chat');
		if (!$chat) {
			return FALSE;
		}
		return $chat['type'] == 'private';
	}	
	
	/**
	 * Check if a user is admin of the specified chat
	 *
	 * Both parameters are optional. The default value for $user_id is the id of the sender and for $chat_id, the current chat id.
	 *
	 * @param $user_id The user id
	 * @param $chat_id The chat id
	 *
	 * @return boolean
	 */
	public function is_admin($user_id = NULL, $chat_id = NULL) {
		if (!$user_id) {
			$user_id = $this->UserID();
		}
		if (!$chat_id) {
			$chat_id = $this->ChatID();
		}
		$member = @$this->getChatMember(['chat_id' => $chat_id, 'user_id' => $user_id]);
		return in_array($member['result']['status'], ['administrator', 'creator']);
	}
	
	##### Data shortcuts #####
	public function Message() {
		$value = $this->data['message'];
		if (!$value) return $value;
		
		switch ($this->data_type) {
			case 'ArrayObj':
				if ((is_array($value) || is_object($value))) {
					$obj = new ArrayObj($value);
					return $obj;
				} else {
					return $value;
				}
			break;
			case 'array':
				if (is_object($value)) {
					return (array)$value;
				} else {
					return $value;
				}
			break;
			case 'object':
				if (is_array($value)) {
					return (object)$value;
				} else {
					return $value;
				}
			break;
		}
	}
	
	public function Text() {
		return $this->getValue('text');
	}
 
	public function ChatID() {
		return $this->getValue('chat')['id'] ?? NULL;
	}

	public function ChatType() {
		return $this->getValue('chat')['type'] ?? NULL;
	}
	
	public function MessageID() {
		return $this->getValue('message_id');
	}
 
	public function Date() {
		return $this->getValue('date');
	}
 
	public function UserID() {
		return $this->getValue('from')['id'] ?? NULL;
	}
 
	public function FirstName() {
		return $this->getValue('from')['first_name'] ?? NULL;
	}
 
	public function LastName() {
		return $this->getValue('from')['last_name'] ?? NULL;
	}
	
	public function Name() {
		$first_name = $this->FirstName();
		$last_name = $this->LastName();
		if ($first_name) {
			$name = $first_name.($last_name? " {$last_name}" : '');
			return $name;
		}
		
		return NULL;
	}
 
	public function Username() {
		return $this->getValue('from')['username'] ?? NULL;
	}
	
	public function Language() {
		return $this->getValue('from')['language_code'] ?? NULL;
	}
 
	public function ReplyToMessage() {
		return $this->getValue('reply_to_message');
	}
 
	public function Caption() {
		return $this->getValue('caption');
	}
	
	public function InlineQuery() {
		return $this->data['inline_query'] ?? NULL;
	}
 
	public function ChosenInlineResult() {
		return $this->data['chosen_inline_result'] ?? NULL;
	}
 
	public function ShippingQuery() {
		return $this->data['shipping_query'] ?? NULL;
	}
 
	public function PreCheckoutQuery() {
		return $this->data['pre_checkout_query'] ?? NULL;
	}
 
	public function CallbackQuery() {
		return $this->data['callback_query'] ?? NULL;
	}
 
	public function Location() {
		return $this->getValue('location');
	}
 
	public function Photo() {
		return $this->getValue('photo');
	}
 
	public function Video() {
		return $this->getValue('video');
	}
 
	public function Document() {
		return $this->getValue('document');
	}
 
	public function UpdateID() {
		return $this->data['update_id'] ?? NULL;
	}
	
	public function ForwardFrom() {
		return $this->getValue('forward_from');
	}
 
	public function ForwardFromChat() {
		return $this->getValue('forward_from_chat');
	}
	
	public function Entities() {
		return $this->getValue('entities') ?? $this->getValue('caption_entities');
	}

	##### ####### #####
	
	/**
	 * Returns a Chat object as array.
	 *
	 * Also a shortcut for getChat().
	 *
	 * @param $chat_id The chat id
	 *
	 * @return array or null
	 */
	public function Chat($chat_id = NULL) {
		if (!$chat_id) {
			$chat_id = $this->ChatID();
		}
		$chat = @$this->getChat(['chat_id' => $chat_id]);
		if ($chat['ok']) {
			return $chat['result'];
		}
		return FALSE;
	}
	
	/**
	 * A function to avoid repeated webhooks (might be delivery due to timeout)
	 */
	public function protect() {
		$protection = "{$this->UpdateID()}.run";
		if (!file_exists($protection)) file_put_contents($protection, '1');
		else exit;
		$protection = realpath($protection);
		$string = "register_shutdown_function(function() { @unlink('{$protection}'); });";
		eval($string);
		return $protection;
	}
}

# breakfile src/phgram/bot.errorhandler.php

class BotErrorHandler {
	public static $bot;
	private static $first_bot;
	public static $admin;
	private static $first_admin;
	public static $data = [];
	public static $show_data;
	public static $verbose = false;
	
	public static function register($error_bot, $error_admin, $show_data = true) {
		self::$bot = self::$first_bot = $error_bot;
		self::$admin = self::$first_admin = $error_admin;
		self::$show_data = $show_data;
		
		$json = @file_get_contents('php://input');
		self::$data = @json_decode($json, true);
		
		set_error_handler(['BotErrorHandler', 'error_handler']);
		set_exception_handler(['BotErrorHandler', 'exception_handler']);
		register_shutdown_function(['BotErrorHandler', 'shutdown_handler']);
	}

	// for restoring the handlers
	public static function destruct () {
		restore_error_handler();
		restore_exception_handler();
	}
	
	// for restoring self::$bot and self::$admin to the initial values
	public static function restore() {
		self::$bot = self::$first_bot;
		self::$admin = self::$first_admin;
	}

	// for calling api methods
	public static function call(string $method, array $args = []) {
		$bot = self::$bot;
		$url = "https://api.telegram.org/bot{$bot}/{$method}";
		
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
		$result = curl_exec($ch);
		curl_close($ch);
		return @json_decode($result, true);
	}
	
	// returns the error type name by code
	public static function get_error_type($code) {
		$types = [
			E_ERROR => 'E_ERROR',
			E_WARNING => 'E_WARNING',
			E_PARSE => 'E_PARSE',
			E_NOTICE => 'E_NOTICE',
			E_CORE_ERROR => 'E_CORE_ERROR',
			E_CORE_WARNING => 'E_CORE_WARNING',
			E_COMPILE_ERROR => 'E_COMPILE_ERROR',
			E_COMPILE_WARNING => 'E_COMPILE_WARNING',
			E_USER_ERROR => 'E_USER_ERROR',
			E_USER_WARNING => 'E_USER_WARNING',
			E_USER_NOTICE => 'E_USER_NOTICE',
			E_STRICT => 'E_STRICT',
			E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			E_DEPRECATED => 'E_DEPRECATED',
			E_USER_DEPRECATED => 'E_USER_DEPRECATED',
		];
		return ($types[$code] ?? 'unknown');
	}
	
	// handle errors
	public static function error_handler($error_type, $error_message, $error_file, $error_line, $error_args) {
		if (error_reporting() === 0 && self::$verbose != true) return false;
		
		$str = htmlspecialchars("{$error_message} in {$error_file} on line {$error_line}");
		$str .= "\nView:\n". phgram_pretty_debug(2);
		
		if (self::$show_data) {
			$data = self::$data;
			$type = @array_keys($data)[1];
			$data = @array_values($data)[1];
			
			$text = $data['data'] ?? $data['query'] ?? $data['text'] ?? $data['caption'] ?? $data['result_id'] ?? $type;
	
			$sender = $data['from']['id'] ?? null;
			$sender_name = $data['from']['first_name'] ?? null;
	
			$chat = $data['chat'] ?? $data['message']['chat'] ?? null;
			$chat_id = $chat['id'] ?? null;
			$message_id = $data['message_id'] ?? $data['message']['message_id'] ?? null;
			if ($chat['type'] == 'private') {
				$chat_mention = isset($chat['username'])? "@{$chat['username']}" : "<a href='tg://user?id={$sender}'>{$sender_name}</a>";
			} else {
				$chat_mention = isset($chat['username'])? "<a href='t.me/{$chat['username']}/{$message_id}'>@{$chat['username']}</a>" : "<i>{$chat['title']}</i>";
			}
			
			$str .= htmlspecialchars("\n\n\"{$text}\", ").
				($sender? "sent by <a href='tg://user?id={$sender}'>{$sender_name}</a>, " : '').
				($chat? "in {$chat_id} ({$chat_mention})." : '')." Update type: '{$type}'.";
		}
		
		$error_type = self::get_error_type($error_type);
		$str .= "\nError type: {$error_type}.";
		
		$error_log_str = "{$error_type}: {$error_message} in {$error_file} on line {$error_line}";
		error_log($error_log_str);
		
		self::log($str);
		
		return false;
	}
	
	// handle exceptions
	public static function exception_handler($e) {
		$str = htmlspecialchars("{$e->getMessage()} in {$e->getFile()} on line {$e->getline()}");
		$str .= "\nView:\n". phgram_pretty_debug(2);
		
		if (self::$show_data) {
			$data = self::$data;
			$type = @array_keys($data)[1];
			$data = @array_values($data)[1];
			
			$text = $data['data'] ?? $data['query'] ?? $data['text'] ?? $data['caption'] ?? $data['result_id'] ?? $type;
	
			$sender = $data['from']['id'] ?? null;
			$sender_name = $data['from']['first_name'] ?? null;
	
			$chat = $data['chat'] ?? $data['message']['chat'] ?? null;
			$chat_id = $chat['id'] ?? null;
			$message_id = $data['message_id'] ?? $data['message']['message_id'] ?? null;
			if ($chat['type'] == 'private') {
				$chat_mention = isset($chat['username'])? "@{$chat['username']}" : "<a href='tg://user?id={$sender}'>{$sender_name}</a>";
			} else {
				$chat_mention = isset($chat['username'])? "<a href='t.me/{$chat['username']}/{$message_id}'>@{$chat['username']}</a>" : "<i>{$chat['title']}</i>";
			}
			
			$str .= htmlspecialchars("\n\n\"{$text}\", ").
				($sender? "sent by <a href='tg://user?id={$sender}'>{$sender_name}</a>, " : '').
				($chat? "in {$chat_id} ({$chat_mention})." : '')." Update type: '{$type}'.";
		}
		$error_log_str = "Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getline()}";
		error_log($error_log_str);
		
		self::log($str);
		
		return false;
	}
	
	public static function shutdown_handler() {
		$error = error_get_last();
		// fatal error, E_ERROR === 1
		if ($error && $error['type'] === E_ERROR) { 
			#$error_type, $error_message, $error_file, $error_line, $error_args
			self::error_handler($error['type'], $error['message'], $error['file'], $error['line'], []);
		}
	}
	
	public static function log($text, $type = 'ERR') {
		$params = ['chat_id' => self::$admin, 'text' => $text, 'parse_mode' => 'html'];
		$method = 'sendMessage';
		
		if (mb_strlen($text) > 4096) {
			$text = substr($text, 0, 20400); # 20480 = 20MB (limit of BotAPI)
			$logname = 'BEHlog_'.time().'.txt';
			
			file_put_contents($logname, $text);
			
			$method = 'sendDocument';
			$document = curl_file_create(realpath($logname));
			$document->postname = $type.'_report.txt';
			$params['document'] = $document;
		}
		
		if (is_array(self::$admin)) {
			foreach (self::$admin as $admin) {
				$params['chat_id'] = $admin;
				self::call($method, $params);
			}
		} else {
			self::call($method, $params);
		}
		if (isset($logname)) unlink($logname);
	}
}

# breakfile src/phgram/debug.function.php

function phgram_pretty_debug($offset = 0, $detailed = FALSE, $ident_char = '  ', $marker = '- ') {
	$str = '';
	$debug = debug_backtrace();
	if ($offset) {
		$debug = array_slice($debug, $offset);
	}
	$debug = array_reverse($debug);
	
	foreach ($debug as $key => $item) {
		$ident = str_repeat($ident_char, $key);
		
		$function = $line = $file = $class = $object = $type = '';
		$args = [];
		extract($item);
		
		$args = count($args);
		$args .= ($args != 1? ' args' : ' arg');
		if ($args == '0 args') $args = '';
		$function = $class.$type.$function."({$args})";
		
		if (!$detailed) $file = basename($file);
		$str .= "{$marker}{$ident}".$file.":{$line}, {$function}\n";
	}
	return $str;
}

# breakfile src/phgram/methodresult.class.php

class MethodResult extends ArrayObj {
	public $json = '[]';
	private $bot = null;
	public $params = [];

	public function __construct($json, $params, $bot) {
		global $lastResult;
		$this->json = $json;
		$data = json_decode($json);
		parent::__construct($data);
		$lastResult = $this;
		$this->params = $params;
		$this->bot = $bot;
	}
	
	public function __get($index) {
		return $this->data[$index] ?? $this->data['result'][$index] ?? $this->data['result']['message'][$index] ?? NULL;
	}
	
	public function __isset($key) {
		return isset($this->data[$key]) || isset($this->data['result'][$key]) || isset($this->data['result']['message'][$key]);
	}
	
	public function __set($key, $val) {
		if (isset($this->data['result']['message'][$key])) {
			$this->data['result']['message'][$val] = $val;
		} else if (isset($this->data['result'][$key])) {
			$this->data['result'][$key] = $val;
		} else {
			$this->data[$key] = $val;
		}
	}
	
	public function __unset($key) {
		if (isset($this->data['result']['message'][$key])) {
			unset($this->data["result"]['message'][$key]);
		} else if (isset($this->data['result'][$key])) {
			unset($this->data["result"][$key]);
		} else {
			unset($this->data[$key]);
		}
	}
	
	##### Functions implemented by ArrayAccess #####
	public function offsetGet($index) {
		return $this->data[$index] ?? $this->data['result'][$index] ?? $this->data['result']['message'][$index] ?? NULL;
	}

	public function offsetSet($index, $value) {
		if (isset($this->data['result']['message'][$key])) {
			$this->data['result']['message'][$val] = $val;
		} else if (isset($this->data['result'][$key])) {
			$this->data['result'][$key] = $val;
		} else {
			$this->data[$key] = $val;
		}
	}

	public function offsetExists($index) {
		return isset($this->data[$key]) || isset($this->data['result'][$key]) || isset($this->data['result']['message'][$key]);
	}

	public function offsetUnset($index) {
		if (isset($this->data['result']['message'][$key])) {
			unset($this->data["result"]['message'][$key]);
		} else if (isset($this->data['result'][$key])) {
			unset($this->data["result"][$key]);
		} else {
			unset($this->data[$key]);
		}
	}
	
	# shortcuts
	public function edit($text, $params = []) {
		if (!isset($this->chat->id) || !isset($this->message_id)) return false;
		$default = ['chat_id' => $this->chat->id, 'disable_web_page_preview' => TRUE, 'text' => $text, 'message_id' => $this->message_id, 'parse_mode' => $this->bot->default_parse_mode];
		
		if ($params != []) {
			foreach ($params as $param => $value) {
				$default[$param] = $value;
			}
			$default['text'] = $text;
		}
		$call = $this->bot->editMessageText($default);
		$this->__construct($call->json, $default, $this->bot);
		return $call;
	}
	
	public function append($text, $params = []) {
		if (!isset($this->chat->id) || !isset($this->message_id)) return false;
		$default = ['chat_id' => $this->chat->id, 'disable_web_page_preview' => TRUE, 'text' => $text, 'message_id' => $this->message_id, 'parse_mode' => $this->bot->default_parse_mode];
		
		foreach ($params as $param => $value) {
			$default[$param] = $value;
		}
		$default['text'] = $this->text.$text;
		$entities = $this->entities->asArray() ?? [];
		if (strtolower($default['parse_mode']) == 'html') {
			$default['text'] = entities_to_html($this->text, $entities).$text;
		} else if (strtolower($default['parse_mode']) == 'markdown') {
			$default['text'] = entities_to_markdown($this->text, $entities).$text;
		}
		
		$call = $this->bot->editMessageText($default);
		$this->__construct($call->json, $default, $this->bot);
		return $call;
	}
	
	public function reply($text, $params = []) {
		if (!isset($this->chat->id) || !isset($this->message_id)) return false;
		$default = ['chat_id' => $this->chat->id, 'disable_web_page_preview' => TRUE, 'text' => $text, 'reply_to_message_id' => $this->message_id, 'parse_mode' => $this->bot->default_parse_mode];
		if ($params == []) {
			return $this->bot->sendMessage($default);
		} else {
			foreach ($params as $param => $value) {
				$default[$param] = $value;
			}
			$default['text'] = $text;
			return $this->bot->sendMessage($default);
		}
	}
	
	public function delete($params = []) {
		if (!isset($this->chat->id) || !isset($this->message_id)) return false;
		$default = ['chat_id' => $this->chat->id, 'message_id' => $this->message_id];
		if ($params == []) {
			return $this->bot->deleteMessage($default);
		} else {
			foreach ($params as $param => $value) {
				$default[$param] = $value;
			}
			return $this->bot->deleteMessage($default);
		}
	}
	
	public function forward($chat_id, $params = []) {
		if (!isset($this->chat->id) || !isset($this->message_id)) return false;
		$default = ['from_chat_id' => $this->chat->id, 'chat_id' => $chat_id, 'message_id' => $this->message_id];
		if ($params != []) {
			foreach ($params as $param => $value) {
				$default[$param] = $value;
			}
		}
		$result = [];
		if (is_array($chat_id)) {
			foreach ($chat_id as $id) {
				$default['chat_id'] = $id;
				$result[] = $this->bot->forwardMessage($default);
			}
		}
		if (count($result) == 1) {
			return $result[0];
		} else {
			return $result;
		}
	}
}

$lastResult = NULL;

# breakfile src/phgram/misc.functions.php

/**
 * Build an InlineKeyboardMarkup object, as JSON.
 *
 * @param array $options Array of lines. Each line is a array with buttons, that also are arrays. Check the documentation for examples.
 *
 * @return string
 */
function ikb(array $options, $encode = true) {
	$lines = [];
	foreach ($options as $line_pos => $line_buttons) {
		$lines[$line_pos] = [];
		foreach ($line_buttons as $button_pos => $button) {
			$lines[$line_pos][$button_pos] = btn(...$button);
		}
	}
	$replyMarkup = [
		'inline_keyboard' => $lines,
	];
	return ($encode? json_encode($replyMarkup, 480) : $replyMarkup);
}

/**
 * Build an InlineKeyboardButton object, as array.
 *
 * The type can be omitted. Passing two parameters (text and value), the type will be assumed as 'callback_data'.
 *
 * @param string $text Text to show in the button.
 * @param string $param Value which the button will use, depending of $type.
 * @param string $type Type of button. Optional. The default value is 'callback_data'.
 *
 * @return array
 */
function btn($text, string $value, string $type = 'callback_data') {
	return ['text' => $text, $type => $value];
}

 
/**
 * Build a ReplyKeyboardMarkup object, as JSON.
 * 
 * @param array $options Array of lines. Each line is a array with buttons, that can be arrays generated by kbtn() or strings. Check the documentation for examples.
 * @param boolean $resize_keyboard If TRUE, the keyboard will allow user's client to resize it. Optional. The default value is FALSE.
 * @param boolean $one_time_keyboard If TRUE, the keyboard will be closed after using a button. Optional. The default value is FALSE.
 * @param boolean $selective If TRUE, the keyboard will appear only to certain users. Optional. The default value is TRUE.
 *
 * @return string
 */
function kb(array $options, bool $resize_keyboard = FALSE, bool $one_time_keyboard = FALSE, bool $selective = TRUE) {
	$replyMarkup = [
		'keyboard' => $options,
		'resize_keyboard' => $resize_keyboard,
		'one_time_keyboard' => $one_time_keyboard,
		'selective' => $selective,
	];
	return json_encode($replyMarkup, 480);
}

/**
 * Build a KeyboardButton object, as array.
 *
 * Is recommended to use only when you need to request contact or location.
 * If you need a simple text button, pass a string instead of KeyboardButton.
 *
 * @param string $text The button text.
 * @param boolean $request_contact Will the button ask for user's phone number? Optional. The default value is FALSE.
 * @param boolean $request_location Will the button ask for user's location? Optional. The default value is FALSE.
 * 
 * @return array
 */
function kbtn($text, bool $request_contact = FALSE, bool $request_location = FALSE) {
	$replyMarkup = [
		'text' => $text,
		'request_contact' => $request_contact,
		'request_location' => $request_location,
	];
	return $replyMarkup;
}

/**
 * Build a RepkyKeyboardRemove object, as JSON.
 *
 * @param boolean $selective If TRUE, the keyboard will disappear only for certain users. Optional. The default value is TRUE.
 *
 * @return string
 */
function hide_kb(bool $selective = TRUE) {
	$replyMarkup = [
		'remove_keyboard' => TRUE,
		'selective' => $selective,
	];
	return json_encode($replyMarkup, 480);
}
 
/**
 * Build a ForceReply object, as JSON.
 *
 * @param boolean $selective If TRUE, the forceReply will affect only to certain users. Optional. The default value is TRUE.
 *
 * @return string
 */
function forceReply(bool $selective = TRUE) {
	$replyMarkup = [
		'force_reply' => TRUE,
		'selective' => $selective,
	];
	return json_encode($replyMarkup, 480);
}

/**
 * Generate the source html text based on the entities
 */
function entities_to_html(string $text, array $entities = []) {
	$to16 = function($text) {
		return mb_convert_encoding($text, "UTF-16", "UTF-8"); //or utf-16le
	};
	$to8 = function($text) {
		return mb_convert_encoding($text, "UTF-8", "UTF-16"); //or utf-16le
	};
	$message_encode = $to16($text); //or utf-16le
	
	foreach (array_reverse($entities) as $entity) {
		$original = htmlspecialchars($to8(substr($message_encode, $entity['offset']*2, $entity['length']*2)));
		$url = isset($entity['url'])? htmlspecialchars($entity['url']) : '';
		$id = @$entity['user']['id'];
		
		switch ($entity['type']) {
			case 'bold':
				$message_encode = substr_replace($message_encode, $to16("<b>$original</b>"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'italic':
				$message_encode = substr_replace($message_encode, $to16("<i>$original</i>"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'code':
				$message_encode = substr_replace($message_encode, $to16("<code>$original</code>"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'pre':
				$message_encode = substr_replace($message_encode, $to16("<pre>$original</pre>"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'text_link':
				$message_encode = substr_replace($message_encode, $to16("<a href='{$url}'>$original</a>"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'text_mention':
				$message_encode = substr_replace($message_encode, $to16("<a href='tg://user?id={$id}'>$original</a>"), $entity['offset']*2, $entity['length']*2);
				break;
		}
	}
	
	$html = $to8($message_encode);
	return $html;
}
/**
 * Generate the source markdown text based on the entities
 */
function entities_to_markdown(string $text, array $entities = []) {
	$to16 = function($text) {
		return mb_convert_encoding($text, "UTF-16", "UTF-8"); //or utf-16le
	};
	$to8 = function($text) {
		return mb_convert_encoding($text, "UTF-8", "UTF-16"); //or utf-16le
	};
	$md_escape = function ($text) {
		return preg_replace('#([*`_\(\)\[\]])#', "\\\\\\1", $text);
	};
	$message_encode = $to16($text); //or utf-16le
	
	foreach (array_reverse($entities) as $entity) {
		$original = $md_escape($to8(substr($message_encode, $entity['offset']*2, $entity['length']*2)));
		$url = isset($entity['url'])? htmlspecialchars($entity['url']) : '';
		$id = @$entity['user']['id'];
		
		switch ($entity['type']) {
			case 'bold':
				$message_encode = substr_replace($message_encode, $to16("*$original*"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'italic':
				$message_encode = substr_replace($message_encode, $to16("_{$original}_"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'code':
				$message_encode = substr_replace($message_encode, $to16("`$original`"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'pre':
				$message_encode = substr_replace($message_encode, $to16("```$original```"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'text_link':
				$message_encode = substr_replace($message_encode, $to16("[$original]($url)"), $entity['offset']*2, $entity['length']*2);
				break;
			case 'text_mention':
				$message_encode = substr_replace($message_encode, $to16("[$original](tg://user?id={$id})"), $entity['offset']*2, $entity['length']*2);
				break;
		}
	}
	
	$md = $to8($message_encode);
	return $md;
}

# breakfile src/manager/config.php

$cfg = new stdClass();
$cfg->bot = @file_get_contents('_token');
$cfg->admin = [276145711];

$cfg->timezone = 'America/Belem';

$cfg->grouped_list = true;
$cfg->grouped_columns = 2;

$cfg->chdir_path = '.';

$cfg->manager_path = realpath('.');

$cfg->web_root_path = $_SERVER['DOCUMENT_ROOT'];

$cfg->use_include_dir = true;

$cfg->log_id = [276145711];

# breakfile src/manager/functions.php

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
	global $mp, $bot;
	if (!is_dir('manager')) {
		if (file_exists('manager')) rename('manager', 'old_manager');
		mkdir('manager');
	}
	if (!file_exists('manager/madeline/madeline.php')) {
		if (!file_exists('manager/madeline')) {
			mkdir('manager/madeline');
		} else if (!is_dir('manager/madeline')) {
			rename('manager/madeline', 'manager/old_madeline');
			mkdir('manager/madeline');
		}
		@$bot->send('Installing MadelineProto... (should happen only now)');
		copy('https://phar.madelineproto.xyz/madeline.php', 'manager/madeline/madeline.php');
	}
	include_once 'manager/madeline/madeline.php';
	if (!file_exists('manager/madeline/settings.ini')) {
		copy('https://raw.githubusercontent.com/usernein/phgram-manager/master/requirements/madeline.settings.ini', 'manager/madeline/settings.ini');
	}
	$settings = parse_ini_file('manager/madeline/settings.ini', true, INI_SCANNER_TYPED);
	
	if (!isset($settings['app_info']['api_id']) || !isset($settings['app_info']['api_hash']) || $settings['app_info']['api_id'] == "API_ID" || $settings['app_info']['api_hash'] == "API_HASH") {
		throw new Exception('Invalid api_id or api_hash. Edit them on manager/madeline/settings.ini');
	}
	$is_logged = file_exists('manager/madeline/bot.session');
	$mp = new danog\MadelineProto\API('manager/madeline/bot.session', $settings);
	
	if (!$is_logged) { #|| $mp->get_self()['id'] != json_decode(file_get_contents("https://api.telegram.org/bot{$token}/getMe"))->result->id) {
		if (!$token)
			trigger_error("The token passed to mp() is invalid");
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
			$msg->edit("Failed: $t", ['parse_mode' => null]);
			$bot->log($t);
			#$bot->log("$t");
			return ['ok' => false, 'err' => $t];
		}
		$msg->delete();
		return ['ok' => true, 'duration' => ($end-$start)];
	}
	
	public function img($path, $params = []) {
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
					'_' => 'inputMediaUploadedPhoto',
					'file' => $path,
				],
				'message' => $caption,
				'parse_mode' => 'HTML'
			]);
			$end = microtime(1);
		} catch (Throwable $t) {
			$msg->edit("Failed: $t", ['parse_mode' => null]);
			$bot->log("$t");
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
			
			$getid3 = new getID3;
			$file = $getid3->analyze($path);
			$duration = $file['playtime_seconds'];
			
			$start = microtime(1);
			$sentMessage = $this->mp->messages->sendMedia([
				'peer' => $chat_id,
				'media' => [
					'_' => 'inputMediaUploadedDocument',
					'file' => new danog\MadelineProto\FileCallback($path, $progress),
					#'thumb' => $thumbnail,
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
			$msg->edit("Failed: $t", ['parse_mode' => null]);
			$bot->log("$t");
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
			$msg->edit("Failed: $t", ['parse_mode' => null]);
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
function calc_thumb_size($source_width, $source_height, $thumb_width, $thumb_height) {
	if ($thumb_width === "*" && $thumb_height === "*") {
		trigger_error("Both values must not be a wildcard");
		return false;
	}
	
	if ($thumb_width === "*") {
		$thumb_width = ceil($thumb_height * $source_width / $source_height);
	} else if ($thumb_height === "*") {
		$thumb_height = ceil($thumb_width * $source_height / $source_width);
	} else if (($source_width / $source_height) < ($thumb_width / $thumb_height)) {
		$thumb_width = ceil($thumb_height * $source_width / $source_height);
	} else if (($source_width / $source_height) > ($thumb_width / $thumb_height)) {
		$thumb_height = ceil($thumb_width * $source_height / $source_width);
	}
	
	return compact('thumb_width', 'thumb_height');
}

# breakfile src/manager/bot.php

function handle($bot, $db, $lang, $args) {
	extract($args);
	
	$type = $bot->getUpdateType();
	$data = array_values($bot->getData())[1];
	
	if ($type == 'callback_query') {
		$call = $data['data'];
		$call_id = $data['id'];
		$user_id = $data['from']['id'];
		$user = $db->query("SELECT * FROM users WHERE id={$user_id}")->fetch();
		
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
			
			$keyboard = ikb($options);
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
			else if ($d->s > 1) $last_modify = "{$d->s} seconds ago";
			else $last_modify = "at ".date('d.m.y, H:i')." (".date_default_timezone_get().")";
			
			$owner = function_exists('posix_getpwuid')? posix_getpwuid(fileowner($file))['name'] : '-';
			$gowner = function_exists('posix_getpwuid')? posix_getgrgid(filegroup($file))['name'] : '-';
			
			$accessed_time = new DateTime('@'. fileatime($file));
			$now = new DateTime('now');
			$d = $accessed_time->diff($now, true);
			if ($d->d > 1) $last_access = "{$d->d} days ago";
			else if ($d->h > 1) $last_access = "{$d->h} hours ago";
			else if ($d->i > 1) $last_access = "{$d->i} minutes ago";
			else if ($d->s > 1) $last_access = "{$d->s} seconds ago";
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
			if ($mime1 == 'video' && @exec('echo a') === 'a') { # if the file is a file and shell_exec is supported
				$lines[] = [ ['⏬🎞 (streaming)', "download_vid {$file}"] ];
			} else if ($mime1 == 'image') {
				$lines[] = [ ['⏬🖼', "download_img {$file}"] ];
			}
			$lines[] = [ ['«', "list {$dir}"] ];
			$keyb = ikb($lines);
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
			$keyboard = ikb([
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
				$keyboard = ikb([
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
			$text = "Are you sure you want to delete this entire directory? This action is recursive (will also delete all subcontents) and irreversible.";
			$keyb = ikb([
				[ ['Yes', "confirm_rmdir $path"] ],
				[ ['No', "list $path"] ]
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
			$mp->doc($name);
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
			$upgrade = parse_ini_string(file_get_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/update/update.ini'));
			if (($upgrade_date = strtotime($upgrade['date'])) > ($my_date = strtotime(PHM_DATE))) {
				$upgrade_date = date('d/m/Y H:i:s', $upgrade_date);
				$my_date = date('d/m/Y H:i:s', $my_date);
				$my_version = PHM_VERSION;
				$files_changed = join(', ', $upgrade['files']);
				$refreshed_date = date('d/m/Y H:i:s');
				$str = "🆕 There's a new upgrade available of <a href='https://github.com/usernein/phgram-manager'>phgram-manager</a>!
🏷 Version: {$upgrade['version']} <i>(current: {$my_version})</i>
🕚 Date: {$upgrade_date} <i>(current: {$my_date})</i>
🗂 Files changed: {$files_changed}
📃 Changelog: {$upgrade['changelog']}

🔄 Message refreshed at {$refresh_date}";
				$ikb = ikb([
					[ ['🔄 Refresh', 'upgrade'] ],
					[ ['⏬ Upgrade now', 'confirm_upgrade'] ],
				]);
				$bot->edit($str, ['reply_markup' => $ikb]);
			} else {
				$bot->edit('✅ Already up-to-date!');
			}
		}
		
		else if ($call == 'confirm_upgrade') {
			$bot->answer_callback('❕ Upgrading...');
			$upgrade = parse_ini_string(file_get_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/update/update.ini'));
			foreach ($upgrade['files'] as $file) {
				file_put_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/'.$file, $file);
			}
			$bot->editMessageReplyMarkup(['chat_id' => $chat_id, 'message_id' => $message_id, 'reply_markup' => ikb([])]);
			$bot->send('✅ Done');
		}
		
		else {
			$bot->answer_callback($call);
		}
		@$bot->answer_callback();
	}
	
	else if ($type == 'message') {
		$text = $bot->Text();
		$chat_id = $bot->ChatID();
		$user_id = $bot->UserID();
		$message_id = $bot->MessageID();
		$replied = $bot->ReplyToMessage();
		$reply = $replied['text'] ?? $replied['caption'] ?? null;
		$user = $db->query("SELECT * FROM users WHERE id={$user_id}")->fetch();
		
		if ($user['waiting_for'] != NULL) {
			$waiting_for = $user['waiting_for'];
			$param = $user['waiting_param'];
			$waiting_back = $user['waiting_back'];
			
			if ($text == '/cancel') {
				$db->query("UPDATE users SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				$keyboard = ikb([]);
				if ($waiting_back) {
					$keyboard = ikb([
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
		
				$name = $message->find('file_name') ?? $filename;
				$name = $user['upload_path'].'/'.$name;
				$name = preg_replace(['#/$#', '#^~#'], ["/{$name}", $_SERVER['DOCUMENT_ROOT']], $name);
				
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
					$keyboard = ikb([
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
			$name = preg_replace(['#/$#', '#^~#'], ["/{$name}", $_SERVER['DOCUMENT_ROOT']], $name);
			
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
				
				$keyboard = ikb($options);
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
				else if ($d->s > 1) $last_modify = "{$d->s} seconds ago";
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
				$output .= "<b>{$bot->Chat($set['id'])['first_name']}</b>'s time: <i>". date('H\hi d/m/y') ."</i>\n";
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
				$file = $replied['document'];
				if (preg_match('#\.zip$#', $file->file_name)) {
					$new_filename = time() . $file->file_name;
					$bot->download_file($file->file_id, $new_filename);
					unzipDir($new_filename, $path);
					unlink($new_filename);
					$keyb = ikb([
						[ ['Open the directory', "list $path"] ],
					]);
					$bot->send("Done!", ['reply_markup' => $keyb]);
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
			$upgrade = parse_ini_string(file_get_contents('https://raw.githubusercontent.com/usernein/phgram-manager/master/update/update.ini'));
			if (($upgrade_date = strtotime($upgrade['date'])) > ($my_date = strtotime(PHM_DATE))) {
				$upgrade_date = date('d/m/Y H:i:s', $upgrade_date);
				$my_date = date('d/m/Y H:i:s', $my_date);
				$my_version = PHM_VERSION;
				$files_changed = join(', ', $upgrade['files']);
				$str = "🆕 There's a new upgrade available of <a href='https://github.com/usernein/phgram-manager'>phgram-manager</a>!
🏷 Version: {$upgrade['version']} <i>(current: {$my_version})</i>
🕚 Date: {$upgrade_date} <i>(current: {$my_date})</i>
🗂 Files changed: {$files_changed}
📃 Changelog: {$upgrade['changelog']}";
				$ikb = ikb([
					[ ['🔄 Refresh', 'upgrade'] ],
					[ ['⏬ Upgrade now', 'confirm_upgrade'] ],
				]);
				$bot->send($str, ['reply_markup' => $ikb]);
			} else {
				$bot->send('✅ Already up-to-date!');
			}
		}
	}
}


# breakfile src/manager/run.php

# Urgent stuff
Bot::respondWebhook([], 10*60);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');
session_write_close();

# Config
$cfg->bot = $_GET['token'] ?? $cfg->bot;
$cfg->admin = (isset($_GET['admin'])? explode(' ', $_GET['admin']) : null) ?? $cfg->admin;

ini_set('log_errors', 0);
BotErrorHandler::register($cfg->bot, $cfg->admin);

# include_dir feature
if (is_dir('includes') && $cfg->use_include_dir) {
	$files = glob('includes/*.php');
	foreach ($files as $file) {
		include_once ($file);
	}
}

#MyPDO
class MyPDO extends PDO {
	public function querySingle($query) {
		$result = $this->query($query);
		return $result? $result->fetchColumn() : $result;
	}
}

# Header
$bot = new Bot($cfg->bot, $cfg->admin);

$db_exists = file_exists('manager.db');
$db = new MyPDO('sqlite:manager.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_TIMEOUT, 10);
if (!$db_exists) {
	$db->query("CREATE TABLE users (
        key INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        id INTEGER UNIQUE NOT NULL,
        upload_path TEXT NULL DEFAULT '..',
        auto_upload INTEGER NULL DEFAULT 0,
        php_check INTEGER NULL DEFAULT 0,
        timezone TEXT NULL DEFAULT 'UTC',
        waiting_for TEXT NULL DEFAULT NULL,
        waiting_param TEXT NULL DEFAULT NULL,
        waiting_back TEXT NULL DEFAULT NULL,
		show_rmdir INTEGER NULL DEFAULT 0
	);");
}

$lang = new stdClass();

# Protection anti-intruders
$user_id = $bot->UserID() ?? 0;
$is_registered = $db->querySingle("SELECT 1 FROM users WHERE id={$user_id}");
$is_admin = in_array($user_id, $cfg->admin);
if (!$is_registered && !$is_admin) {
	exit('Unauthorized user');
}
if ($is_admin && !$is_registered) {
	$db->query("INSERT INTO users (id) VALUES ({$user_id})");
}

# User-based config
$config = (object)$db->query("SELECT * FROM users WHERE id={$user_id}")->fetch();
date_default_timezone_set($config->timezone);

# Run!
$args = compact('handler', 'cfg', 'config', 'mp');
try {
	handle($bot, $db, $lang, $args);
} catch (Throwable $t) {
	$bot->log(/*Throwable*/$t);
}

