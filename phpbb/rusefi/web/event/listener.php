<?php
/**
*
* rusEFI web extension for the phpBB Forum Software package.
*
*/

namespace rusefi\web\event;

/**
* @ignore
*/
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\user */
	protected $user;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\request\request $request,
		\phpbb\template\template $template,
		\phpbb\user $user)
	{
		$this->config = $config;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return array(
			'core.viewtopic_cache_user_data'			=> 'viewtopic_cache_user_data',
//			'core.viewtopic_cache_guest_data'			=> 'viewtopic_cache_guest_data',
			'core.viewtopic_modify_post_row'			=> 'viewtopic_modify_post_row',
			'core.memberlist_view_profile'              => 'profile_vehicles_list',
//			'core.search_get_posts_data'				=> 'search_get_posts_data',
//			'core.search_modify_tpl_ary'				=> 'search_modify_tpl_ary',
//			'core.user_setup'							=> 'user_setup',
		);
	}

	/**
	* Set up the the lang vars
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function user_setup($event)
	{
		// what page are we on?
		$page_name = substr($this->user->page['page_name'], 0, strpos($this->user->page['page_name'], '.'));

		// We only care about memberlist and viewtopic
		if (in_array($page_name, array('viewtopic', 'memberlist', 'search')))
		{
			$lang_set_ext = $event['lang_set_ext'];
			$lang_set_ext[] = array(
				'ext_name' => 'rusefi/web',
				'lang_set' => 'web',
			);
			$this->template->assign_vars(array(
				'S_FLAG' => true,
			));
			$event['lang_set_ext'] = $lang_set_ext;
		}
	}

	/**
	* Update viewtopic user data
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function viewtopic_cache_user_data($event)
	{
	    $array = $event['user_cache_data'];
		$event['user_cache_data'] = $array;
	}

	/**
	* Update viewtopic guest data
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function viewtopic_cache_guest_data($event)
	{
		$array = $event['user_cache_data'];
		$event['user_cache_data'] = $array;
	}
	/**
	* Modify the viewtopic post row
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function viewtopic_modify_post_row($event)
	{
	}

	public function profile_vehicles_list($event)
	{
	}

}
