<?php
use CRM_Paymentstatement_ExtensionUtil as E;

/**
 * Job.Yeartaxstatement API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Yeartaxstatement_spec(&$spec) {
  //$spec['magicword']['api.required'] = 1;
}

/**
 * Job.Yeartaxstatement API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws CRM_Core_Exception
 */
function civicrm_api3_job_Yeartaxstatement($params) {
  CRM_Core_Error::debug_var('civicrm_api3_job_Yeartaxstatement $params', $params);
  $object = new CRM_Paymentstatement_Utils();
  $object->generatePaymentStatement('year', 'this.year');
}
