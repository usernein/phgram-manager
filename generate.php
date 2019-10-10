<?php
if (!isset($argv[1])) exit("Pass changelog\n");
if (!isset($argv[2])) exit("Pass version\n");
$update = parse_ini_file('update/update.ini');
if ($update['version'] == $argv[2]) exit("Can't use same version.\n");

$update = "version = %s
date = \"%s\"
files[] = manager.php
changelog = \"{$argv[1]}\"
";
date_default_timezone_set('America/Belem');
$date = new DateTime('now');
$date_str = $date->format(DateTime::RFC3339);
$update = sprintf($update, $argv[2], $date_str);
file_put_contents('update/update.ini', $update);

$str = "<?php
define('PHM_VERSION', '{$argv[2]}');
define('PHM_DATE', '{$date_str}');
";
$glob = glob('src/phgram/*');

foreach ($glob as $file) {
	if (basename($file) == 'index.php') continue;
	echo $file."\n";
	$contents = file_get_contents($file);
	$contents = str_replace(['<?php', '<?', '?>'], '', $contents);
	$name = ($file);
	$str .= "# breakfile {$name}\n{$contents}\n\n";
}

$glob = [
	'config.php',
	'functions.php',
	'bot.php',
	'run.php'
];

foreach ($glob as $file) {
	$file = 'src/manager/'.$file;
	echo $file."\n";
	$contents = file_get_contents($file);
	$contents = str_replace(['<?php', '<?', '?>'], '', $contents);
	$name = ($file);
	$str .= "# breakfile {$name}\n{$contents}\n\n";
}

file_put_contents('manager.php', $str);