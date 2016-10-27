<?php

require_once 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Enverido Blesta Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.enverido
 * @copyright Copyright (c) 2016 Cogative LTD.
 * @link http://www.Enverido_Reseller.com/ Enverido
 */
class EnveridoReseller extends Module {

    /**
     * Pass a log entry to Blesta's module log
     *
     * @param string $url The URL contacted for this request
     * @param string $data A string of module data sent along with the request (optional)
     * @param string $direction The direction of the log entry (input or output, default input)
     * @param boolean $success True if the request was successful, false otherwise
     * @return string Returns the 8-character group identifier, used to link log entries together
     */

    public function passLogEntry($url, $data, $direction="input", $success) {
        return $this->log($url, $data, $direction, $success);
    }
	
	/**
	 * Initializes the module
	 */
	public function __construct() {
        // Load the language required by this module
		Language::loadLang("enverido_reseller", null, dirname(__FILE__) . DS . "language" . DS);
        
        // Load config
        $this->loadConfig(dirname(__FILE__) . DS . "config.json");

		// Load components required by this module
		Loader::loadComponents($this, array("Input"));
	}
	
	/**
	 * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param array $vars An array of user supplied info to satisfy the request
	 * @return boolean True if the service validates, false otherwise. Sets Input errors when false.
	 */
	public function validateService($package, array $vars=null) {
        $rules = array(
            'enverido_email' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_email.empty", true)
                )
            )
        );

        // Only validate the IP address if the product relies on IP address info
        if($productInfo->lock_ip) {
            $rules['enverido_ip'] = array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_ip.empty", true)
                ),
                'format' => array(
                    'rule' => array('matches', "/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/"),
                    'message' => Language::_("Enverido_Reseller.!error.enverido_ip.format", true)
                )
            );
        }

        // Only validate the domain name if the product relies on domain info
        if($productInfo->lock_domain_name) {
            $rules['enverido_domain'] = array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_domain.empty", true)
                )
            );
        }

		$this->Input->setRules($rules);
		return $this->Input->validates($vars);
	}
	
	/**
	 * Adds the service to the remote server. Sets Input errors on failure,
	 * preventing the service from being added.
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param array $vars An array of user supplied info to satisfy the request
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being added (if the current service is an addon service service and parent service has already been provisioned)
	 * @param string $status The status of the service being added. These include:
	 * 	- active
	 * 	- canceled
	 * 	- pending
	 * 	- suspended
	 * @param array $options A set of options for the service (optional)
	 * @return array A numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function addService($package, array $vars=null, $parent_package=null, $parent_service=null, $status="pending", $options = array()) {
		// Get module row and API
		$module_row = $this->getModuleRow();
		$api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);
        
        // Get fields
        $params = $this->getFieldsFromInput((array)$vars, $package);

		$this->validateService($package, $vars);

        if ($this->Input->errors())
			return;

        // Only provision the service if 'use_module' is true
		if ($vars['use_module'] == "true") {

		    try {
                $licence = $api->generateLicence($package->meta->product, $package->meta->authority, $params['email'], $params['ip'], $params['domain'], $today->getTimestamp());
                if (!isset($licence->short_code)) {
                    $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
                }
            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }
            
            if ($this->Input->errors())
				return;
        }

        // If not provisioned yet - this is designed in cases where the service is pending rather
        // than if the API call failed.
        if(!isset($licence)) {
            // Set a dummy short-code value so that provision won't fail
            $licence = new stdClass();
            $licence->short_code="N/A";
            $licence->id = "-1";
        }
        
		// Return service fields
		return array(
            array(
                'key' => 'enverido_licence_id',
                'value' => $licence->id,
                'encrypted' => 0
            ),
			array(
				'key' => "enverido_ip",
				'value' => $params['ip'],
				'encrypted' => 0
			),
			array(
				'key' => "enverido_domain",
				'value' => $params['domain'],
				'encrypted' => 0
			),
            array(
                'key' => "enverido_email",
                'value' => $params['email'],
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_shortcode",
                'value' => $licence->short_code,
                'encrypted' => 0
            )
        );
	}
	
	/**
	 * Edits the service on the remote server. Sets Input errors on failure,
	 * preventing the service from being edited.
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param array $vars An array of user supplied info to satisfy the request
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being edited (if the current service is an addon service)
	 * @return array A numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function editService($package, $service, array $vars=null, $parent_package=null, $parent_service=null) {
        // Get module row and API
        $module_row = $this->getModuleRow();
        $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);
		
        // Validate the service-specific fields
		$this->validateService($package, $vars);
        
        if ($this->Input->errors())
			return;
        
        // Get the service fields
		$service_fields = $this->serviceFieldsToObject($service->fields);

        // Only provision the service if 'use_module' is true
		if ($vars['use_module'] == "true") {

		    // Check if the fields are relevant, if they are then use their values otherwise use null.
		    $ip = isset($vars['enverido_ip']) ? $vars['enverido_ip'] : null;
            $domain = isset($vars['enverido_domain']) ? $vars['enverido_domain']: null;

            try {
                $r = $api->editLicence($ip, $domain, $vars['enverido_email'], $package->meta->product, $service_fields->enverido_licence_id);
            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }
            if ($this->Input->errors())
				return;
        }

        // Return service fields
        return array(
            array(
                'key' => 'enverido_licence_id',
                'value' => $service_fields->enverido_licence_id,
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_ip",
                'value' => $ip,
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_domain",
                'value' => $domain,
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_email",
                'value' => $vars['enverido_email'],
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_shortcode",
                'value' => $service_fields->enverido_shortcode,
                'encrypted' => 0
            )
        );
	}
	
	/**
	 * Cancels the service on the remote server. Sets Input errors on failure,
	 * preventing the service from being canceled.
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being canceled (if the current service is an addon service)
	 * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function cancelService($package, $service, $parent_package=null, $parent_service=null) {

        if (($row = $this->getModuleRow())) {
            $service_fields = $this->serviceFieldsToObject($service->fields);

            $api = $this->getApi($row->meta->organisation, $row->meta->key);

            try {
                $api->delete_licence($package->meta->product, $service_fields->enverido_licence_id);
            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }

            if ($this->Input->errors())
                return;

        }

		return null;
	}

	/**
	 * Suspends the service on the remote server. Sets Input errors on failure,
	 * preventing the service from being suspended.
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being suspended (if the current service is an addon service)
	 * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function suspendService($package, $service, $parent_package=null, $parent_service=null) {
		// Suspend the service by cancelling it
		//$this->cancelService($package, $service, $parent_package, $parent_service);

        // Suspend the licence

        // Get module row and API
        $module_row = $this->getModuleRow();
        $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);

        $service_fields = $this->serviceFieldsToObject($service->fields);

        try {
            $api->suspendLicence($package->meta->product, $service_fields->enverido_licence_id);
        } catch(\GuzzleHttp\Exception\ClientException $ex) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        }

        if ($this->Input->errors())
            return;

        return null;
    }
	
	/**
	 * Unsuspends the service on the remote server. Sets Input errors on failure,
	 * preventing the service from being unsuspended.
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being unsuspended (if the current service is an addon service)
	 * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function unsuspendService($package, $service, $parent_package=null, $parent_service=null) {
        // Get module row and API
        $module_row = $this->getModuleRow();
        $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);

        // Convert to stdClass
        $service_fields = $this->serviceFieldsToObject($service->fields);

        try {
            $api->unsuspendLicence($package->meta->product, $service_fields->enverido_licence_id);
        } catch(\GuzzleHttp\Exception\ClientException $ex) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        }

        if ($this->Input->errors())
            return;

        return null;
	}
	
	/**
	 * Allows the module to perform an action when the service is ready to renew.
	 * Sets Input errors on failure, preventing the service from renewing.
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being renewed (if the current service is an addon service)
	 * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function renewService($package, $service, $parent_package=null, $parent_service=null) {

        $service_fields = $this->serviceFieldsToObject($service->fields);

        $module_row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);

        // Generate an expiry date
        // Get term (eg: 1, 2, 5, 10) This will be attached to the period (days, months, years, etc)

        // Blesta passes all pricing terms data so we need to work out which one the user picked
        $term = null; // eg 15
        $period = null; // eg days

        foreach($package->pricing as $pricing) {
            // If the user picked this pricing option then set our term and period variables
            if($pricing->id == $service->pricing_id) {
                $term = $pricing->term;
                $period = $pricing->period;
            }
        }

        $today = new DateTime();

        // Here we add the expected amount of time between today and the licence expiration
        switch($period) {
            case 'onetime':
                // Expiration date 100 years in the future
                $today->add(new DateInterval('P100Y'));
                break;
            case 'year':
                $today->add(new DateInterval('P'.$term.'Y'));
                break;
            case 'month':
                $today->add(new DateInterval('P'.$term.'M'));
                break;
            case 'week':
                $today->add(new DateInterval('P'.$term.'W'));
                break;
            case 'day':
                $today->add(new DateInterval('P'.$term.'D'));
                break;
        }

        try {
            $api->renew_licence($package->meta->product, $service_fields->enverido_licence_id, $today->getTimestamp());
        } catch(\GuzzleHttp\Exception\ClientException $ex) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        }

        if ($this->Input->errors())
            return;

        // Array
        $toReturn = array(
            array(
                'key' => 'enverido_email',
                'value' => $service_fields->enverido_email,
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_licence_id',
                'value' => $service_fields->enverido_licence_id,
                'encrypted' => 0
            )
        );

        if(property_exists($service_fields, 'enverido_domain')) {
            $toReturn[] = array(
                'key' => 'enverido_domain',
                'value' => $service_fields->enverido_domain,
                'encrypted' => 0
            );
        }

        if(property_exists($service_fields, 'enverido_ip')) {
            $toReturn[] = array(
                'key' => 'enverido_ip',
                'value' => $service_fields->enverido_ip,
                'encrypted' => 0
            );
        }
        
		return $toReturn;
	}
	
	/**
	 * Updates the package for the service on the remote server. Sets Input
	 * errors on failure, preventing the service's package from being changed.
	 *
	 * @param stdClass $package_from A stdClass object representing the current package
	 * @param stdClass $package_to A stdClass object representing the new package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
	 * @param stdClass $parent_service A stdClass object representing the parent service of the service being changed (if the current service is an addon service)
	 * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function changeServicePackage($package_from, $package_to, $service, $parent_package=null, $parent_service=null) {
		// Nothing to do
		return null;
	}
	
	/**
	 * Validates input data when attempting to add a package, returns the meta
	 * data to save when adding a package. Performs any action required to add
	 * the package on the remote server. Sets Input errors on failure,
	 * preventing the package from being added.
	 *
	 * @param array An array of key/value pairs used to add the package
	 * @return array A numerically indexed array of meta fields to be stored for this package containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function addPackage(array $vars=null) {
        // Set rules to validate input data
		//$this->Input->setRules($this->getPackageRules($vars));
		
		// Build meta data to return
		$meta = array();

        // Return all package meta fields
        foreach ($vars['meta'] as $key => $value) {
            $meta[] = array(
                'key' => $key,
                'value' => $value,
                'encrypted' => 0
            );
        }
		return $meta;
	}
	
	/**
	 * Validates input data when attempting to edit a package, returns the meta
	 * data to save when editing a package. Performs any action required to edit
	 * the package on the remote server. Sets Input errors on failure,
	 * preventing the package from being edited.
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param array An array of key/value pairs used to edit the package
	 * @return array A numerically indexed array of meta fields to be stored for this package containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function editPackage($package, array $vars=null) {
        // Same as adding a package
		return $this->addPackage($vars);
	}
	
	/**
	 * Deletes the package on the remote server. Sets Input errors on failure,
	 * preventing the package from being deleted.
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @see Module::getModule()
	 * @see Module::getModuleRow()
	 */
	public function deletePackage($package) {
		// Nothing to do
		return null;
	}

    public function deleteModuleRow($module_row) {
       return null;
    }
	
	/**
	 * Returns the rendered view of the manage module page
	 *
	 * @param mixed $module A stdClass object representing the module and its rows
	 * @param array $vars An array of post data submitted to or on the manage module page (used to repopulate fields after an error)
	 * @return string HTML content containing information to display when viewing the manager module page
	 */
	public function manageModule($module, array &$vars) {
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("manage", "default");
		$this->view->base_uri = $this->base_uri;
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html", "Widget"));
		
		$this->view->set("module", $module);
		
		return $this->view->fetch();
	}
	
	/**
	 * Returns the rendered view of the add module row page
	 *
	 * @param array $vars An array of post data submitted to or on the add module row page (used to repopulate fields after an error)
	 * @return string HTML content containing information to display when viewing the add module row page
	 */
	public function manageAddRow(array &$vars) {
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("add_row", "default");
		$this->view->base_uri = $this->base_uri;
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html", "Widget"));
		
		$this->view->set("vars", (object)$vars);
		return $this->view->fetch();	
	}
	
	/**
	 * Returns the rendered view of the edit module row page
	 *
	 * @param stdClass $module_row The stdClass representation of the existing module row
	 * @param array $vars An array of post data submitted to or on the edit module row page (used to repopulate fields after an error)
	 * @return string HTML content containing information to display when viewing the edit module row page
	 */	
	public function manageEditRow($module_row, array &$vars) {
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("edit_row", "default");
		$this->view->base_uri = $this->base_uri;
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html", "Widget"));
		
		if (empty($vars))
			$vars = $module_row->meta;
		
		$this->view->set("vars", (object)$vars);
		return $this->view->fetch();
	}
	
	/**
	 * Adds the module row on the remote server. Sets Input errors on failure,
	 * preventing the row from being added.
	 *
	 * @param array $vars An array of module info to add
	 * @return array A numerically indexed array of meta fields for the module row containing:
	 * 	- key The key for this meta field
	 * 	- value The value for this key
	 * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
	 */
	public function addModuleRow(array &$vars) {
		$meta_fields = array("email", "organisation", "key");
		$encrypted_fields = array("key");

		$this->Input->setRules($this->getRowRules($vars));
		
		// Validate module row
		if ($this->Input->validates($vars)) {

			// Build the meta data for this row
			$meta = array();
			foreach ($vars as $key => $value) {
				
				if (in_array($key, $meta_fields)) {
					$meta[] = array(
						'key' => $key,
						'value' => $value,
						'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
					);
				}
			}
			
			return $meta;
		}
	}
	
	/**
	 * Returns an array of available service delegation order methods. The module
	 * will determine how each method is defined. For example, the method "first"
	 * may be implemented such that it returns the module row with the least number
	 * of services assigned to it.
	 *
	 * @return array An array of order methods in key/value pairs where the key is the type to be stored for the group and value is the name for that option
	 * @see Module::selectModuleRow()
	 */
	public function getGroupOrderOptions() {
		return array('first'=>Language::_("Enverido_Reseller.order_options.first", true));
	}
	
	/**
	 * Determines which module row should be attempted when a service is provisioned
	 * for the given group based upon the order method set for that group.
	 *
	 * @return int The module row ID to attempt to add the service with
	 * @see Module::getGroupOrderOptions()
	 */
	public function selectModuleRow($module_group_id) {
		if (!isset($this->ModuleManager))
			Loader::loadModels($this, array("ModuleManager"));
		
		$group = $this->ModuleManager->getGroup($module_group_id);
		
		if ($group) {
			switch ($group->add_order) {
				default:
				case "first":
					
					foreach ($group->rows as $row) {
						return $row->id;
					}
					
					break;
			}
		}
		return 0;
	}
	
	/**
	 * Returns all fields used when adding/editing a package, including any
	 * javascript to execute when the page is rendered with these fields.
	 *
	 * @param $vars stdClass A stdClass object representing a set of post fields
	 * @return ModuleFields A ModuleFields object, containing the fields to render as well as any additional HTML markup to include
	 */
	public function getPackageFields($vars=null) {
		Loader::loadHelpers($this, array("Html"));
		
		$fields = new ModuleFields();

        // Set the current module row to be null
        $module_row = null;

        // If a module row has been sent by the form, then use that one
        if(isset($vars->module_row) && $vars->module_row > 0) {
            // This will happen when the drop-down with the module rows is changed
            // an AJAX request reloads the module options section.
            $module_row = $vars->module_row;
        } else {
            // Otherwise get an array of all the rows and use the first one listed
            // This will happen on initial page load
            $rows = $this->getModuleRows();
            $module_row = $rows[0]->id;
        }

        // Get an API instance from the module row's organisation and API key
        $api = $this->getApi($this->getModuleRow($module_row)->meta->organisation, $this->getModuleRow($module_row)->meta->key);

        // Get a list of plans from the API
        $plans = array();

        try {
            /**
             * @var stdClass $plan
             */
            foreach(\Enverido\API\Reselling\SubscriptionPlan::all($api) as $plan) {
                $plans[$plan->id] = $plan->label;
            }
        } catch(\GuzzleHttp\Exception\ClientException $ex) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        }

        if ($this->Input->errors())
            return;

        // Set the package's available options
		$plan = $fields->label(Language::_("Enverido_Reseller.package_fields.plan", true), "plan");
		$plan->attach($fields->fieldSelect("meta[plan]", $plans,
			$this->Html->ifSet($vars->meta['plan']), array('id'=>"plan")));
		$fields->setField($plan);

		return $fields;
	}

    /**
     * Get product information from the Enverido API based on the package currently selected. This information
     * can then be used to change the options available to the user
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return stdClass Product information from the API
     * @see https://docs.cogative.com/pages/viewpage.action?pageId=1409436#id-/{PRODUCT-ID}-GET
     */

	private function getProductInformationFromPackage($package) {
        $module_row = $this->getModuleRow($package->module_row);

        $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);

        try {
            return $api->getProduct($package->meta->product);
        } catch(\GuzzleHttp\Exception\ClientException $ex) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        }

        if ($this->Input->errors())
            return;
    }
	
	/**
	 * Returns an array of key values for fields stored for a module, package,
	 * and service under this module, used to substitute those keys with their
	 * actual module, package, or service meta values in related emails.
	 *
	 * @return array A multi-dimensional array of key/value pairs where each key is one of 'module', 'package', or 'service' and each value is a numerically indexed array of key values that match meta fields under that category.
	 * @see Modules::addModuleRow()
	 * @see Modules::editModuleRow()
	 * @see Modules::addPackage()
	 * @see Modules::editPackage()
	 * @see Modules::addService()
	 * @see Modules::editService()
	 */
	public function getEmailTags() {
		return array(
			'module' => array(),
			'package' => array(),
			'service' => array("enverido_domain", "enverido_ip", "enverido_email", "enverido_shortcode")
		);
	}
	
	/**
	 * Returns all fields to display to an admin attempting to add a service with the module
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param $vars stdClass A stdClass object representing a set of post fields
	 * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
	 */
	public function getAdminAddFields($package, $vars=null) {
		Loader::loadHelpers($this, array("Html"));
		
		$fields = new ModuleFields();

        // Account holder's name
        $name = $fields->label(Language::_("Enverido_Reseller.service_fields.name", true), "enverido_reseller_name");
        $name->attach($fields->fieldText("enverido_reseller_name", $this->Html->ifSet($vars->enverido_reseller_name, $this->Html->ifSet($vars->name)), array('id' => "enverido_reseller_name")));
        // Add tooltip
        $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.name", true));
        $name->attach($tooltip);
        $fields->setField($name);

        // Account's Email Address
        $email = $fields->label(Language::_("Enverido_Reseller.service_fields.email", true), "enverido_reseller_email");
        $email->attach($fields->fieldText("enverido_reseller_email", $this->Html->ifSet($vars->enverido_reseller_email, $this->Html->ifSet($vars->email)), array('id' => "enverido_reseller_email")));
        // Add tooltip
        $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.email", true));
        $email->attach($tooltip);
        $fields->setField($email);

        // Account's organisation
        $organisation = $fields->label(Language::_("Enverido_Reseller.service_fields.organisation", true), "enverido_reseller_organisation");
        $organisation->attach($fields->fieldText("enverido_reseller_organisation", $this->Html->ifSet($vars->enverido_reseller_organisation, $this->Html->ifSet($vars->organisation)), array('id' => "enverido_reseller_organisation")));
        // Add tooltip
        $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.organisation", true));
        $organisation->attach($tooltip);
        $fields->setField($organisation);

        // Account's password
        $password = $fields->label(Language::_("Enverido_Reseller.service_fields.password", true), "enverido_reseller_password");
        $password->attach($fields->fieldPassword("enverido_reseller_password", array('id' => "enverido_reseller_password")));
        $fields->setField($password);

        // Receive news?
        $news = $fields->label(Language::_("Enverido_Reseller.service_fields.news", true), "enverido_reseller_news");
        $news->attach($fields->fieldCheckbox("enverido_reseller_news", null, $this->Html->ifSet($vars->enverido_reseller_news, $this->Html->ifSet($vars->news)), array('id' => 'enverido_reseller_news')));
        $fields->setField($news);

        // ToS agreement?
        $tos = $fields->label(Language::_("Enverido_Reseller.service_fields.tos", true), "enverido_reseller_tos");
        $tos->attach($fields->fieldCheckbox("enverido_reseller_tos", null, $this->Html->ifSet($vars->enverido_reseller_tos, $this->Html->ifSet($vars->tos)), array('id' => 'enverido_reseller_tos')));
        $fields->setField($tos);
		return $fields;
	}
	
	/**
	 * Returns all fields to display to a client attempting to add a service with the module
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param $vars stdClass A stdClass object representing a set of post fields
	 * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
	 */	
	public function getClientAddFields($package, $vars=null) {
		// Same as admin fields
        return $this->getAdminAddFields($package, $vars);
	}
	
	/**
	 * Returns all fields to display to an admin attempting to edit a service with the module
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @param $vars stdClass A stdClass object representing a set of post fields
	 * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
	 */	
	public function getAdminEditFields($package, $vars=null) {
		Loader::loadHelpers($this, array("Html"));

		$fields = new ModuleFields();

        $product = $this->getProductInformationFromPackage($package);

        if($product == null) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
        } else {

            // Licensee Email Address
            $email = $fields->label(Language::_("Enverido_Reseller.service_fields.email", true), "enverido_email");
            $email->attach($fields->fieldText("enverido_email", $this->Html->ifSet($vars->enverido_email, $this->Html->ifSet($vars->email)), array('id' => "enverido_email")));
            // Add tooltip
            $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.email", true));
            $email->attach($tooltip);
            $fields->setField($email);

            if ($product->lock_domain_name) {
                // Domain name
                $domain = $fields->label(Language::_("Enverido_Reseller.service_fields.domain", true), "enverido_domain");
                $domain->attach($fields->fieldText("enverido_domain", $this->Html->ifSet($vars->enverido_domain, $this->Html->ifSet($vars->domain)), array('id' => "enverido_domain")));
                // Add tooltip
                $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.domain", true));
                $domain->attach($tooltip);
                $fields->setField($domain);
            }

            if ($product->lock_ip) {
                // Set the IP address as selectable options
                $ip = $fields->label(Language::_("Enverido_Reseller.service_fields.ipaddress", true), "enverido_ip");
                $ip->attach($fields->fieldText("enverido_ip", $this->Html->ifSet($vars->enverido_ip), array('id' => "enverido_ip")));
                // Add tooltip
                $tooltip = $fields->tooltip(Language::_("Enverido_Reseller.service_field.tooltip.ipaddress", true));
                $ip->attach($tooltip);
                $fields->setField($ip);
            }
        }

        return $fields;
	}
	
	/**
	 * Fetches the HTML content to display when viewing the service info in the
	 * admin interface.
	 *
	 * @param stdClass $service A stdClass object representing the service
	 * @param stdClass $package A stdClass object representing the service's package
	 * @return string HTML content containing information to display when viewing the service info
	 */
	public function getAdminServiceInfo($service, $package) {
		$row = $this->getModuleRow();
		
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("admin_service_info", "default");
		$this->view->base_uri = $this->base_uri;
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html"));
		
		$this->view->set("module_row", $row);
		$this->view->set("package", $package);
		$this->view->set("service", $service);
		$this->view->set("service_fields", $this->serviceFieldsToObject($service->fields));

		return $this->view->fetch();
	}
	
	/**
	 * Fetches the HTML content to display when viewing the service info in the
	 * client interface.
	 *
	 * @param stdClass $service A stdClass object representing the service
	 * @param stdClass $package A stdClass object representing the service's package
	 * @return string HTML content containing information to display when viewing the service info
	 */
	public function getClientServiceInfo($service, $package) {
		$row = $this->getModuleRow();
		
		// Load the view into this object, so helpers can be automatically added to the view
		$this->view = new View("client_service_info", "default");
		$this->view->base_uri = $this->base_uri;
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html"));

		$this->view->set("module_row", $row);
		$this->view->set("package", $package);
		$this->view->set("service", $service);
		$this->view->set("service_fields", $this->serviceFieldsToObject($service->fields));

		return $this->view->fetch();
	}

    /**
	 * Returns all tabs to display to a client when managing a service whose
	 * package uses this module
	 *
	 * @param stdClass $package A stdClass object representing the selected package
	 * @return array An array of tabs in the format of method => title. Example: array('methodName' => "Title", 'methodName2' => "Title2")
	 */
	public function getClientTabs($package) {
		return array(
            'tabReissueLicence' => array('name' => Language::_("Enverido_Reseller.tab_reissue_licence", true), 'icon' => "fa fa-refresh")
		);
	}

    /**
	 * Tab to allow clients to update their IP address for the license
	 *
	 * @param stdClass $package A stdClass object representing the current package
	 * @param stdClass $service A stdClass object representing the current service
	 * @param array $get Any GET parameters
	 * @param array $post Any POST parameters
	 * @param array $files Any FILES parameters
	 * @return string The string representing the contents of this tab
	 */
	public function tabReissueLicence($package, $service, array $get=null, array $post=null, array $files=null) {
        $this->view = new View("tab_reissue_licence", "default");
		$this->view->base_uri = $this->base_uri;
		// Load the helpers required for this view
		Loader::loadHelpers($this, array("Form", "Html"));

        // Fetch the service fields
        $service_fields = $this->serviceFieldsToObject($service->fields);

        $vars = array(
            'enverido_email' => $service_fields->enverido_email,
            'enverido_domain' => $service_fields->enverido_domain,
            'enverido_ip' => $service_fields->enverido_ip
        );
        
        if (!empty($post)) {
            // Get module row and API
            $module_row = $this->getModuleRow();
            $api = $this->getApi($module_row->meta->organisation, $module_row->meta->key);

            $vars = array(
                'enverido_email' => (isset($post['enverido_email']) ? $post['enverido_email'] : $service_fields->enverido_email),
                'enverido_domain' => (isset($post['enverido_domain']) ? $post['enverido_domain'] : null),
                'enverido_ip' => (isset($post['enverido_ip']) ? $post['enverido_ip'] : null),
            );

            try {
                $api->reissue_licence($package->meta->product, $service_fields->enverido_licence_id);
                $api->editLicence($vars['enverido_ip'], $vars['enverido_domain'], $vars['enverido_email'], $package->meta->product, $service_fields->enverido_licence_id);
            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }

            if ($this->Input->errors())
                return;

            // Update the service IP address
            Loader::loadModels($this, array("Services"));
            $this->Services->edit($service->id, $vars);

            if ($this->Services->errors())
                $this->Input->setErrors($this->Services->errors());
        }

        $this->view->set("vars", $vars);
        $this->view->set("service_fields", $service_fields);
		$this->view->set("service_id", $service->id);

		$this->view->set("view", $this->view->view);
		$this->view->setDefaultView("components" . DS . "modules" . DS . "enverido_reseller" . DS);
		return $this->view->fetch();
    }

    /**
	 * Returns an array of service fields to set for the service using the given input
	 *
	 * @param array $vars An array of key/value input pairs
	 * @param stdClass $package A stdClass object representing the package for the service
	 * @return array An array of key/value pairs representing service fields
	 */
	private function getFieldsFromInput(array $vars, $package) {
		$fields = array(
            'ip' => isset($vars['enverido_ip']) ? $vars['enverido_ip']: null,
			'domain' => isset($vars['enverido_domain']) ? $vars['enverido_domain'] : null,
            'email' => isset($vars['enverido_email']) ? $vars['enverido_email'] : null
		);

		return $fields;
	}

    /**
     * Initializes the Enverido Api and returns an instance of that object with the given account information set
     *
     * @param string $email The account email address
     * @param string $key The API Key
     *
     * @return \Enverido\API\Api
     */
	private function getApi($organisation, $key) {
	    // TODO change from staging server to live server when not testing. Or, even better, add a test mode!
        return new \Enverido\API\Api($organisation, $key, 'staging.enverido.com', false);
	}

	/**
	 * Builds and returns the rules required to add/edit a module row (e.g. server)
	 *
	 * @param array $vars An array of key/value data pairs
	 * @return array An array of Input rules suitable for Input::setRules()
	 */
	private function getRowRules(&$vars) {
		return array(
			'email' => array(
                'empty' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("Enverido_Reseller.!error.email.valid", true)
                )
            ),
            'organisation' => array(
                'empty' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("Enverido_Reseller.!error.organisation.valid", true)
                )
            ),
            'key' => array(
                'empty' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("Enverido_Reseller.!error.key.empty", true)
                )
            )
		);
	}
}
?>