<?php

namespace rusefi\web\migrations;

class release_data extends \phpbb\db\migration\migration
{

	/**
	 * Populate phpBB's tables with some needed
	 * data for rusEFI Vehicles to work
	 */
	public function update_data()
	{
		return array(

            // parent
			array('module.add', array('ucp', 0, 'RUSEFI_VEHICLES')),

			// Add main_module to the parent module (UCP_PROFILE)
			array('module.add', array(
				'ucp',
				'RUSEFI_VEHICLES',
				array(
					'module_basename' => '\rusefi\web\ucp\main_module',
					'modes'           => array('management', 'overview'),
				),
			)),
		);
	}
}
