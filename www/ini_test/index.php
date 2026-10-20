<?php

function iniTestRedirectPath($path)
{
	if (!is_string($path) || preg_match('#^(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_-]+\.ini$#D', $path) !== 1) {
		return array('status' => 400, 'headers' => array(), 'body' => 'Invalid INI path');
	}
	return array(
		'status' => 302,
		'headers' => array('Location' => '/online/ini/' . $path, 'Cache-Control' => 'no-store'),
		'body' => '',
	);
}

function iniTestSend($response)
{
	http_response_code($response['status']);
	foreach ($response['headers'] as $name => $value) {
		header($name . ': ' . $value);
	}
	echo $response['body'];
}

if (!defined('INI_TEST_SKIP_MAIN')) {
	$path = isset($_GET['path']) ? $_GET['path'] : null;
	iniTestSend(iniTestRedirectPath($path));
}
