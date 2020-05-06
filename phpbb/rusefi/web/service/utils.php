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
   	protected $tokens_table;

	public function __construct(\phpbb\user $user, \phpbb\auth\auth $auth, \phpbb\config\config $config, \phpbb\db\driver\factory $db, $tokens_table)
	{
		$this->user = $user;
		$this->auth = $auth;
		$this->config = $config;
		$this->db = $db;
		$this->tokens_table = $tokens_table;
	}

	public function get_token($key)
	{
//	    $sql = 'SELECT user_id, token
//           	FROM ' . $this->tokens_table . ' ' . 'WHERE user_id = ' . (int) $key;

//   		$result = $this->db->sql_query($sql);
//   		$user = $this->db->sql_fetchrow();
//   		$this->db->sql_freeresult($result);



	}

	public function reset_token($key)
	{


	}


}