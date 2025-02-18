<?php

require_once 'paymentstatement.civix.php';

use CRM_Paymentstatement_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function paymentstatement_civicrm_config(&$config): void {
  _paymentstatement_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function paymentstatement_civicrm_install(): void {
  _paymentstatement_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function paymentstatement_civicrm_enable(): void {
  _paymentstatement_civix_civicrm_enable();
}
