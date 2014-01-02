<?php

require_once 'sync.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function sync_civicrm_config(&$config) {
  _sync_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function sync_civicrm_xmlMenu(&$files) {
  _sync_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function sync_civicrm_install() {
  return _sync_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function sync_civicrm_uninstall() {
  return _sync_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function sync_civicrm_enable() {
    /**
     * Check if required extensions org.civicoop.dgw.custom and
     * org.civicoop.dgw.api are installed and enabled
     */
    $reqExtApi = false;
    $reqExtCustom = false;
    $daoExtension = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_extension");
    while ($daoExtension->fetch()) {
        if (isset($daoExtension->full_name)) {
            if ($daoExtension->full_name == "org.civioop.dgw.custom") {
                if (isset($daoExtension->is_active)) {
                    if ($daoExtension->is_active == 1) {
                        $reqExtCustom = true;
                    }
                }
            }
            if ($daoExtension->full_name == "org.civioop.dgw.api") {
                if (isset($daoExtension->is_active)) {
                    if ($daoExtension->is_active == 1) {
                        $reqExtApi = true;
                    }
                }
            }
        }
    }
    if (!$reqExtApi) {
        CRM_Core_Session::setStatus("Can not enable extension org.civicoop.dgw.sync
            because required extension org.civicoop.dgw.api is not enabled", 'Extension not enabled', 'alert');
        return;
    }
    if ( !$reqExtCustom ) {
        CRM_Core_Session::setStatus("Can not enable extension org.civicoop.dgw.sync
            because required extension org.civicoop.dgw.custom is not enabled", 'Extension not enabled', 'alert');
        return;
    }
    /**
     * check if dgw_config table is present, which is required
     */
    $dgwConfigExists = CRM_Core_DAO::checkTableExists('dgw_config');
    if ($dgwConfigExists) {
        require_once 'CRM/Utils/DgwUtils.php';
        $syncTableTitle = CRM_Utils_DgwUtils::getDgwConfigValue('synchronisatietabel first');
        $syncTable = CRM_Utils_DgwUtils::getCustomGroupTableName($syncTableTitle);
        $syncTableExists = CRM_Core_DAO::checkTableExists($syncTable);
        /**
         * if sync table exists, clean up might be required before
         * module sync is enabled
         */
        if ($syncTableExists) {
            _cleanSyncTable($syncTable);
        } else {
            /**
             * sync table needs to be created
             */
            $customGroupParams = array(
                'version'   =>  3,
                'title'     =>  $syncTableTitle,
                'extends'   =>  'Individual',
                'is_active' =>  0
                );
            $resultCustomGroup = civicrm_api('CustomGroup', 'Create', $customGroupParams);
            if ($resultCustomGroup['is_error'] == 0) {
                $customGroupId = $resultCustomGroup['id'];
                /**
                 * create custom fields
                 */
                $customFieldParams = array(
                    'version'           =>  3,
                    'is_active'         =>  0,
                    'custom_group_id'   =>  $customGroupId
                    );
                $syncFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('sync first action veld');
                $customFieldParams['label'] = $syncFieldLabel;
                $customFieldParams['data_type'] = 'string';
                civicrm_api('CustomField', 'Create', $customFieldParams);

                $syncFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('sync first entity veld');
                $customFieldParams['label'] = $syncFieldLabel;
                $customFieldParams['data_type'] = 'string';
                civicrm_api('CustomField', 'Create', $customFieldParams);

                $syncFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('sync first entity_id veld');
                $customFieldParams['label'] = $syncFieldLabel;
                $customFieldParams['data_type'] = 'int';
                civicrm_api('CustomField', 'Create', $customFieldParams);

                $syncFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('sync first key_first veld');
                $customFieldParams['label'] = $syncFieldLabel;
                $customFieldParams['data_type'] = 'string';
                civicrm_api('CustomField', 'Create', $customFieldParams);

                $syncFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('sync first change_date veld');
                $customFieldParams['label'] = $syncFieldLabel;
                $customFieldParams['data_type'] = 'date';
                civicrm_api('CustomField', 'Create', $customFieldParams);
            }
        }
    } else {
        /**
         * send status message required table and abort enable
         */
        CRM_Core_Session::setStatus("Can not enable extension org.civicoop.dgw.sync
            because dgw_config table does not exit", 'Extension not enabled', 'alert');
        return;
    }
  return _sync_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function sync_civicrm_disable() {
  return _sync_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function sync_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _sync_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function sync_civicrm_managed(&$entities) {
  return _sync_civix_civicrm_managed($entities);
}
/**
 * Implementation of hook_civicrm_pre
 *
 * @author Erik Hommel (erik.hommel@civicoop.org)
 *
 * - check for changes to individual or organization that requires
 *   synchronization work.
 *
 */
function sync_civicrm_pre( $op, $objectName, $objectId, &$objectRef ) {
    /**
     * only for some objects
     */
    $syncedObjects = array("Individual", "Organization", "Address", "Email", "Phone");
    /**
     * only if op is not create (handled in sync_civicrm_post)
     */
    if ($op != "create") {
        /**
         * only if one of selected objects
         */
        if (in_array($objectName, $syncedObjects)) {
            /**
             * skip execution if hook originates from API De Goede Woning
             */
            if (!isset($GLOBALS['dgw_api']) || $GLOBALS['dgw_api'] != "nosync") {
                /**
                 * check if sync action is required when op = edit
                 */
                if ($op == "edit") {
                    $syncRequired = _checkSyncRequired ($op, $objectName, $objectId, $objectRef);
                } else {
                    $syncRequired = true;
                }
                /**
                 * if syncAction is required, process sync
                 */
                if ($syncRequired) {
                    /**
                     * if $op = delete $objectRef will be empty. In that case, retrieve contactId from API
                     */
                    $contactId = 0;
                    if ($op == "delete") {
                        $delObjectParams = array(
                            'version'   =>  3,
                            'id'        =>  $objectId
                        );
                        $delObject = civicrm_api($objectName, 'Getsingle', $delObjectParams);
                        if (!isset($delObject['is_error']) || $delObject['is_error'] = 0) {
                            if (isset($delObject['contact_id'])) {
                                $contactId = $delObject['contact_id'];
                            }
                        }
                    } else {
                        if (is_object($objectRef)) {
                            if (isset($objectRef->contact_id)) {
                                $contactId = $objectRef->contact_id;
                            }
                        } else {
                            if (isset($objectRef['contact_id'])) {
                                $contactId = $objectRef['contact_id'];
                            }
                        }
                    }
                    $syncResult = _syncFirstObject($op, $objectId, $contactId, $objectName);
                }
            }
        }
    }
    return;
}
/**
 * Implementation of hook_civicrm_post
 *
 * @author Erik Hommel (erik.hommel@civicoop.org)
 *
 * - synchronization for crate operation
 *
 */
function sync_civicrm_post($op, $objectName, $objectId, &$objectRef) {
    /**
     * only for some objects
     */
    $syncedObjects = array("Individual", "Organization", "Address", "Email", "Phone");
    /**
     * only if op is create (other op handled in sync_civicrm_pre)
     */
    if ($op == "create") {
        /**
         * only if one of selected objects
         */
        if (in_array($objectName, $syncedObjects)) {
            /**
             * skip execution if hook originates from API De Goede Woning
             */
            if (!isset($GLOBALS['dgw_api']) || $GLOBALS['dgw_api'] != "nosync") {
                if ($objectName == "Individual" || $objectName == "Organization") {
                    $contactId = $objectId;
                } else {
                    if (is_object($objectRef)) {
                        if (isset($objectRef->contact_id)) {
                            $contactId = $objectRef->contact_id;
                        }
                    } else {
                        if (isset($objectRef['contact_id'])) {
                            $contactId = $objectRef['contact_id'];
                        } else {
                            $contactId = 0;
                        }
                    }
                }
                $syncResult = _syncFirstObject($op, $objectId, $contactId, $objectName);
            }
        }
    }
    return;
}
/**
 * Function to check if synchronization is required
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $objectName, $objectId, $objectRef
 * @return $syncRequired (boolean)
 */
function _checkSyncRequired($op, $objectName, $objectId, $objectRef) {
    $syncRequired = false;
    if ($op == "delete") {
        if ($objectName == "Individual" || $objectName == "Organization") {
            return $syncRequired;
        }
    }
    /**
     * sync is only required if contact also exists in NCCW First.
     */
    $objectInFirst = _checkObjectInFirst($objectName, $objectId);
    if ($objectInFirst) {
        $apiParams = array(
            'version'   => 3,
            'id'        => $objectId
        );
        if ($objectName == "Individual" || $objectName == "Organization") {
            $resultCheck = civicrm_api('Contact', 'Getsingle', $apiParams);
        } else {
            $resultCheck = civicrm_api($objectName, 'Getsingle', $apiParams);
        }
        /**
         * return false if error in api
         */
        if (isset($resultCheck['is_error']) && $resultCheck['is_error'] == 1) {
            return $syncRequired;
        }
        /**
         * check fields in object against database fields depending on object
         * and handle the discrepancy between objecRef as object and as array
         */
        switch ($objectName) {
            case "Individual":
                if (isset($resultCheck['gender_id'])) {
                    if (is_object($objectRef)) {
                        if (isset($objectRef->gender_id)) {
                            if ($resultCheck['gender_id'] != $objectRef->gender_id) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if (isset($objectRef['gender_id'])) {
                            if ($resultCheck['gender_id'] != $objectRef['gender_id']) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if (isset($resultCheck['first_name'])) {
                    if (is_object($objectRef)) {
                        if (isset($objectRef->first_name)) {
                            if ($resultCheck['first_name'] != $objectRef->first_name) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if (isset($objectRef['first_name'])) {
                            if ($resultCheck['first_name'] != $objectRef['first_name']) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if (isset($resultCheck['middle_name'])) {
                    if (is_object($objectRef)) {
                        if (isset($objectRef->middle_name)) {
                            if ($resultCheck['middle_name'] != $objectRef->middle_name) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if (isset($objectRef['middle_name'])) {
                            if ($resultCheck['middle_name'] != $objectRef['middle_name']) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if (isset($resultCheck['last_name'])) {
                    if (is_object( $objectRef)) {
                        if (isset($objectRef->last_name)) {
                            if ($resultCheck['last_name'] != $objectRef->last_name) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if (isset($objectRef['last_name'])) {
                            if ($resultCheck['last_name'] != $objectRef['last_name']) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                /**
                 * reformat birth date because API and object use diffent formats
                 */
                if (isset($resultCheck['birth_date'])) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->birth_date ) ) {
                            if ( strtotime( $resultCheck['birth_date'] ) != strtotime( $objectRef->birth_date ) ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['birth_date'] ) ) {
                            if ( strtotime( $resultCheck['birth_date'] ) != strtotime( $objectRef['birth_date'] ) ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }

                /*
                 * if still no sync required, also check custom field for
                 * burgerlijke staat
                 */
                if ( !$syncRequired ) {
                    require_once 'CRM/Utils/DgwUtils.php';
                    $customFieldData = CRM_Utils_DgwUtils::getCustomField ( array( 'label' => "burgerlijke staat") );
                    if ( isset( $customFieldData['id'] ) ) {
                        $apiParams = array(
                            'version'   =>  3,
                            'entity_id' =>  $objectId
                        );
                        $customValues = civicrm_api('CustomValue', 'Get', $apiParams );
                        if ( $customValues['is_error'] == 0 ) {
                            foreach ( $customValues['values'] as $customId => $customValue ) {
                                /*
                                 * if id of custom field retrieved from API is the same as
                                 * the id of the custom field for burgerlijke staat
                                 */
                                if ( $customFieldData['id'] == $customValue['id'] ) {
                                    require_once 'CRM/Utils/DgwApiUtils.php';
                                    $customElement = CRM_Utils_DgwApiUtils::getCustomValueTableElement( $customValue );
                                    if ( !empty( $customElement ) ) {
                                        // safe to use array element 0 because it is not a repeating group
                                        $customCompare = "custom_".$customElement[0]['custom_id']."_".$customElement[0]['record_id'];
                                        if ( is_object( $objectRef ) ) {
                                            if ( isset( $objectRef->$customCompare ) ) {
                                                if ( $customElement[0]['value'] != $objectRef->$customCompare ) {
                                                    $syncRequired = true;
                                                }
                                            }
                                        } else {
                                            if ( isset( $objectRef[$customCompare] ) ) {
                                                if ( $customElement[0]['value'] != $objectRef[$customCompare] ) {
                                                    $syncRequired = true;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                break;
            case "Organization":
                if ( isset( $resultCheck['organization_name'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->organization_name ) ) {
                            if ( $resultCheck['organization_name'] != $objectRef->organization_name ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['organization_name'] ) ) {
                            if ( $resultCheck['organization_name'] != $objectRef['organization_name'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                break;

            case "Address":
                if ( isset( $resultCheck['street_address'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->street_address ) ) {
                            if ( $resultCheck['street_address'] != $objectRef->street_address ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['street_address'] ) ) {
                            if ( $resultCheck['street_address'] != $objectRef['street_address'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['street_name'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->street_name ) ) {
                            if ( $resultCheck['street_name'] != $objectRef->street_name ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['street_name'] ) ) {
                            if ( $resultCheck['street_name'] != $objectRef['street_name'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['street_number'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->street_number ) ) {
                            if ( $resultCheck['street_number'] != $objectRef->street_number ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['street_number'] ) ) {
                            if ( $resultCheck['street_number'] != $objectRef['street_number'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['street_unit'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->street_unit ) ) {
                            if ( $resultCheck['street_unit'] != $objectRef->street_unit ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['street_unit'] ) ) {
                            if ( $resultCheck['street_unit'] != $objectRef['street_unit'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['postal_code'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->postal_code ) ) {
                            if ( $resultCheck['postal_code'] != $objectRef->postal_code ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['postal_code'] ) ) {
                            if ( $resultCheck['postal_code'] != $objectRef['postal_code'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['city'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->city ) ) {
                            if ( $resultCheck['city'] != $objectRef->city ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['city'] ) ) {
                            if ( $resultCheck['city'] != $objectRef['city'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['country_id'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->country_id ) ) {
                            if ( $resultCheck['country_id'] != $objectRef->country_id ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['country_id'] ) ) {
                            if ( $resultCheck['country_id'] != $objectRef['country_id'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                break;

            case "Phone":
                if ( isset( $resultCheck['location_type_id'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->location_type_id ) ) {
                            if ( $resultCheck['location_type_id'] != $objectRef->location_type_id ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['location_type_id'] ) ) {
                            if ( $resultCheck['location_type_id'] != $objectRef['location_type_id'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['phone_type_id'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->phone_type_id ) ) {
                            if ( $resultCheck['phone_type_id'] != $objectRef->phone_type_id ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['phone_type_id'] ) ) {
                            if ( $resultCheck['phone_type_id'] != $objectRef['phone_type_id'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['phone'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->phone ) ) {
                            if ( $resultCheck['phone'] != $objectRef->phone ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['phone'] ) ) {
                            if ( $resultCheck['phone'] != $objectRef['phone'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                break;

            case "Email":
                if ( isset( $resultCheck['location_type_id'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->location_type_id ) ) {
                            if ( $resultCheck['location_type_id'] != $objectRef->location_type_id ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['location_type_id'] ) ) {
                            if ( $resultCheck['location_type_id'] != $objectRef['location_type_id'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                if ( isset( $resultCheck['email'] ) ) {
                    if ( is_object( $objectRef ) ) {
                        if ( isset( $objectRef->email ) ) {
                            if ( $resultCheck['email'] != $objectRef->email ) {
                                $syncRequired = true;
                            }
                        }
                    } else {
                        if ( isset( $objectRef['email'] ) ) {
                            if ( $resultCheck['email'] != $objectRef['email'] ) {
                                $syncRequired = true;
                            }
                        }
                    }
                }
                break;
        }
    }
    return $syncRequired;
}
/**
 * Function to check if object exists in First Noa
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $objectName, $objectId
 * @return $objectInFirst (boolean)
 */
function _checkObjectInFirst( $objectName, $objectId ) {
    $objectInFirst = false;
    /*
     * no synchronization required for household
     */
    $lowerObject = strtolower( $objectName );
    if ( $lowerObject == "household" ) {
        return $objectInFirst;
    }
    /*
     * retrieve record for object from synchronization table
     */
    if ( $lowerObject == "individual" || $lowerObject == "organization" ) {
        $syncObject = "contact";
    } else {
        $syncObject = $lowerObject;
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first entity veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $entityFldName = $retrieveSyncField['sync_field'];
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first entity_id veld');
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $entityIdFldName = $retrieveSyncField['sync_field'];
    }
    require_once 'CRM/Utils/DgwUtils.php';
    $customTableTitle = CRM_Utils_DgwUtils::getDgwConfigValue( 'synchronisatietabel first' );
    $customTable = CRM_Utils_DgwUtils::getCustomGroupTableName( $customTableTitle );
    $selSync =
"SELECT COUNT(*) AS aantal FROM $customTable WHERE $entityIdFldName = $objectId and $entityFldName = '$syncObject'";
    $daoSync = CRM_Core_DAO::executeQuery( $selSync );
    if ( $daoSync->fetch() ) {
        if ( $daoSync->aantal > 0 ) {
            $objectInFirst = true;
        }
    }
    return $objectInFirst;
}
/**
 * Function to add contact to group for synchronization First Noa
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $params with contact_id
 * @return none
 */
function _addContactSyncGroup( $contactId ) {
    if ( !empty( $contactId ) ) {
        require_once 'CRM/Utils/DgwUtils.php';
        $groupTitle = CRM_Utils_DgwUtils::getDgwConfigValue( 'groep sync first' );
        $groupParams = array(
            'version'   =>  3,
            'title'     =>  $groupTitle
        );
        $groupData = civicrm_api( 'Group', 'Getsingle', $groupParams );
        if ( !isset( $groupData['is_error'] ) || $groupData['is_error'] == 0 ) {
            $addParams = array(
                'version'       =>  3,
                'contact_id'    =>  $contactId,
                'group_id'      =>  $groupData['id']
            );
            $addResult = civicrm_api( 'GroupContact', 'Create', $addParams );
        }
    }
    return;
}
/**
 * Function to add or update record in synchronization table
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $action = operation (create, update or delete)
 * @param $contactId = id of the contact the sync record
 * @param $entityId = CiviCRM id of the entity affected
 * @param $entityName = name of the entity
 * @return $result array
 */
function _setSyncRecord( $action, $contactId, $entityId, $entityName, $keyFirst = null ) {
    $result = array( );
    /*
     * return without further processing if one of the params is empty
     */
    if ( empty( $action ) || empty( $entityId ) || empty( $entityName ) ) {
        $result['is_error'] = 1;
        $result['error_message'] = "action, entityId and entityName are mandatory params and can not be empty";
        return $result;
    }
    /*
     * retrieve custom_id's of the required fields
     */
    require_once 'CRM/Utils/DgwUtils.php';
    $retrieveSyncField = _retrieveSyncField( 'sync first action veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $actionFldId = "custom_".$retrieveSyncField['sync_field_id'];
        $actionFldName = $retrieveSyncField['sync_field'];
    } else {
        $result['is_error'] = 1;
        $result['error_message'] = "Could not retrieve field name for sync first action veld: {$retrieveSyncField['error_message']}";
        return $result;
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first entity veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $entityFldId = "custom_".$retrieveSyncField['sync_field_id'];
        $entityFldName = $retrieveSyncField['sync_field'];
    } else {
        $result['is_error'] = 1;
        $result['error_message'] = "Could not retrieve field name for sync first entity veld: {$retrieveSyncField['error_message']}";
        return $result;
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first entity_id veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $entityIdFldId = "custom_".$retrieveSyncField['sync_field_id'];
        $entityIdFldName = $retrieveSyncField['sync_field'];
    } else {
        $result['is_error'] = 1;
        $result['error_message'] = "Could not retrieve field name for sync first entity_id veld: {$retrieveSyncField['error_message']}";
        return $result;
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first key_first veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $keyFirstFldId = "custom_".$retrieveSyncField['sync_field_id'];
        $keyFirstFldName = $retrieveSyncField['sync_field'];
    } else {
        $result['is_error'] = 1;
        $result['error_message'] = "Could not retrieve field name for sync first key_first veld: {$retrieveSyncField['error_message']}";
        return $result;
    }
    $retrieveSyncField = _retrieveSyncField( 'sync first change_date veld' );
    if ( $retrieveSyncField['is_error'] == 0 ) {
        $changeDateFldId = "custom_".$retrieveSyncField['sync_field_id'];
        $changeDateFldName = $retrieveSyncField['sync_field'];
    } else {
        $result['is_error'] = 1;
        $result['error_message'] = "Could not retrieve field name for sync first change_date veld: {$retrieveSyncField['error_message']}";
        return $result;
    }
    /*
     * process based on action
     */
    $customValueParams = array(
        'version'   =>  3,
        'entity_id' =>  $contactId
    );
    switch( $action ) {
        case "create":
            $customValueParams[$actionFldId] = "ins";
            $customValueParams[$entityFldId] = strtolower( $entityName );
            $customValueParams[$entityIdFldId] = $entityId;
            $customValueParams[$changeDateFldId] = date('Ymd');
            $resultSync = civicrm_api( 'CustomValue', 'Create', $customValueParams );
            break;
        case "edit":
            /*
             * retrieve record id of the relevant custom group record
             */
            $customRecordId = _retrieveSyncRecordId ( $entityName, $contactId, $entityId, $entityFldName, $entityIdFldName );
            if ( $customRecordId != 0 ) {
                $customValueParams[$actionFldId.":".$customRecordId] = "upd";
                if ( isset( $keyFirst ) ) {
                    $customValueParams[$keyFirstFldId.":".$customRecordId] = $keyFirst;
                }
                $customValueParams[$changeDateFldId.":".$customRecordId] = date('Ymd');
                $resultSync = civicrm_api( 'CustomValue', 'Create', $customValueParams );
            }
            break;
        case "delete":
            $customRecordId = _retrieveSyncRecordId ( $entityName, $contactId, $entityId, $entityFldName, $entityIdFldName );
            if ( $customRecordId != 0 ) {
                $customValueParams[$actionFldId.":".$customRecordId] = "del";
                $customValueParams[$changeDateFldId.":".$customRecordId] = date('Ymd');
                $resultSync = civicrm_api( 'CustomValue', 'Create', $customValueParams );
            }
    }
    return;
}
/**
 * function to retrieve the sync table custom table id. This should really
 * be possible with the API, but probably no time to fix in the core API
 */
function _retrieveSyncRecordId( $entityName, $contactId, $entityId, $entityFldName, $entityIdFldName ) {
    $recordId = 0;
    if ( empty( $entityName ) || empty( $contactId ) || empty( $entityId ) ) {
        return $recordId;
    }
    $customTableTitle = CRM_Utils_DgwUtils::getDgwConfigValue( 'synchronisatietabel first' );
    $customTable = CRM_Utils_DgwUtils::getCustomGroupTableName( $customTableTitle );
    $selRecord =
"SELECT id FROM $customTable WHERE entity_id = $contactId AND $entityFldName = '$entityName' AND $entityIdFldName = $entityId";
    $daoRecord = CRM_Core_DAO::executeQuery( $selRecord );
    if ( $daoRecord->fetch() ) {
        $recordId = $daoRecord->id;
    }
    return $recordId;
}
/**
 * function to clean up synchronization table, only called in enable function
 */
function _cleanSyncTable( $syncTable ) {
    /*
     * first create backup table clean_sync_save and store data
     */
    $saveTableExists = CRM_Core_DAO::checkTableExists( 'clean_sync_save' );
    if ( $saveTableExists ) {
        CRM_Core_DAO::executeQuery( "DROP TABLE clean_sync_save" );
    }
    CRM_Core_DAO::executeQuery( "CREATE TABLE clean_sync_save LIKE $syncTable ");
    CRM_Core_DAO::executeQuery( "INSERT INTO clean_sync_save SELECT * FROM $syncTable" );
    /*
     * noew create temp table for distinct keys
     */
    $tempTableExists = CRM_Core_DAO::checkTableExists( 'check_sync' );
    if ( $tempTableExists ) {
        CRM_Core_DAO::executeQuery( "DROP TABLE check_sync" );
    }

    $toBeRemoveds = array();
    CRM_Core_DAO::executeQuery( "CREATE TABLE check_sync (id int(11) ,  PRIMARY KEY (id))" );

    $selDistinct =
"INSERT INTO check_sync (SELECT DISTINCT(entity_id) FROM $syncTable)";
    CRM_Core_DAO::executeQuery( $selDistinct );
    $daoDistinct = CRM_Core_DAO::executeQuery( "SELECT * FROM check_sync");

    while ( $daoDistinct->fetch() ) {

        $previousCivi = null;
        $previousEntity = null;
        $previousFirst = null;
        $actionFld = CRM_Utils_DgwUtils::getDgwConfigValue( 'sync first action veld' );
        $entityFld = CRM_Utils_DgwUtils::getDgwConfigValue( 'sync first entity veld' );
        $entityIdFld = CRM_Utils_DgwUtils::getDgwConfigValue( 'sync first entity_id veld' );
        $keyFirstFld = CRM_Utils_DgwUtils::getDgwConfigValue( 'sync first key_first veld' );
        $changeDateFld = CRM_Utils_DgwUtils::getDgwConfigValue( 'sync first change_date veld' );

        $selSync =
"SELECT * FROM $syncTable WHERE entity_id = {$daoDistinct->id} ORDER BY entity_49, entity_id_50, key_first_51, change_date_52 DESC";
        $daoSync = CRM_Core_DAO::executeQuery( $selSync);
        while ( $daoSync->fetch() ) {
            if ( $daoSync->$entityFld == $previousEntity && $daoSync->$entityIdFld == $previousCivi
                    && $daoSync->$keyFirstFld == $previousFirst ) {
                $toBeRemoved['id'] = $daoSync->id;
                $toBeRemoved['type'] = $daoSync->$entityFld;
                $toBeRemoved['entity_id'] = $daoDistinct->id;
                $toBeRemoveds[] = $toBeRemoved;
            } else {
                if ( $daoSync->$entityIdFld != $previousCivi ) {
                    $previousCivi = $daoSync->$entityIdFld;
                }
                if ( $daoSync->$keyFirstFld != $previousFirst ) {
                    $previousFirst = $daoSync->$keyFirstFld;
                }
                if ( $daoSync->$entityFld != $previousEntity ) {
                    $previousEntity = $daoSync->$entityFld;
                }
            }
        }
    }
    foreach ( $toBeRemoveds as $removeData ) {
        $removeQry = "DELETE FROM $syncTable WHERE id = {$removeData['id']}";
        CRM_Core_DAO::executeQuery( $removeQry );
    }
    return;
}
/**
 * function to synchronize object with First Noa which means:
 * - update record in synchronization table
 * - add contact to synchronization group
 *
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $op, $objectId, $objectRef (array or object with values)
 * @return $result array
 */
function _syncFirstObject( $op, $objectId, $contactId, $objectName ) {
    $result = array( );
    /*
     * objectId and contactId can not be empty
     */
    if ( empty( $objectId ) || empty( $contactId ) ) {
        $result['is_error'] = 1;
        $result['error_message'] = "objectId and contactId can not be empty";
        return $result;
    }
    /*
     * create or update synchronization table record for organization
     */
    if ( $objectName == "Individual" || $objectName == "Organization" ) {
        $resultSync = _setSyncRecord( $op, $contactId, $objectId, 'contact' );
    } else {
        $resultSync = _setSyncRecord(  $op, $contactId, $objectId, $objectName );
    }
    /*
     * add contact to synchronization group
     */
    _addContactSyncGroup( $contactId );
    $result['is_error'] = 0;
    return $result;
}
/**
 * function to retrieve the custom_xx of a sync first field
 * @author Erik Hommel (erik.hommel@civicoop.org)
 * @param $label label of a dgw_config value for a field name
 * @params $result array
 */
function _retrieveSyncField( $fldLabel ) {
    require_once 'CRM/Utils/SyncUtils.php';
    return CRM_Utils_SyncUtils::retrieveSyncField($fldLabel, CRM_Utils_DgwUtils::getDgwConfigValue( 'synchronisatietabel first' ));
}
/**
 * implementation of hook postProcess specifically to synchronize burgerlijke staat
 * if edited in Inline form. In this case, not pre or post hook is triggered by core.
 *
 *
 * @param string $formName
 * @param object $form
 */
function sync_civicrm_postProcess( $formName, &$form ) {
    if ( $formName == "CRM_Contact_Form_Inline_CustomData" ) {
        $groupId = $form->getvar('_groupID');
        if ( $groupId == 1 ) {
            $contactId = $form->getvar( '_contactId' );
            $element = $form->getElement('custom_3_252201');
            $values = $element->_values;
            foreach ( $values as $waarde ) {
                $burgStaat = $waarde;
            }
            /*
             * There is no way to check the original value as the inline update of a custom field
             * does not trigger the hook post or pre. The only option would be to store the
             * original burgerlijke staat in a constant during the buildForm. Elected instead
             * to always send change when burgerlijke staat is edited, which will not be often
             */
            $resultSync = _syncFirstObject( "edit", $contactId, $contactId, "Individual" );
        }
    }
}