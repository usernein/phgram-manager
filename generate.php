<?php
$argv = [$argv[0], $argv[2], $argv[1]];
$current = parse_ini_file('update/update.ini');
if (!isset($argv[1]) || $current['version'] == $argv[1]) {
	$cases = explode('.', $current['version']);
	$cases[2]++;
	$argv[1] = join('.', $cases);
}
if (!isset($argv[2])) {
	$argv[2] = $current['changelog'];
}
$update_str = "version = %s
date = \"%s\"
changelog = \"{$argv[2]}\"
files[] = manager.php
";
date_default_timezone_set('America/Belem');
$date = new DateTime('now', (new DateTimeZone('America/Belem')));
$date_str = $date->format(DateTime::RFC3339);

$update_str = sprintf($update_str, $argv[1], $date_str);
file_put_contents('update/update.ini', $update_str);
$update = parse_ini_string($update_str);

$str = "<?php
# Urgent stuff
session_write_close();
set_time_limit(10*60);
if (!file_exists('phgram.phar')) {
	copy('https://raw.githubusercontent.com/usernein/phgram/master/phgram.phar', 'phgram.phar');
}
require 'phgram.phar';
use \phgram\{Bot, BotErrorHandler, ArrayObj};
use function \phgram\{ikb, show};
Bot::closeConnection();
define('PHM_VERSION', '{$update['version']}');
define('PHM_DATE', '{$date_str}');
";

$files = [
	'src/config.php',
	'src/functions.php',
	'src/bot.php',
	'src/run.php'
];

foreach ($files as $file) {
	$update_str .= "\nfilemtimes['".basename($file)."'] = ".filemtime($file);
	$contents = file_get_contents($file);
	$contents = str_replace(['<?php', '<?', '?>'], '', $contents);
	$str .= "# breakfile {$file}\n{$contents}\n\n";
}

file_put_contents('update/update.ini', $update_str);
file_put_contents('manager.php', $str);

# Share the file through Termux
exec('termux-open manager.php --send --chooser');