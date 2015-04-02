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
    * @param string $fldLabel label of a dgw_config value for a field name
    * @param string $customGroupLabel
    * @return array $result
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
                            }
                            $customParams = array(
                                'version'   =>  3,
                                'label'     =>  $customLabel,
                                'group'     =>  $customGroupKey
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

  /**
   * Method to create sync record for object if there is not one and there is a
   * persoonsnummer First for the contact
   *
   * BOS1307645\01 (Erik Hommel <erik.hommel@civicoop.org> 2 Apr 2015)
   * @param string $objectName
   * @param int $objectId
   * @param string $customTable
   * @param string $entityColumn
   * @param string $actionColumn
   * @param string $entityIdColumn
   * @param string $keyFirstColumn
   * @return bool
   * @access public
   * @static
   */
  public static function correctSyncRecord($objectName, $objectId, $customTable, $entityColumn, $actionColumn, $entityIdColumn, $keyFirstColumn) {
    if ($objectName == 'Organization' || $objectName == 'Individual') {
      $persoonsnummerFirst = self::contactHasPersoonsnummerFirst($objectId, $objectName);
      if (!empty($persoonsnummerFirst)) {
        $queryColumns = array('entity_id', $entityColumn, $actionColumn, $entityIdColumn, $keyFirstColumn);
        $query = 'INSERT INTO '.$customTable.' ('.implode(', ', $queryColumns).') VALUES(%1, %2, %3, %4, %5)';
        $queryParams = array(
          1 => array($objectId, 'Integer'),
          2 => array('contact', 'String'),
          3 => array('none', 'String'),
          4 => array($objectId, 'Integer'),
          5 => array($persoonsnummerFirst, 'String')
        );
        CRM_Core_DAO::executeQuery($query, $queryParams);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Method to retrieve the persoonsnummer First for a contact
   *
   * @param int $contactId
   * @param string $objectName
   * @return string $persoonsnummerFirst
   * @throws Exception when error from API
   * @access public
   * @static
   */
  public static function contactHasPersoonsnummerFirst($contactId, $objectName) {
    CRM_Core_Error::debug('contact_id', $contactId);
    $persoonsnummerFirst = null;
    switch ($objectName) {
      case 'Individual':
        $tableTitle = CRM_Utils_DgwUtils::getDgwConfigValue('tabel data first');
        $customFieldLabel = CRM_Utils_DgwUtils::getDgwConfigValue('persoonsnummer first');
        break;
      case 'Organization':
        $tableTitle = 'Gegevens uit First';
        $customFieldLabel = 'Nr. in First';
        break;
    }
    $customGroupParams = array('title' => $tableTitle);
    try {
      $customGroup = civicrm_api3('CustomGroup', 'Getsingle', $customGroupParams);
      $customFieldParams = array(
        'custom_group_id' => $customGroup['id'],
        'label' => $customFieldLabel,
        'return' => 'column_name'
      );
      try {
        $customFieldColumnName = civicrm_api3('CustomField', 'Getvalue', $customFieldParams);
        $query = 'SELECT '.$customFieldColumnName.' AS persNrFirst FROM '.$customGroup['table_name'].' WHERE entity_id = %1';
        $queryParams[1] = array($contactId, 'Integer');
        $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
        if ($dao->fetch()) {
          if (!empty($dao->persNrFirst)) {
            $persoonsnummerFirst = $dao->persNrFirst;
          }
        }
      } catch (CiviCRM_API3_Exception $ex) {
        throw new Exception('Kon geen custom field met het label ' .$customFieldLabel. ' vinden in de custom group met title '
          .$tableTitle. ', error uit API CustomField Getvalue: ' .$ex->getMessage());
      }
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception('Kon geen custom group met title '.$tableTitle.' vinden, error uit API CustomGroup Getsingle: '.$ex->getMessage());
    }
    return $persoonsnummerFirst;
  }

}