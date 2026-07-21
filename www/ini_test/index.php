<?php

function iniTestLog($path, $source, $status, $started)
{
	error_log(sprintf('ini_test source=%s status=%s duration_ms=%d path=%s', $source, $status, (microtime(true) - $started) * 1000, $path));
}

function iniTestFallback($path)
{
	return array(
		'status' => 302,
		'headers' => array(
			'Location' => '/online/ini/' . $path,
			'Cache-Control' => 'no-store',
		),
		'body' => '',
	);
}

function iniTestSignedRequest($method, $key, $timestamp = null, $config = null)
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
	$amzDate = gmdate('Ymd\THis\Z', $timestamp);
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

function iniTestR2Request($method, $key)
{
	if (!function_exists('curl_init')) {
		throw new RuntimeException('PHP cURL extension is unavailable');
	}

	$request = iniTestSignedRequest($method, $key);
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

function iniTestResolve($method, $path, $requestR2 = null)
{
	$started = microtime(true);
	if ($method !== 'GET' && $method !== 'HEAD') {
		return array('status' => 405, 'headers' => array('Allow' => 'GET, HEAD'), 'body' => 'Method not allowed');
	}

	if (!is_string($path) || preg_match('#^(?:[A-Za-z0-9_-]+/)+[A-Za-z0-9_-]+\.ini$#D', $path) !== 1) {
		return array('status' => 400, 'headers' => array(), 'body' => 'Invalid INI path');
	}

	try {
		$response = $requestR2 === null
			? iniTestR2Request($method, $path)
			: call_user_func($requestR2, $method, $path);
	} catch (Throwable $error) {
		iniTestLog($path, 'r2', 'error-fallback', $started);
		return iniTestFallback($path);
	}

	if (!isset($response['status']) || $response['status'] !== 200) {
		iniTestLog($path, 'r2', (isset($response['status']) ? $response['status'] : 'unknown') . '-fallback', $started);
		return iniTestFallback($path);
	}
	iniTestLog($path, 'r2', 200, $started);

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

function iniTestSend($response)
{
	http_response_code($response['status']);
	foreach ($response['headers'] as $name => $value) {
		header($name . ': ' . $value);
	}
	echo $response['body'];
}

if (!defined('INI_TEST_SKIP_MAIN')) {
	$configPath = __DIR__ . '/../online/config_rusefi.php';
	if (is_file($configPath)) {
		require_once $configPath;
	}
	iniTestSend(iniTestResolve(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET', isset($_GET['path']) ? $_GET['path'] : null));
}
