<?php

define('INI_TEST_SKIP_MAIN', true);
require_once __DIR__ . '/../www/ini_test/index.php';

function check($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$paths = array(
	'rusefi/master/2023/11/30/uaefi/2573691820.ini',
	'rusefi/development/2026/07/21/proteus_f7/123456789.ini',
);

foreach ($paths as $path) {
	$response = iniTestResolve('GET', $path, function ($method, $key) use ($path) {
		check($method === 'GET', 'R2 method mismatch');
		check($key === $path, 'R2 key must preserve the whitelabel path');
		return array(
			'status' => 200,
			'headers' => array('content-length' => '7', 'etag' => '"test"'),
			'body' => 'fixture',
		);
	});

	check($response['status'] === 200, 'R2 success status mismatch');
	check($response['body'] === 'fixture', 'R2 success body mismatch');
	check($response['headers']['Content-Length'] === '7', 'Content-Length mismatch');
}

$missingPath = 'white-label/release/2024/01/02/board-name/42.ini';
$response = iniTestResolve('GET', $missingPath, function ($method, $key) use ($missingPath) {
	check($key === $missingPath, 'Missing object R2 key mismatch');
	return array('status' => 404, 'headers' => array(), 'body' => '');
});
check($response['status'] === 302, 'R2 miss did not fall back');
check($response['headers']['Location'] === '/online/ini/' . $missingPath, 'Whitelabel fallback mismatch');

$response = iniTestResolve('HEAD', $paths[0], function () {
	return array('status' => 200, 'headers' => array('content-length' => '7'), 'body' => 'fixture');
});
check($response['status'] === 200 && $response['body'] === '', 'HEAD returned a body');

$called = false;
$response = iniTestResolve('GET', '../online/config_rusefi.php', function () use (&$called) {
	$called = true;
});
check(!$called && $response['status'] === 400, 'Traversal path reached R2');
check(iniTestResolve('POST', $paths[0])['status'] === 405, 'POST was accepted');

$request = iniTestSignedRequest('GET', 'rusefi/file.ini', 0, array(
	'endpoint' => 'https://example.r2.cloudflarestorage.com',
	'bucket' => 'test-bucket',
	'access_key' => 'access',
	'secret_key' => 'secret',
));
check($request['url'] === 'https://example.r2.cloudflarestorage.com/test-bucket/rusefi/file.ini', 'R2 URL mismatch');
check(strpos($request['headers'][0], 'Signature=fb2b885202776d8d6bd3ebaa1a67e4a5e1c32524c7ead754018219d1a367f8f2') !== false, 'R2 signature mismatch');

echo "ini_test checks passed\n";
