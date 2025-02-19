<?php

use CRM_Paymentstatement_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Paymentstatement_Form_Settings extends CRM_Core_Form {

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {

    // add form elements
    $attribute = ['rows' => 100, 'cols' => 100, 'class' => 'collapsed'];
    $this->add('text', 'paymentstatement_logo', 'Logo URL/Path', ['size' => 100,
      'maxlength' => 100,], FALSE);
    $this->addEntityRef('paymentstatement_contact_id', ts('Log Activity Againt this contact for shared Email PDF'), ['create' => TRUE, 'api' => ['extra' => ['email']]], TRUE);
    $this->add('text', 'paymentstatement_default_email', 'Default Email (if contact does not have email)',
      ['size' => 100, 'maxlength' => 100,], FALSE);
    $this->add('textarea', 'paymentstatement_custom_css', 'CSS Block', [], FALSE);
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    // use settings as defined in default domain
    $domainID = CRM_Core_Config::domainID();
    $settings = Civi::settings($domainID);
    $setDefaults = [];
    foreach ($this->getRenderableElementNames() as $elementName) {
      $setDefaults[$elementName] = $settings->get($elementName);
    }
    $this->setDefaults($setDefaults);
    parent::buildQuickForm();
  }

  public function postProcess(): void {
    $values = $this->exportValues();

    // use settings as defined in default domain
    $domainID = CRM_Core_Config::domainID();
    $settings = Civi::settings($domainID);

    foreach ($values as $k => $v) {
      if (strpos($k, 'paymentstatement_') === 0) {
        $settings->set($k, $v);
      }
    }
    CRM_Core_Session::setStatus(E::ts('Setting updated successfully'));
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
