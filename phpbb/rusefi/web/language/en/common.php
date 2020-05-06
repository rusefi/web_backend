<?php

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(

	'RUSEFI_VEHICLES'                       => 'Your Vehicles',
	'RUSEFI_TOKENS_OVERVIEW'                => 'Access Token',
	'RUSEFI_RESET_TOKEN'                    => 'Reset Token',


));
