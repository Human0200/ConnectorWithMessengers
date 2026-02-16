<?php
define('C_REST_CLIENT_ID', $_ENV['BITRIX_CLIENT_ID']); // Application ID
define('C_REST_CLIENT_SECRET', $_ENV['BITRIX_CLIENT_SECRET']); // Application key
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
// or
// define('C_REST_WEB_HOOK_URL','https://rest-course.bitrix24.ru/rest/1/ррррррр/');//url on creat Webhook

// define('C_REST_CURRENT_ENCODING','windows-1251');
// define('C_REST_IGNORE_SSL',true);//turn off validate ssl by curl
// define('C_REST_LOG_TYPE_DUMP',true); //logs save var_export for viewing convenience
//define('C_REST_BLOCK_LOG', true); // turn off default logs
// define('C_REST_LOGS_DIR', __DIR__ .'/logs/'); //directory path to save the log
