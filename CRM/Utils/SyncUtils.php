<?php
/*
+--------------------------------------------------------------------+
| Project       :   CiviCRM De Goede Woning - Upgrade CiviCRM 4.3    |
| Author        :   Erik Hommel (CiviCooP, erik.hommel@civicoop.org  |
| Date          :   16 April 20134                                   |
| Description   :   Class with DGW helper functions                  |
+--------------------------------------------------------------------+
*/

/**
*
* @package CRM
* @copyright CiviCRM LLC (c) 2004-2013
* $Id$
*
*/
class CRM_Utils_SyncUtils {
    /**
    * function to retrieve the custom_xx of a sync first field
    * @author Erik Hommel (erik.hommel@civicoop.org)
    * @param $label label of a dgw_config value for a field name
    * @params $result array
    */
    static function retrieveSyncField($fldLabel, $customGroupLabel) {
        $result = array();
        if ( empty($fldLabel)) {
            $result['is_error'] = 1;
            $result['error_message'] = "fldLabel is empty, no field name associated";
            return $result;
        }
        $customLabel = CRM_Utils_DgwUtils::getDgwConfigValue($fldLabel);
        if (empty($customLabel)) {
            $result['is_error'] = 1;
            $result['error_message'] = "customLabel is empty, no field name associated";
            return $result;
        }
        $customParams = array(
            'version'   =>  3,
            'label'     =>  $customLabel
        );
        $result['is_error'] = 0;
        $customFields = civicrm_api('CustomField', 'Get', $customParams);
        if (isset($customFields['is_error']) && $customFields['is_error'] == 1) {
            $result['is_error'] = 1;
            $result['error_message'] = "Error in CustomField Get API : {$customFields['error_message']}";
            return $result;
        } else {
            if ($customFields['count'] == 1) {
                foreach($customFields['values'] as $customFieldId => $customField) {
                    $result['sync_field'] = $customField['column_name'];
                    $result['sync_field_id'] = $customFieldId;
                    return $result;
                }
            } else {
                /*
                 * retrieve FirstSync group to select correct field
                 */
                $customGroupParams = array(
                    'version'   =>  3,
                    'title'     =>  $customGroupLabel
                );
                $customGroups = civicrm_api('CustomGroup', 'Get', $customGroupParams);
                if (isset($customGroups['is_error']) && $customGroups['is_error'] == 1) {
                    $result['is_error'] = 1;
                    $result['error_message'] = "Error in CustomGroup Get API : {$customGroups['error_message']}";
                    return $result;
                } else {
                    if ($customGroups['count'] > 1) {
                        $result['is_error'] = 1;
                        $result['error_message'] = "There are more than one custom groups with the title $customGroupLabel";
                            return $result;
                        } else {
                            foreach( $customGroups as $customGroupKey => $customGroup ) {
                                $customGroupId = $customGroupKey;
                            }
                            $customParams = array(
                                'version'   =>  3,
                                'label'     =>  $customLabel,
                                'group'     =>  $customGroupId
                            );
                            $customFields = civicrm_api( 'CustomField', 'Get', $customParams );
                            if ( isset( $customFields['is_error'] ) && $customFields['is_error'] == 1 ) {
                                $result['is_error'] = 1;
                                $result['error_message'] = "Error in CustomField Get API : {$customFields['error_message']}";
                            } else {
                                if ( $customFields['count'] == 1 ) {
                                    foreach( $customFields['values'] as $customFieldId => $customField ) {
                                        $result['sync_field'] = $customField['column_name'];
                                        $result['sync_field_id'] = $customFieldId;
                                    }
                                } else {
                                    $result['is_error'] = 1;
                                    $result['error_message'] = "Er zijn meer velden met label $customLabel in de synchronisatietabel $customGroupLabel";
                                }
                            }
                        }
                    }
                }
            }
            return $result;
    }
}