<?php
$str = '<?php
';
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