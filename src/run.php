<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');

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
		show_rmdir INTEGER NULL DEFAULT 0,
		ask_upload INTEGER NULL DEFAULT 1
	);");
}

$lang = new stdClass();

# Protection anti-intruders
$user_id = $bot->UserID() ?? 0;
$is_registered = $db->querySingle("SELECT 1 FROM users WHERE id={$user_id}");
$is_admin = in_array($user_id, $cfg->admin);
if (!$user_id || (!$is_registered && !$is_admin)) {
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