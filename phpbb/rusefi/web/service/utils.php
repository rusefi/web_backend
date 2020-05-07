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

	 function gen_uuid() {
            return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                // 32 bits for "time_low"
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

                // 16 bits for "time_mid"
                mt_rand( 0, 0xffff ),

                // 16 bits for "time_hi_and_version",
                // four most significant bits holds version number 4
                mt_rand( 0, 0x0fff ) | 0x4000,

                // 16 bits, 8 bits for "clk_seq_hi_res",
                // 8 bits for "clk_seq_low",
                // two most significant bits holds zero and one for variant DCE1.1
                mt_rand( 0, 0x3fff ) | 0x8000,

                // 48 bits for "node"
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
            );
        }

    function set_token_cookie($uid) {
        // todo: take domain from settings
        setcookie('rusefi_token', $uid, time() + 365 * 24 * 60 * 60, '/', '.rusefi.com', true);
    }

    public function count_engines($user_id) {
	    $sql = 'SELECT count(*) as count
	           	FROM ' . 'msqur_engines' . ' ' . 'WHERE user_id = ' . (int) $user_id;

   		$result = $this->db->sql_query($sql);
   		$data = $this->db->sql_fetchrow();
   		$this->db->sql_freeresult($result);

        return $data['count'];
    }

    public function get_engines($user_id) {
	    $sql = 'SELECT m.file AS id, e.make AS make, e.code AS code FROM msqur_metadata m '.
	    	'JOIN (SELECT id,name,make,code FROM msqur_engines WHERE user_id = ' . (int) $user_id . ') e '.
	    	'WHERE m.engine = e.id GROUP BY e.name ORDER BY m.file DESC';

   		$result = $this->db->sql_query($sql);
		$output = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$output[] = $row;
		}
   		$this->db->sql_freeresult($result);

        return $output;
    }

	public function get_token($user_id)
	{
	    $sql = 'SELECT user_id, token
           	FROM ' . $this->tokens_table . ' ' . 'WHERE user_id = ' . (int) $user_id;

   		$result = $this->db->sql_query($sql);
   		$user = $this->db->sql_fetchrow();
   		$this->db->sql_freeresult($result);

   		if (is_null($user['token'])) {
            $new_token = $this->gen_uuid();
			$sql_ary = array(
			    'user_id'		=> $user_id,
				'created_at'	=> time(),
				'token'	        => $new_token,
			);
			$sql = 'INSERT INTO ' . $this->tokens_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
			$this->db->sql_query($sql);
			$this->set_token_cookie($new_token);
   		    return 'Brand new token: ' .  $new_token;
   		}
   		return 'Existing token: ' . $user['token'];
	}

	public function reset_token($user_id)
	{
	    $new_token = $this->gen_uuid();

	    $sql = 'UPDATE ' . $this->tokens_table . "
        				SET token = '" . $this->db->sql_escape($new_token) . "',
        				created_at = " . time() . "
        				WHERE user_id = '" . $this->db->sql_escape($user_id) . "'";

        $this->db->sql_query($sql);
        $this->set_token_cookie($new_token);

        return 'New token: ' .  $new_token;
	}
}