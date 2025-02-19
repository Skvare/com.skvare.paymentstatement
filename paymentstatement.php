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

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function paymentstatement_civicrm_navigationMenu(&$menu) {
  _paymentstatement_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('Payment Statement Setting'),
    'name' => 'payment_statement_setting',
    'url' => CRM_Utils_System::url('civicrm/admin/paymentstatement', 'reset=1', TRUE),
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _paymentstatement_civix_navigationMenu($menu);
}
