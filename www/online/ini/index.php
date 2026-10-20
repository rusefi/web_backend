<?php

function onlineIniLog($path, $source, $status, $started)
{
	error_log(sprintf('online_ini source=%s status=%s duration_ms=%d path=%s', $source, $status, (microtime(true) - $started) * 1000, $path));
}

function onlineIniValidPath($path)
{
	return is_string($path) && preg_match('#^(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_-]+\.ini$#D', $path) === 1;
}

function onlineIniLocalOnly($path)
{
	$parts = explode('/', $path);
	$yearIndex = count($parts) - 5;
	$year = isset($parts[$yearIndex]) && preg_match('/^[0-9]{4}$/D', $parts[$yearIndex]) === 1
		&& preg_match('/^[0-9]{2}$/D', $parts[$yearIndex + 1]) === 1
		&& preg_match('/^[0-9]{2}$/D', $parts[$yearIndex + 2]) === 1
		? $parts[$yearIndex]
		: null;

	return $year === '2026';
}

function onlineIniSignedRequest($method, $key, $timestamp = null, $config = null)
{
	if ($config === null) {
		$required = array('R2_ENDPOINT', 'R2_BUCKET', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY');
		foreach ($required as $name) {
			if (!defined($name)) {
				throw new RuntimeException('Incomplete R2 configuration');
			}
		}
		$config = array(
			'endpoint' => R2_ENDPOINT,
			'bucket' => R2_BUCKET,
			'access_key' => R2_ACCESS_KEY_ID,
			'secret_key' => R2_SECRET_ACCESS_KEY,
		);
	}

	$endpoint = rtrim($config['endpoint'], '/');
	$host = parse_url($endpoint, PHP_URL_HOST);
	if (!$host || !$config['bucket'] || !$config['access_key'] || !$config['secret_key']) {
		throw new RuntimeException('Incomplete R2 configuration');
	}

	$timestamp = $timestamp === null ? time() : $timestamp;
	$date = gmdate('Ymd', $timestamp);
	$amzDate = gmdate('Ymd\\THis\\Z', $timestamp);
	$canonicalUri = '/' . rawurlencode($config['bucket']) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
	$payloadHash = hash('sha256', '');
	$canonicalHeaders = "host:$host\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n";
	$signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
	$scope = "$date/auto/s3/aws4_request";
	$canonicalRequest = "$method\n$canonicalUri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
	$stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
	$dateKey = hash_hmac('sha256', $date, 'AWS4' . $config['secret_key'], true);
	$regionKey = hash_hmac('sha256', 'auto', $dateKey, true);
	$serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
	$signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
	$signature = hash_hmac('sha256', $stringToSign, $signingKey);

	return array(
		'url' => $endpoint . $canonicalUri,
		'headers' => array(
			'Authorization: AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
			'Host: ' . $host,
			'X-Amz-Content-Sha256: ' . $payloadHash,
			'X-Amz-Date: ' . $amzDate,
		),
	);
}

function onlineIniR2Request($method, $key, $configPath = null)
{
	if ($configPath === null) {
		$configPath = __DIR__ . '/../config_rusefi.php';
	}
	if (is_file($configPath)) {
		require_once $configPath;
	}

	if (!function_exists('curl_init')) {
		throw new RuntimeException('PHP cURL extension is unavailable');
	}

	$request = onlineIniSignedRequest($method, $key);
	$responseHeaders = array();
	$curl = curl_init($request['url']);
	curl_setopt_array($curl, array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $request['headers'],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_TIMEOUT => 20,
		CURLOPT_NOBODY => $method === 'HEAD',
		CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
			}
			return strlen($line);
		},
	));
	$body = curl_exec($curl);
	$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	$error = curl_error($curl);
	curl_close($curl);

	if ($body === false) {
		throw new RuntimeException('R2 request failed: ' . $error);
	}

	return array('status' => $status, 'headers' => $responseHeaders, 'body' => $body);
}

function onlineIniLocalResponse($method, $path)
{
	$root = realpath(__DIR__);
	$file = realpath(__DIR__ . '/' . $path);
	if ($root === false || $file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file) || !is_readable($file)) {
		return null;
	}

	$size = filesize($file);
	$headers = array(
		'Content-Type' => 'application/octet-stream',
		'Cache-Control' => 'public, max-age=31536000, immutable',
	);
	if ($size !== false) {
		$headers['Content-Length'] = (string)$size;
	}
	$modified = filemtime($file);
	if ($modified !== false) {
		$headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $modified) . ' GMT';
	}
	return array('status' => 200, 'headers' => $headers, 'body' => $method === 'HEAD' ? '' : file_get_contents($file));
}

function onlineIniResolve($method, $path, $requestR2 = null)
{
	$started = microtime(true);
	if ($method !== 'GET' && $method !== 'HEAD') {
		return array('status' => 405, 'headers' => array('Allow' => 'GET, HEAD'), 'body' => 'Method not allowed');
	}
	if (!onlineIniValidPath($path)) {
		return array('status' => 400, 'headers' => array(), 'body' => 'Invalid INI path');
	}

	if (onlineIniLocalOnly($path)) {
		$response = onlineIniLocalResponse($method, $path);
		onlineIniLog($path, 'local-policy', $response === null ? 404 : 200, $started);
		return $response === null
			? array('status' => 404, 'headers' => array(), 'body' => 'INI not found')
			: $response;
	}

	try {
		$response = $requestR2 === null
			? onlineIniR2Request($method, $path)
			: call_user_func($requestR2, $method, $path);
	} catch (Throwable $error) {
		onlineIniLog($path, 'r2', 'error-fallback', $started);
		$local = onlineIniLocalResponse($method, $path);
		return $local === null
			? array('status' => 502, 'headers' => array(), 'body' => 'INI storage unavailable')
			: $local;
	}

	if (isset($response['status']) && $response['status'] === 200 && isset($response['body'])) {
		onlineIniLog($path, 'r2', 200, $started);
		$headers = array(
			'Content-Type' => 'application/octet-stream',
			'Cache-Control' => 'public, max-age=31536000, immutable',
		);
		if (isset($response['headers']['content-length']) && ctype_digit($response['headers']['content-length'])) {
			$headers['Content-Length'] = $response['headers']['content-length'];
		}
		if (isset($response['headers']['etag'])) {
			$headers['ETag'] = $response['headers']['etag'];
		}
		return array('status' => 200, 'headers' => $headers, 'body' => $method === 'HEAD' ? '' : $response['body']);
	}

	$status = isset($response['status']) ? $response['status'] : 'unknown';
	onlineIniLog($path, 'r2', $status . '-fallback', $started);
	$local = onlineIniLocalResponse($method, $path);
	if ($local !== null) {
		return $local;
	}
	if ($status === 404) {
		return array('status' => 404, 'headers' => array(), 'body' => 'INI not found');
	}
	return array('status' => 503, 'headers' => array('Retry-After' => '60'), 'body' => 'INI storage unavailable');
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
