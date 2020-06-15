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
	    $sql = 'SELECT MAX(m.file) AS id, e.make AS make, e.code AS code FROM msqur_engines e ' .
	    	'INNER JOIN msqur_metadata m ON m.engine = e.id WHERE (user_id = ' . (int) $user_id . ') '.
	    	'GROUP BY e.name';

   		$result = $this->db->sql_query($sql);
		$output = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$output[] = $row;
		}
   		$this->db->sql_freeresult($result);

        return $output;
    }

	public function get_clipboard_icon($token)
	{
/*
oh, I do not like JS :()
	    return ' <svg class="octicon octicon-clippy" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M5.75 1C5.33579 1 5 1.33579 5 1.75V4.75C5 5.16421 5.33579 5.5 5.75 5.5H10.25C10.6642 5.5 11 5.16421 11 4.75V1.75C11 1.33579 10.6642 1 10.25 1H5.75ZM6.5 4V2.5H9.5V4H6.5ZM3.62554 3.533C3.98409 3.32559 4.10661 2.86679 3.8992 2.50825C3.6918 2.1497 3.233 2.02718 2.87446 2.23459C2.35334 2.53604 2 3.10132 2 3.75001V13.25C2 14.2165 2.7835 15 3.75 15H12.25C13.2165 15 14 14.2165 14 13.25V3.75001C14 3.10132 13.6467 2.53604 13.1255 2.23459C12.767 2.02718 12.3082 2.1497 12.1008 2.50825C11.8934 2.86679 12.0159 3.32559 12.3745 3.533C12.4511 3.57735 12.5 3.65842 12.5 3.75001V13.25C12.5 13.3881 12.3881 13.5 12.25 13.5H3.75C3.61193 13.5 3.5 13.3881 3.5 13.25V3.75001C3.5 3.65842 3.54886 3.57735 3.62554 3.533Z"></path></svg>';
*/
        return '';
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
   		    return 'Brand new token: ' .  $new_token . get_clipboard_icon($new_token);
   		}
   		return 'Existing token: ' . $user['token'] . $this->get_clipboard_icon($user['token']);
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

        return 'New token: ' .  $new_token . get_clipboard_icon($new_token);
	}
}