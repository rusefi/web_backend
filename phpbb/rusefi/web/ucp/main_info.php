<?php

namespace rusefi\web\ucp;

// Required by EPV
if (!defined('IN_PHPBB'))
{
	exit;
}

class main_info
{
	public function module()
	{
		return array(
			'filename' => '\rusefi\web\ucp\main_module',
			'title'    => 'RE_TOKENS',
			'modes'    => array(
				'overview'   => array(
					'title' => 'RUSEFI_TOKEN',
				),
			),
		);
	}
}
