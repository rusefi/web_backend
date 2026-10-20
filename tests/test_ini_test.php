<?php

define('ONLINE_INI_SKIP_MAIN', true);
require_once __DIR__ . '/../www/online/ini/index.php';
define('INI_TEST_SKIP_MAIN', true);
require_once __DIR__ . '/../www/ini_test/index.php';

function check($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function makeIniFixture($path, $content)
{
	$fullPath = __DIR__ . '/../www/online/ini/' . $path;
	$directory = dirname($fullPath);
	if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
		throw new RuntimeException('Could not create local INI fixture directory');
	}
	if (file_put_contents($fullPath, $content) === false) {
		throw new RuntimeException('Could not create local INI fixture');
	}
	return $fullPath;
}

$fixturePrefix = 'test-r2-' . getmypid();
$oldPath = $fixturePrefix . '/release/2024/01/02/board/42.ini';
$static2026 = $fixturePrefix . '/release/2026/01/02/NUCLEO-F7/43.ini';
$olderNucleoPath = $fixturePrefix . '/release/2024/01/02/NUCLEO-F7/44.ini';
$olderDiscoverySubstringPath = $fixturePrefix . '/release/2024/01/02/discoveryfoo/49.ini';
$olderNucleoSubstringPath = $fixturePrefix . '/release/2024/01/02/myNucleoBoard/50.ini';
$olderF407DiscoveryPath = $fixturePrefix . '/release/2024/01/02/F407-Discovery/45.ini';
$fixtures = array();

try {
	$fixtures[] = makeIniFixture($oldPath, "local fallback\n");
	$fixtures[] = makeIniFixture($static2026, "2026 static bytes\0\n");
	$fixtures[] = makeIniFixture($olderNucleoPath, "older local nucleo bytes\n");
	$fixtures[] = makeIniFixture($olderDiscoverySubstringPath, "older local discovery bytes\n");
	$fixtures[] = makeIniFixture($olderNucleoSubstringPath, "older local nucleo substring bytes\n");
	$fixtures[] = makeIniFixture($olderF407DiscoveryPath, "older local f407 discovery bytes\n");
	$outside = tempnam(sys_get_temp_dir(), 'ini-outside-');
	file_put_contents($outside, 'outside bytes');
	$symlinkPath = __DIR__ . '/../www/online/ini/' . $fixturePrefix . '/release/2024/01/02/board/48.ini';
	if (!symlink($outside, $symlinkPath)) {
		throw new RuntimeException('Could not create symlink containment fixture');
	}
	$fixtures[] = $symlinkPath;

	$r2Bytes = "r2-owned bytes\0with binary\n";
	$r2Hit = onlineIniResolve('GET', $oldPath, function ($method, $key) use ($oldPath, $r2Bytes) {
		check($method === 'GET' && $key === $oldPath, 'Older keys must be requested from R2 first');
		return array('status' => 200, 'headers' => array('content-length' => (string)strlen($r2Bytes)), 'body' => $r2Bytes);
	});
	check($r2Hit['status'] === 200 && hash('sha256', $r2Hit['body']) === hash('sha256', $r2Bytes), 'R2 hit did not return exact bytes');
	$r2Head = onlineIniResolve('HEAD', $oldPath, function ($method, $key) use ($oldPath, $r2Bytes) {
		check($method === 'HEAD' && $key === $oldPath, 'R2 HEAD request mismatch');
		return array('status' => 200, 'headers' => array('content-length' => (string)strlen($r2Bytes)), 'body' => '');
	});
	check($r2Head['status'] === 200 && $r2Head['body'] === '' && $r2Head['headers']['Content-Length'] === (string)strlen($r2Bytes), 'R2 HEAD response mismatch');

	$miss = onlineIniResolve('GET', $oldPath, function () {
		return array('status' => 404, 'headers' => array(), 'body' => '');
	});
	check($miss['status'] === 200 && $miss['body'] === "local fallback\n", 'R2 miss did not serve local bytes directly');
	check(!isset($miss['headers']['Location']), 'Local fallback must not redirect or loop');

	$failed = onlineIniResolve('GET', $oldPath, function () {
		throw new RuntimeException('upstream unavailable');
	});
	check($failed['status'] === 200 && $failed['body'] === "local fallback\n", 'R2 failure did not serve local bytes');

	$missingPath = $fixturePrefix . '/release/2024/01/02/board/404.ini';
	$missing = onlineIniResolve('GET', $missingPath, function () {
		return array('status' => 404, 'headers' => array(), 'body' => '');
	});
	check($missing['status'] === 404 && !isset($missing['headers']['Location']), 'Definitive missing key must return 404 without redirect');
	$unavailable = onlineIniResolve('GET', $missingPath, function () {
		throw new RuntimeException('upstream unavailable');
	});
	check($unavailable['status'] === 502 && !isset($unavailable['headers']['Location']), 'Upstream failure without local file must return 502 without redirect');
	$symlinkFallback = onlineIniResolve('GET', $fixturePrefix . '/release/2024/01/02/board/48.ini', function () {
		return array('status' => 404, 'headers' => array(), 'body' => '');
	});
	check($symlinkFallback['status'] === 404, 'Local symlink escaping the handler root was served');

	$head = onlineIniResolve('HEAD', $oldPath, function ($method) {
		check($method === 'HEAD', 'HEAD method must be preserved to R2');
		return array('status' => 404, 'headers' => array(), 'body' => '');
	});
	check($head['status'] === 200 && $head['body'] === '' && $head['headers']['Content-Length'] === (string)strlen("local fallback\n"), 'HEAD fallback headers/body mismatch');

	$called = false;
	$direct2026 = onlineIniResolve('GET', $static2026, function () use (&$called) {
		$called = true;
		throw new RuntimeException('2026 policy bypassed');
	});
	check(!$called && $direct2026['status'] === 200 && $direct2026['body'] === "2026 static bytes\0\n", '2026 key on a discovery/nucleo board must remain local, including direct index requests');

	$olderBoardPaths = array($oldPath, $olderNucleoPath, $olderDiscoverySubstringPath, $olderNucleoSubstringPath, $olderF407DiscoveryPath);
	foreach ($olderBoardPaths as $path) {
		$called = false;
		$r2Response = onlineIniResolve('GET', $path, function ($method, $key) use (&$called, $path) {
			$called = true;
			check($method === 'GET' && $key === $path, 'Older board path did not preserve its R2 key');
			return array('status' => 200, 'headers' => array(), 'body' => 'r2 object for ' . $key);
		});
		check($called && $r2Response['body'] === 'r2 object for ' . $path, 'Every non-2026 board must be R2-first, including discovery/nucleo/f407-discovery');
	}

	$branchYearInName = $fixturePrefix . '-2026/release/2024/01/02/board/47.ini';
	$called = false;
	$branchResponse = onlineIniResolve('GET', $branchYearInName, function () use (&$called) {
		$called = true;
		return array('status' => 200, 'headers' => array(), 'body' => 'old-year R2 bytes');
	});
	check($called && $branchResponse['body'] === 'old-year R2 bytes', 'Year policy must use exact date-tail year, not arbitrary branch text');

	$called = false;
	$invalid = onlineIniResolve('GET', '../config_rusefi.php', function () use (&$called) { $called = true; });
	check(!$called && $invalid['status'] === 400, 'Traversal path reached R2');
	check(onlineIniResolve('POST', $oldPath)['status'] === 405, 'POST was accepted');
	check(onlineIniResolve('GET', array('bad'))['status'] === 400, 'Array path was accepted');

	$redirect = iniTestRedirectPath($oldPath);
	check($redirect['status'] === 302 && $redirect['headers']['Location'] === '/online/ini/' . $oldPath, 'Legacy endpoint did not redirect to canonical URL');
	check(iniTestRedirectPath('https://example.com/x.ini')['status'] === 400, 'Legacy endpoint accepted an external redirect');

	$request = onlineIniSignedRequest('GET', 'rusefi/file.ini', 0, array(
		'endpoint' => 'https://example.r2.cloudflarestorage.com',
		'bucket' => 'test-bucket',
		'access_key' => 'access',
		'secret_key' => 'secret',
	));
	check($request['url'] === 'https://example.r2.cloudflarestorage.com/test-bucket/rusefi/file.ini', 'R2 URL mismatch');
	check(strpos($request['headers'][0], 'Signature=fb2b885202776d8d6bd3ebaa1a67e4a5e1c32524c7ead754018219d1a367f8f2') !== false, 'R2 signature changed unexpectedly');

	echo "production INI handler checks passed\n";
} finally {
	foreach ($fixtures as $fixture) {
		if (is_file($fixture) || is_link($fixture)) {
			unlink($fixture);
		}
	}
	if (isset($outside) && is_file($outside)) {
		unlink($outside);
	}
	$base = __DIR__ . '/../www/online/ini/' . $fixturePrefix;
	foreach (array($base . '/release/2024/01/02/board', $base . '/release/2024/01/02/NUCLEO-F7', $base . '/release/2024/01/02/discoveryfoo', $base . '/release/2024/01/02/myNucleoBoard', $base . '/release/2024/01/02/F407-Discovery', $base . '/release/2026/01/02/NUCLEO-F7', $base . '/release/2024/01/02', $base . '/release/2024/01', $base . '/release/2024', $base . '/release/2026/01/02', $base . '/release/2026/01', $base . '/release/2026', $base . '/release', $base) as $directory) {
		if (is_dir($directory)) {
			rmdir($directory);
		}
	}
}
