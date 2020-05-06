<?php

namespace rusefi\web\ucp;

class main_module
{

	const FORM_KEY = 'rusefi_ucp_management';

	public $u_action;
	public $tpl_name;
	public $page_title;


	/** @var \phpbb\config\config $config */
	protected $config;

	/** @var \phpbb\request\request $request */
	protected $request;

	/** @var \phpbb\template\template $template */
	protected $template;

	/** @var \phpbb\user $user */
	protected $user;

	/** @var \phpbb\language\language $language */
	protected $language;

	/** @var \phpbb\db\driver\factory $db  */
	protected $db;

	/** @var string $phpbb_root_path */
	protected $phpbb_root_path;

	/** @var string $phpbb_container */
	protected $phpbb_container;

	/** @var string $phpEx */
	protected $phpEx;

	/** @var string $phpbb_admin_path */
	protected $phpbb_admin_path;

	protected $utils;

	public function main($id, $mode)
	{
		global $config, $request, $template, $user, $db, $phpbb_container;
		global $phpbb_root_path, $phpEx, $phpbb_admin_path;

		$this->config = $config;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->db = $db;
		$this->phpEx = $phpEx;
		$this->phpbb_container = $phpbb_container;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->phpbb_admin_path = $phpbb_admin_path;

		$this->utils = $this->phpbb_container->get('rusefi.web.utils');

    	$this->language = $this->phpbb_container->get('language');

		switch ($mode)
		{
			case 'overview':
			default:
				$this->tpl_name = 'ucp_rusefi';
				$this->page_title = $this->language->lang('RUSEFI_TOKENS_OVERVIEW');
				$this->mode_overview();
			break;
		}
	}

	/**
	 * Controller for the overview mode
	 */
	private function mode_overview()
	{
		add_form_key(self::FORM_KEY);

		if ($this->request->is_set_post('reset_token')) {

            $status = "Just reset!";

		} else {




		    $status = "Hello stranger";
		}




		$pagination = $this->phpbb_container->get('pagination');
		$pagination->generate_template_pagination($this->u_action, 'pagination', 'start', 0, 99999, 0);

		$this->template->assign_vars(array(
// replace magic keeps system parts of the action
//			'U_ACTION'              => str_replace('mode=overview', 'mode=management', $this->u_action),

			'U_ACTION'              => $this->u_action,
			'U_STATUS'              => $status,
			'RUSEFI_VEHICLE_COUNT'  => 3,
		));
	}


}
