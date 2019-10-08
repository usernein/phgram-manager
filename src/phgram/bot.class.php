<?php
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
		}
		$params = ['parse_mode' => $parse_mode, 'disable_web_page_preview' => TRUE, 'text' => $text];
		
		if (mb_strlen($text) > 4096) {
			$name = 'log_'.$this->UpdateID().'.txt';
		
			$file_exists = file_exists($name);
			$contents = ($file_exists? file_get_contents($name) : 0);
			
			file_put_contents($name, $text);
			
			$url = "https://api.telegram.org/bot{$this->bot_token}/sendDocument";
			$params = [];
			$document = curl_file_create(realpath($name));
			if (file_exists(realpath($name)) && !is_dir(realpath($name))) {
				$params['document'] = $document;
			} else {
				$params['document'] = $name;
			}
			
			if ($file_exists) {
				file_put_contents($name, $contents);
			} else {
				#unlink($name);
			}
		}
		
		if (is_array($this->debug_admin)) {
			foreach ($this->debug_admin as $admin) {
				$params['chat_id'] = $admin;
				$this->sendAPIRequest($url, $params);
			}
		} else {
			$params['chat_id'] = $this->debug_admin;
			$this->sendAPIRequest($url, $params);
		}
		
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