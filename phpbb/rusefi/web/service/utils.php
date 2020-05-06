<?php
/**
 *
 * @package       phpBB Extension - Linked Accounts
 * @copyright (c) 2018 Flerex
 * @license       http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace rusefi\web\service;

class utils
{
   	/** @var \phpbb\user */
   	protected $user;

   	/** @var \phpbb\auth\auth */
   	protected $auth;

   	/** @var \phpbb\config\config $config */
   	protected $config;

   	/** @var \phpbb\db\driver\factory */
   	protected $db;

   	/** @var string */
   	protected $linkedaccounts_table;

	public function __construct(\phpbb\user $user, \phpbb\auth\auth $auth, \phpbb\config\config $config, \phpbb\db\driver\factory $db, $linkedaccounts_table)
	{
		$this->user = $user;
		$this->auth = $auth;
		$this->config = $config;
		$this->db = $db;
		$this->linkedaccounts_table = $linkedaccounts_table;
	}

	public function get_token($key)
	{


	}

	public function reset_token($key)
	{


	}


}