<?php

function onlineIniValidPath($path)
{
	return is_string($path) && preg_match('#^(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_-]+\.ini$#D', $path) === 1;
}

function onlineIniLocalResponse($method, $path)
{
	if (!onlineIniValidPath($path)) {
		return null;
	}

	$root = realpath(__DIR__);
	$file = realpath(__DIR__ . '/' . $path);
	if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file) || !is_readable($file)) {
		return null;
	}

	$size = filesize($file);
	$modified = filemtime($file);
	$headers = array('Content-Type' => 'application/octet-stream');
	if ($size !== false) {
		$headers['Content-Length'] = (string)$size;
	}
	if ($modified !== false) {
		$headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $modified) . ' GMT';
	}

	$body = $method === 'HEAD' ? '' : file_get_contents($file);
	return $body === false ? null : array('status' => 200, 'headers' => $headers, 'body' => $body);
}

function onlineIniResolve($method, $path)
{
	if ($method !== 'GET' && $method !== 'HEAD') {
		return array('status' => 405, 'headers' => array('Allow' => 'GET, HEAD'), 'body' => 'Method not allowed');
	}
	if (!onlineIniValidPath($path)) {
		return array('status' => 400, 'headers' => array(), 'body' => 'Invalid INI path');
	}

	$response = onlineIniLocalResponse($method, $path);
	return $response === null
		? array('status' => 404, 'headers' => array(), 'body' => 'INI not found')
		: $response;
}

function onlineIniSend($response)
{
	http_response_code($response['status']);
	foreach ($response['headers'] as $name => $value) {
		header($name . ': ' . $value);
	}
	echo $response['body'];
}

if (!defined('ONLINE_INI_SKIP_MAIN')) {
	$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
	$path = isset($_GET['path']) ? $_GET['path'] : null;
	onlineIniSend(onlineIniResolve($method, $path));
}
