<?php
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