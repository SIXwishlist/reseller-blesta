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
            'enverido_reseller_email' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_email.empty", true)
                )
            ),
            'enverido_reseller_name' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_name.empty", true)
                )
            ),
            'enverido_reseller_organisation' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_organisation.empty", true)
                ),
                'format' => array(
                    'rule' => array('matches', '/^\S*$/'),
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_organisation.whitespace", true)
                )
            ),
            'enverido_reseller_password' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_password.empty", true)
                )
            ),
            'enverido_reseller_tos' => array(
                'empty' => array(
                    'rule' => 'isEmpty',
                    'negate' => 'true',
                    'message' => Language::_("Enverido_Reseller.!error.enverido_reseller_tos.empty", true)
                )
            )
        );

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

        $user = new \Enverido\API\Reselling\User(null, $api);
        $user->setSubscriptionPlanId($package->meta->plan);
        $user->setName($params['name']);
        $user->setOrganisation($params['organisation']);
        $user->setEmail($params['email']);
        $user->setPassword($params['password']);
        $user->setReceiveNews($params['news']);

        // Only provision the service if 'use_module' is true
		if ($vars['use_module'] == "true") {

		    try {

		        // Require agreement to the ToS before an account is created
		        if($params['tos']) {
                    $user->create();

                    if ($user->getId() == null) {
                        $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
                    }

                } else {
                    $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.enverido_reseller_tos.empty", true))));
                }

            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }
            
            if ($this->Input->errors())
				return;
        }

        // If not provisioned yet - this is designed in cases where the service is pending rather
        // than if the API call failed.
        if(!isset($user)) {
            // Set a dummy short-code value so that provision won't fail
            $licence = new stdClass();
            $licence->short_code="N/A";
            $licence->id = "-1";
        }
        
		// Return service fields
		return array(
		    array(
		        'key' => 'enverido_reseller_id',
                'value' => $user->getId(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_email',
                'value' => $user->getEmail(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_name',
                'value' => $user->getName(),
                'encrypted' => 0
            ),
			array(
				'key' => "enverido_reseller_organisation",
				'value' => $user->getOrganisation(),
				'encrypted' => 0
			),
            array(
                'key' => 'enverido_reseller_news',
                'value' => $user->getReceiveNews(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_tos',
                'value' => true,
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_password',
                'value' => $params['password'],
                'encrypted' => 1
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
        
        // Get the existing service fields
		$service_fields = $this->serviceFieldsToObject($service->fields);

        // Get a nicer format of the new fields
        $new_fields = $this->getFieldsFromInput($vars, $package);

        // Only provision the service if 'use_module' is true
		if ($vars['use_module'] == "true") {

		    // Check if the fields are relevant, if they are then use their values otherwise use null.
		    $ip = isset($vars['enverido_ip']) ? $vars['enverido_ip'] : null;
            $domain = isset($vars['enverido_domain']) ? $vars['enverido_domain']: null;

            try {
                $user = new \Enverido\API\Reselling\User($service_fields->enverido_reseller_id, $api);
                // These values are prepended with enverido_reseller because thati s the name of the module,
                // not because they relate to the actual reseller. Eg: enverido_reseller_name is still the name
                // of the resold user.
                $user->setName($new_fields['name']);
                $user->setOrganisation($new_fields['organisation']);
                $user->setReceiveNews($new_fields['news']);
                $user->setEmail($new_fields['email']);
                $user->update();
            } catch(\GuzzleHttp\Exception\ClientException $ex) {
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
            }
            if ($this->Input->errors())
				return;
        }

        // Return service fields
        return array(
            array(
                'key' => 'enverido_reseller_id',
                'value' => $user->getId(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_email',
                'value' => $user->getEmail(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_name',
                'value' => $user->getName(),
                'encrypted' => 0
            ),
            array(
                'key' => "enverido_reseller_organisation",
                'value' => $user->getOrganisation(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_news',
                'value' => $user->getReceiveNews(),
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_tos',
                'value' => true,
                'encrypted' => 0
            ),
            array(
                'key' => 'enverido_reseller_password',
                'value' => $service_fields->enverido_reseller_password,
                'encrypted' => 1
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
                // ->enverido_reseller_id is ID of resold account, not reseller. enverido_reseller is prepended
                // as the module name only.
                $user = new \Enverido\API\Reselling\User($service_fields->enverido_reseller_id,  $api);
                $user->cancel();
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
            $user = new \Enverido\API\Reselling\User($service_fields->enverido_reseller_id, $api);
            $user->cancel();
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
            $user = new \Enverido\API\Reselling\User($service_fields->enverido_reseller_id, $api);
            $user->subscribe($package->meta->plan);
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
	    // nothing to do
        return null;
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
		// Update subscription
        $from_module_row = $this->getModuleRow($package_from->module_row);
        $to_module_row = $this->getModuleRow($package_to->module_row);

        // Can't move resold accounts between reseller accounts
        if($from_module_row->meta->email != $to_module_row->meta->email) {
            $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.accountmismatch", true))));
        }

        if($this->Input->errors()) {
            return;
        }

        // Only change on server end if the packages have different plans
        if($package_from->meta->plan != $package_to->meta->plan) {

            // Setup API
            $fields = $this->serviceFieldsToObject($service->fields);

            $api = $this->getApi($to_module_row->meta->organisation, $to_module_row->meta->key);

            //enverido_reseller_id isn't the ID of the reseller, it's the ID of the resold user.
            // enverido_reseller is prepended only because this is the name of the Blesta module
            try {
                $user = new \Enverido\API\Reselling\User($fields->enverido_reseller_id, $api);
                $user->subscribe($package_to->meta->plan);
            } catch (\GuzzleHttp\Exception\ClientException $ex) {
                // Errors
                $this->Input->setErrors(array('api' => array('internal' => Language::_("Enverido_Reseller.!error.api.internal", true))));
                return;
            }
        }

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
			'service' => array("enverido_reseller_email", "enverido_reseller_name", "enverido_reseller_organisation")
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
        $password->attach($fields->fieldPassword("enverido_reseller_password", array('id' => "enverido_reseller_password", 'value' => $this->Html->ifSet($vars->enverido_reseller_password))));
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
        return $this->getAdminAddFields($package, $vars);
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
	 * Returns an array of service fields to set for the service using the given input
	 *
	 * @param array $vars An array of key/value input pairs
	 * @param stdClass $package A stdClass object representing the package for the service
	 * @return array An array of key/value pairs representing service fields
	 */
	private function getFieldsFromInput(array $vars, $package) {
		$fields = array(
            'name' => isset($vars['enverido_reseller_name']) ? $vars['enverido_reseller_name']: null,
			'email' => isset($vars['enverido_reseller_email']) ? $vars['enverido_reseller_email'] : null,
            'organisation' => isset($vars['enverido_reseller_organisation']) ? $vars['enverido_reseller_organisation'] : null,
            'password' => isset($vars['enverido_reseller_password']) ? $vars['enverido_reseller_password']: null,
            'tos' => isset($vars['enverido_reseller_tos']),
            'news' => isset($vars['enverido_reseller_news'])
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
        return new \Enverido\API\Api($organisation, $key);
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