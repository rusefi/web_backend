<?php

define('ONLINE_INI_SKIP_MAIN', true);
require_once __DIR__ . '/../www/online/ini/index.php';

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

$fixturePrefix = 'test-rollback-' . getmypid();
$fixtures = array();
$outside = null;

try {
	$olderPath = $fixturePrefix . '/release/2024/01/02/board/42.ini';
	$newerPath = $fixturePrefix . '/release/2026/01/02/board/43.ini';
	$excludedPath = $fixturePrefix . '/release/2024/01/02/NUCLEO-F7/44.ini';
	$fixtures[] = makeIniFixture($olderPath, "older local bytes\0\n");
	$fixtures[] = makeIniFixture($newerPath, "newer local bytes\n");
	$fixtures[] = makeIniFixture($excludedPath, "excluded local bytes\n");
	$outside = tempnam(sys_get_temp_dir(), 'ini-rollback-outside-');
	file_put_contents($outside, 'outside bytes');
	$symlinkPath = __DIR__ . '/../www/online/ini/' . $fixturePrefix . '/release/2024/01/02/board/48.ini';
	if (!symlink($outside, $symlinkPath)) {
		throw new RuntimeException('Could not create symlink containment fixture');
	}
	$fixtures[] = $symlinkPath;

	foreach (array($olderPath => "older local bytes\0\n", $newerPath => "newer local bytes\n", $excludedPath => "excluded local bytes\n") as $path => $expected) {
		$response = onlineIniResolve('GET', $path);
		check($response['status'] === 200 && $response['body'] === $expected, 'GET did not return exact local bytes');
		check($response['headers']['Content-Length'] === (string)strlen($expected), 'GET content length mismatch');
		$head = onlineIniResolve('HEAD', $path);
		check($head['status'] === 200 && $head['body'] === '', 'HEAD did not return an empty body');
		check($head['headers']['Content-Length'] === (string)strlen($expected), 'HEAD content length mismatch');
	}

	check(onlineIniResolve('GET', $fixturePrefix . '/release/2024/01/02/board/missing.ini')['status'] === 404, 'Missing INI did not return 404');
	check(onlineIniResolve('GET', $fixturePrefix . '/release/2024/01/02/board/48.ini')['status'] === 404, 'Symlink escaping the local INI root was served');
	check(onlineIniResolve('GET', '../config_rusefi.php')['status'] === 400, 'Traversal path was accepted');
	check(onlineIniResolve('POST', $olderPath)['status'] === 405, 'POST was accepted');
	check(onlineIniResolve('GET', array('bad'))['status'] === 400, 'Array path was accepted');

	echo "rollback INI handler checks passed\n";
} finally {
	foreach ($fixtures as $fixture) {
		if (is_file($fixture) || is_link($fixture)) {
			unlink($fixture);
		}
	}
	if ($outside !== null && is_file($outside)) {
		unlink($outside);
	}
	$base = __DIR__ . '/../www/online/ini/' . $fixturePrefix;
	foreach (array(
		$base . '/release/2024/01/02/board',
		$base . '/release/2024/01/02/NUCLEO-F7',
		$base . '/release/2026/01/02/board',
		$base . '/release/2024/01/02',
		$base . '/release/2024/01',
		$base . '/release/2024',
		$base . '/release/2026/01/02',
		$base . '/release/2026/01',
		$base . '/release/2026',
		$base . '/release',
		$base,
	) as $directory) {
		if (is_dir($directory)) {
			rmdir($directory);
		}
	}
}
