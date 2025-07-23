<?php
use CRM_Paymentstatement_ExtensionUtil as E;
/**
 * Collection of upgrade steps.
 */
class CRM_Paymentstatement_Upgrader extends \CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * Note that if a file is present sql\auto_install that will run regardless of this hook.
   */
  public function install(): void {
    $this->installStatmentMsgWorkflowTpls();
  }

  public function installStatmentMsgWorkflowTpls(): void {
    try {
      $optionGroup = civicrm_api3('OptionGroup', 'create', [
        'name' => 'msg_tpl_workflow_payment_statement',
        'title' => ts("Message Template Workflow for Payment Statement", ['domain' => 'com.skvare.paymentstatement']),
        'description' => ts("Message Template Workflow for Payment Statement", ['domain' => 'com.skvare.paymentstatement']),
        'is_reserved' => 1,
        'is_active' => 1,
      ]);
      $optionGroupId = $optionGroup['id'];
    }
    catch (Exception $e) {
      // If an exception is thrown, most likely the option group already exists,
      // in which case we'll just use that one.
      $optionGroupId = civicrm_api3('OptionGroup', 'getvalue', [
        'name' => 'msg_tpl_workflow_payment_statement',
        'return' => 'id',
      ]);
    }

    $msgTpls = [
      [
        'description' => ts('Contribution Statement - PDF', ['domain' => 'com.skvare.paymentstatement']),
        'label' => ts('Contribution Statement - PDF', ['domain' => 'com.skvare.paymentstatement']),
        'name' => 'payment_statement',
        'subject' => ts("Thank You for Your Contribution", ['domain' => 'com.skvare.paymentstatement']),
      ],
      [
        'description' => ts('Contribution Statement - Email', ['domain' => 'com.skvare.paymentstatement']),
        'label' => ts('Contribution Statement - Email', ['domain' => 'com.skvare.paymentstatement']),
        'name' => 'payment_statement_email',
        'subject' => '{$frequency} Contribution Statement for {$period}',
      ],
    ];

    $this->createMsgTpl($msgTpls, $optionGroupId);
  }

  /**
   * Create template.
   *
   * @param array $msgTpls
   *   Msg template details.
   * @param int $optionGroupId
   *   Option Group id.
   *
   * @return void
   *   Nothing.
   *
   * @throws CRM_Core_Exception
   */
  public function createMsgTpl(array $msgTpls, int $optionGroupId): void {
    $msgTplDefaults = [
      'is_active' => 1,
      'is_default' => 1,
      'is_reserved' => 0,
    ];

    $baseDir = CRM_Extension_System::singleton()->getMapper()->keyToBasePath('com.skvare.paymentstatement') . '/';
    foreach ($msgTpls as $i => $msgTpl) {
      $optionValue = civicrm_api3('OptionValue', 'create', [
        'description' => $msgTpl['description'],
        'is_active' => 1,
        'is_reserved' => 1,
        'label' => $msgTpl['label'],
        'name' => $msgTpl['name'],
        'option_group_id' => $optionGroupId,
        'value' => ++$i,
        'weight' => $i,
      ]);
      $html = file_get_contents($baseDir . 'msg_tpl/' . $msgTpl['name'] . '.html');

      $params = array_merge($msgTplDefaults, [
        'msg_title' => $msgTpl['label'],
        'msg_subject' => $msgTpl['subject'],
        'msg_html' => $html,
        'workflow_id' => $optionValue['id'],
      ]);
      civicrm_api3('MessageTemplate', 'create', $params);
    }

  }


  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   *
  public function upgrade_1100(): bool {
    //$this->ctx->log->info('Applying update 1100');
    //$this->installStatmentMsgWorkflowTpls();
    //return TRUE;
  }
  */

}
