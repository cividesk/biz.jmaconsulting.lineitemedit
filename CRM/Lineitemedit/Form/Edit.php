<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Lineitemedit_Form_Edit extends CRM_Core_Form {

  /**
   * The line-item values of an existing contribution
   */

  public $_id;

  public $_values;

  public $_isQuickConfig = FALSE;

  public $_priceFieldInfo = array();

  protected $_lineitemInfo;

  public function preProcess() {
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $this->assignFormVariables();
  }

  /**
   * Check if there is tax value for selected financial type.
   * @param $financialTypeId
   * @return bool
   */
  private function isTaxEnabledInFinancialType($financialTypeId) {
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();
    return (isset($taxRates[$financialTypeId])) ? TRUE : FALSE;
  }

  public function assignFormVariables($params = []) {

    $this->_lineitemInfo = civicrm_api3('lineItem', 'getsingle', array('id' => $this->_id));
    $this->_lineitemInfo['tax_amount'] = CRM_Utils_Array::value('tax_amount', $this->_lineitemInfo, 0.00);
    foreach (CRM_Lineitemedit_Util::getLineitemFieldNames() as $attribute) {
      $this->_values[$attribute] = CRM_Utils_Array::value($attribute, $this->_lineitemInfo, 0);
    }

    $this->_values['currency'] = CRM_Core_DAO::getFieldValue(
      'CRM_Financial_DAO_Currency',
      CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution', $this->_lineitemInfo['contribution_id'], 'currency'),
      'symbol',
      'name'
    );

    $this->_isQuickConfig = (bool) CRM_Core_DAO::getFieldValue(
      'CRM_Price_DAO_PriceSet',
      CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceField', $this->_lineitemInfo['price_field_id'], 'price_set_id'),
      'is_quick_config'
    );

    $this->_priceFieldInfo = civicrm_api3('PriceField', 'getsingle', array('id' => $this->_lineitemInfo['price_field_id']));

    if ($this->_isQuickConfig || $this->_priceFieldInfo['is_enter_qty'] == 0) {
      $this->_values['qty'] = (int) $this->_values['qty'];
    }
  }

  /**
   * Set default values.
   *
   * @return array
   */
  public function setDefaultValues() {
    return $this->_values;
  }

  public function buildQuickForm() {
    $fieldNames = array_keys($this->_values);
    foreach ($fieldNames as $fieldName) {
      $required = TRUE;
      if ($fieldName == 'line_total') {
        $this->add('text', 'line_total', ts('Total amount'), array(
          'size' => 6,
          'maxlength' => 14,
          'readonly' => TRUE)
        );
        continue;
      }
      elseif ($fieldName == 'currency') {
        $this->assign('currency', $this->_values['currency']);
        continue;
      }
      $properties = array(
        'entity' => 'LineItem',
        'name' => $fieldName,
        'context' => 'edit',
        'action' => 'create',
      );
      if ($fieldName == 'financial_type_id') {
        CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes($financialTypes);
        $properties['options'] = $financialTypes;
      }
      // In case of text non-quickconfig price field we cannot change the unit price
      elseif ($fieldName == 'tax_amount') {
        $properties['readonly'] = TRUE;
        $required = FALSE;
      }

      $ele = $this->addField($fieldName, $properties, $required);
      if ($this->_lineitemInfo['entity_table'] != 'civicrm_contribution' && $fieldName == 'financial_type_id') {
        $ele->freeze();
      }
    }
    $this->assign('fieldNames', $fieldNames);

    $this->assign('taxRates', json_encode(CRM_Core_PseudoConstant::getTaxRates()));

    $this->assign('isTaxEnabled', $this->isTaxEnabledInFinancialType($this->_values['financial_type_id']));

    $this->addFormRule(array(__CLASS__, 'formRule'), $this);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
      array(
        'type' => 'cancel',
        'name' => ts('Close'),
      ),
    ));

    parent::buildQuickForm();
  }

  public static function formRule($fields, $files, $self) {
    $errors = array();

    if (!CRM_Utils_Rule::integer($fields['qty'])) {
      if ($self->_isQuickConfig || $self->_priceFieldInfo['is_enter_qty'] == 0) {
        $errors['qty'] = ts('Please enter a whole number quantity');
      }
    }

    return $errors;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $this->submit($values);
    parent::postProcess();
  }

  public function submit($values, $isTest = FALSE) {
    $values['line_total'] = CRM_Utils_Rule::cleanMoney($values['line_total']);

    $balanceAmount = ($values['line_total'] - $this->_lineitemInfo['line_total']);
    $contactId = CRM_Core_DAO::getFieldValue('CRM_Contribute_BAO_Contribution',
      $this->_lineitemInfo['contribution_id'],
      'contact_id'
    );

    if (!$this->isTaxEnabledInFinancialType($values['financial_type_id'])) {
      $values['tax_amount'] = '';
    }
    $params = array(
      'id' => $this->_id,
      'financial_type_id' => $values['financial_type_id'],
      'label' => $values['label'],
      'qty' => $values['qty'],
      'unit_price' => CRM_Utils_Rule::cleanMoney($values['unit_price']),
      'line_total' => $values['line_total'],
      'tax_amount' => CRM_Utils_Array::value('tax_amount', $values, 0.00),
    );

    $lineItem = CRM_Price_BAO_LineItem::create($params);
    $lineItem = $lineItem->toArray();

    // calculate balance, tax and paidamount later used to adjust transaction
    $updatedAmount = CRM_Price_BAO_LineItem::getLineTotal($this->_lineitemInfo['contribution_id']);
    $taxAmount = CRM_Lineitemedit_Util::getTaxAmountTotalFromContributionID($this->_lineitemInfo['contribution_id']);
    // Record adjusted amount by updating contribution info and create necessary financial trxns
    CRM_Lineitemedit_Util::recordAdjustedAmt(
      $updatedAmount,
      $this->_lineitemInfo['contribution_id'],
      $taxAmount,
      FALSE
    );

    // Record financial item on edit of lineitem
    CRM_Lineitemedit_Util::insertFinancialItemOnEdit(
      $this->_id,
      $this->_lineitemInfo
    );

    if (in_array($this->_lineitemInfo['entity_table'], ['civicrm_membership', 'civicrm_participant'])) {
      $this->updateEntityRecord($this->_lineitemInfo);
      $entityTab = ($this->_lineitemInfo['entity_table'] == 'civicrm_membership') ? 'member' : 'participant';
      if (!$isTest) {
        $this->ajaxResponse['updateTabs']['#tab_' . $entityTab] = CRM_Contact_BAO_Contact::getCountComponent(str_replace('civicrm_', '', $this->_lineitemInfo['entity_table']), $contactId);
      }
    }
  }

  protected function updateEntityRecord($lineItem) {
    if ($lineItem['entity_table'] == 'membership') {
      $memberNumTerms = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $lineItem['price_field_value_id'], 'membership_num_terms');
      $membershipTypeId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $lineItem['price_field_value_id'], 'membership_type_id');
      $memberNumTerms = empty($memberNumTerms) ? 1 : $memberNumTerms;
      $memberNumTerms = $lineItem['qty'] * $memberNumTerms;
      $memParams = array(
        'id' => $lineItem['entity_id'],
        'num_terms' => $memberNumTerms,
        'membership_type_id' => $membershipTypeId,
      );
      if ($lineItem['qty'] == 0) {
        $memParams['status_id'] = 'Cancelled';
        $memParams['is_override'] = TRUE;
      }
      else {
        $memParams['skipStatusCal'] = FALSE;
      }
      civicrm_api3('Membership', 'create', $memParams);
    }
    else {
      $partUpdateFeeAmt = ['id' => $lineItem['entity_id']];
      $getUpdatedLineItems = CRM_Utils_SQL_Select::from('civicrm_line_item')
                              ->where([
                                "entity_table = '!et'",
                                "entity_id = #eid",
                                "qty > 0",
                              ])
                              ->param('!et', $lineItem['entity_table'])
                              ->param('#eid', $lineItem['entity_id'])
                              ->execute()
                              ->fetchAll();
      $line = array();
      $lineTotal = 0;
      foreach ($getUpdatedLineItems as $updatedLineItem) {
        $line[$updatedLineItem['price_field_value_id']] = $updatedLineItem['label'] . ' - ' . (float) $updatedLineItem['qty'];
        $lineTotal += $updatedLineItem['line_total'] + $updatedLineItem['tax_amount'];
      }

      $partUpdateFeeAmt['fee_level'] = implode(', ', $line);
      $partUpdateFeeAmt['fee_amount'] = $lineTotal;
      civicrm_api3('Participant', 'create', $partUpdateFeeAmt);

      //activity creation
      CRM_Event_BAO_Participant::addActivityForSelection($lineItem['entity_id'], 'Change Registration');
    }
  }

  public function testSubmit($params) {
    $this->_id = (int) $params['id'];
    $this->assignFormVariables($params);
    $this->submit($params, TRUE);
  }

}
