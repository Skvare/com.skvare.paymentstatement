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
  $spec['period']['api_required'] = 1;
  $spec['period']['title'] = 'Relative time period';
  $spec['period']['description'] = 'Relative time period';
  $spec['period']['api.default'] = 'this.year';

  $spec['type']['api_required'] = 1;
  $spec['type']['title'] = 'Period type';
  $spec['type']['description'] = 'Period type, e.g year, month';
  $spec['type']['api.default'] = 'year';
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
  // validate the period value.
  $relativeDate = explode('.', $params['period'], 2);
  if (count($relativeDate) == 2) {
    // convert relative date to actual date.
    [$from, $to] = CRM_Utils_Date::getFromTo($params['period'], '', '');
    if (empty($from)) {
      throw new API_Exception('Invalid relative date format', 'period');
    }
  }
  else {
    throw new API_Exception('Invalid date format', 'period');
  }
  // Send request to generate payment statement.
  $object = new CRM_Paymentstatement_Utils();
  $object->generatePaymentStatement($params['type'], $params['period']);
  $returnValues = "Payment statement generated for period: {$params['period']}";
  return civicrm_api3_create_success($returnValues, $params, 'Job', 'Yeartaxstatement');
}
