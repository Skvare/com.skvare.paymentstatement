<?php

use CRM_Paymentstatement_ExtensionUtil as E;

class CRM_Paymentstatement_Utils {
  var $_pdfFormat = [];
  var $_type = '';
  var $_period = '';
  var $_periodText = '';
  var $_paymentStartDate = '';
  var $_paymentEndDate = '';
  var $_frequency = '';
  var $_intitParams = [];

  /**
   * @param string $type
   *   Payment Statement Type (year, month).
   * @param string $period
   * @return void
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function generatePaymentStatement($type = 'year', $period = 'this.year') {
    [$from, $to] = CRM_Utils_Date::getFromTo($period, '', '');
    $this->_type = $type;
    if ($type == 'year') {
      $this->_frequency = 'Yearly';
      $this->_type = 'year';
      $this->_period = date('Y', strtotime($from));
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    else {
      $this->_frequency = 'Monthly';
      $this->_type = 'month';
      $this->_period = date('F', strtotime($from));
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    $this->_intitParams = [
      'frequency' => $this->_frequency,
      'type' => $this->_type,
      'period' => $this->_period,
      'paymentStartDate' => $this->_paymentStartDate,
      'paymentEndDate' => $this->_paymentEndDate,
    ];
    // Get all contributions for the current year
    $currentYear = date('Y');
    $contributions = \Civi\Api4\Contribution::get(TRUE)
      ->addSelect('id', 'contact_id', 'total_amount', 'receive_date')
      ->addJoin('Contact AS contact', 'INNER')
      //->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('total_amount', '>', 0)
      ->addWhere('receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addOrderBy('contact_id', 'ASC')
      ->addOrderBy('receive_date', 'ASC')
      ->setLimit(0)
      ->execute()->getArrayCopy();
    $contributionsByContacts = $contributionsSumByContacts = [];
    foreach ($contributions as $contribution) {
      $contributionsByContacts[$contribution['contact_id']][$contribution['id']] = $contribution;
      $contributionsSumByContacts[$contribution['contact_id']]['total_amount'] += $contribution['total_amount'];
    }

    $contributionSofts = \Civi\Api4\ContributionSoft::get(TRUE)
      ->addSelect('amount', 'contribution.receive_date', 'contribution.total_amount', 'contact_id')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addJoin('Contact AS contact', 'INNER', ['contribution.contact_id', '=', 'contact.id'])
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->addWhere('contribution.is_test', '=', FALSE)
      ->addWhere('contribution.total_amount', '>', 0)
      ->addWhere('contribution.receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->setLimit(0)
      ->execute()->getArrayCopy();
    foreach ($contributionSofts as $contributionSoft) {
      $contributionSoft['total_amount'] = $contributionSoft['amount'];
      $contributionSoft['receive_date'] = $contributionSoft['contribution.receive_date'];
      $contributionsByContacts[$contributionSoft['contact_id']][] = $contributionSoft;
      $contributionsSumByContacts[$contributionSoft['contact_id']]['total_amount'] += $contributionSoft['total_amount'];
    }
    // Now check any contact have household contact, if present then get
    // household contact contribution and soft credit of household.
    // Get All contacts ids
    $allContactIds = array_keys($contributionsByContacts);
    $allContactIds = array_combine($allContactIds, $allContactIds);
    foreach ($allContactIds as $contactId) {
      [$houseHoldContactID, $houseHoldContributionsByContact, $houseHoldContributionsSumByContact] = $this->getRelatedHouseHold($contactId);
      if ($houseHoldContactID && !empty($houseHoldContributionsByContact)) {
        unset($contributionsByContacts[$contactId]);
        unset($contributionsSumByContacts[$contactId]);
        $contributionsByContacts[$houseHoldContactID] = $houseHoldContributionsByContact[$houseHoldContactID];
        $contributionsSumByContacts[$houseHoldContactID] = $houseHoldContributionsByContact[$houseHoldContactID];
        unset($allContactIds[$contactId]);
        $allContactIds[$houseHoldContactID] = $houseHoldContactID;
      }
    }
    //CRM_Core_Error::debug_var('$allContactIds', $allContactIds);
    // Get emails details those contact whose email is available.
    $contactEmails = $this->getContactEmails($allContactIds);
    //CRM_Core_Error::debug_var('$contactEmails', $contactEmails);
    // Get contact ids for which email only.
    $contactIDswithEmail = array_keys($contactEmails);
    //CRM_Core_Error::debug_var('$contactIDswithEmail', $contactIDswithEmail);
    // Get Contact whose email is not availble.
    $contactIDsForDownloadFile = array_diff($allContactIds, $contactIDswithEmail);
    // CRM_Core_Error::debug_var('$contactIDsForDownloadFile',$contactIDsForDownloadFile);
    $this->_pdfFormat = CRM_Core_BAO_MessageTemplate::getPDFFormatForTemplate('contribution_invoice_receipt');
    // First send email to contact who has email.
    foreach ($contactIDswithEmail as $contactId) {
      $contribution = $contributionsByContacts[$contactId];
      $html = $this->generatePdfStatementForContact('email', $contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
      $this->generateEmailforPayment('email', $contactId, $html);
    }
    // Generate PDF for contact who has no email.
    $htmlArray = [];

    foreach ($contactIDsForDownloadFile as $contactId) {
      $contribution = $contributionsByContacts[$contactId];
      $htmlArray[$contactId] = $this->generatePdfStatementForContact('file', $contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
    }
    if (!empty($htmlArray)) {
      $this->generateEmailforPayment('file', NULL, $htmlArray);
    }
  }

  /**
   * @param $contactID
   *   Individual Contact ID.
   * @return void
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getRelatedHouseHold($contactID) {
    $relationshipCaches = \Civi\Api4\RelationshipCache::get(TRUE)
      ->addSelect('far_contact_id')
      ->addWhere('near_relation', 'IN', ['Head of Household for', 'Household Member of'])
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('near_contact_id', '=', $contactID)
      ->setLimit(0)
      ->execute();
    $contributionsByContacts = $contributionsSumByContacts = [];
    foreach ($relationshipCaches as $relationshipCache) {
      [$contributionsByContacts, $contributionsSumByContacts] = $this->getContactContributionRecord($relationshipCache['far_contact_id']);
    }
    return [$relationshipCache['far_contact_id'], $contributionsByContacts, $contributionsSumByContacts];
  }

  /**
   * Get Contribution Record for Contact.
   * @param int $contactID
   *   Mostly household contact ID.
   * @return array[]
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getContactContributionRecord($contactID) {
    $contributions = \Civi\Api4\Contribution::get(TRUE)
      ->addSelect('id', 'contact_id', 'total_amount', 'receive_date')
      //->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('total_amount', '>', 0)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addOrderBy('contact_id', 'ASC')
      ->addOrderBy('receive_date', 'ASC')
      ->setLimit(0)
      ->execute()->getArrayCopy();
    $contributionsByContacts = $contributionsSumByContacts = [];
    foreach ($contributions as $contribution) {
      $contributionsByContacts[$contribution['contact_id']][$contribution['id']] = $contribution;
      $contributionsSumByContacts[$contribution['contact_id']]['total_amount'] += $contribution['total_amount'];
    }

    $contributionSofts = \Civi\Api4\ContributionSoft::get(TRUE)
      ->addSelect('amount', 'contribution.receive_date', 'contribution.total_amount', 'contact_id')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addWhere('contribution.is_test', '=', FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('contribution.total_amount', '>', 0)
      ->addWhere('contribution.receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->setLimit(0)
      ->execute()->getArrayCopy();
    foreach ($contributionSofts as $contributionSoft) {
      $contributionSoft['total_amount'] = $contributionSoft['amount'];
      $contributionSoft['receive_date'] = $contributionSoft['contribution.receive_date'];
      $contributionsByContacts[$contributionSoft['contact_id']][] = $contributionSoft;
      $contributionsSumByContacts[$contributionSoft['contact_id']]['total_amount'] += $contributionSoft['total_amount'];
    }
    return [$contributionsByContacts, $contributionsSumByContacts];
  }

  /**
   * Generate Email for Payment Statement.
   *
   * @param string $type
   * @param int $contactId
   * @param string $html
   * @return void
   * @throws CRM_Core_Exception
   */
  private function generateEmailforPayment($type = 'file', $contactId = NULL, $html) {
    $pdfFileName = $this->_frequency . '_Statement_' . $this->_type . '.pdf';
    $email = NULL;
    if ($contactId) {
      $email = CRM_Contact_BAO_Contact::getPrimaryEmail($contactId);
    }
    $sendTemplateParams = [
      'workflow' => 'payment_statement_email',
      'tplParams' => array_merge([
      ], $this->_intitParams),
      'PDFFilename' => $pdfFileName,
      'tokenContext' => ['contactId' => $contactId],
      'modelProps' => [
        //'userEnteredText' => $this->getSubmittedValue('receipt_text'),
        'contactID' => $contactId,
      ],
    ];
    $fromEmailAddress = 'system@skvare.com';
    $sendTemplateParams['from'] = $fromEmailAddress;
    $sendTemplateParams['toEmail'] = $email ?? 'noemail@skvare.com';
    if (empty($sendTemplateParams['attachments'])) {
      $sendTemplateParams['attachments'] = [];
    }

    $paymentStatementPdfFile = CRM_Utils_Mail::appendPDF($pdfFileName, $html, $this->_pdfFormat);
    $sendTemplateParams['attachments'][] = $paymentStatementPdfFile;
    [$sent, $subject, $message, $html] = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
  }

  /**
   * Generate PDF Statement for Contact.
   *
   * @param string $type
   *   File or Email.
   * @param int $contactId
   *   Contact ID.
   * @param array $contributions
   *   List of contribution record.
   * @param int $totalAmount
   *   Total amount.
   *
   * @return mixed
   *
   * @throws CRM_Core_Exception
   */
  private function generatePdfStatementForContact($type = 'file', $contactId, $contributions, $totalAmount) {
    // Fetch contact details
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactId]);
    $address  = CRM_Core_BAO_Address::getValues(['contact_id' => $contactId], TRUE);
    $address = reset($address);
    $addressDisplay = $address['display_text'];
    if (!empty($addressDisplay)) {
      $addressDisplay = nl2br($addressDisplay);
    }

    $sendTemplateParams = [
      'workflow' => 'payment_statement',
      'tplParams' => array_merge([
        'contact' => $contact,
        'contributions' => $contributions,
        'totalAmount' => $totalAmount,
        'address' => $addressDisplay,
      ], $this->_intitParams),
      'tokenContext' => ['contactId' => $contactId],
      'modelProps' => [
        //'userEnteredText' => $this->getSubmittedValue('receipt_text'),
        'contactID' => $contactId,
      ],
    ];
    [$sent, $subject, $message, $html] = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
    if ($type == 'file') {
      return $html;
    }
    return $html;

    // Create Activity for Payment Printing..., Attached PDF to Activity.
    $activityParams = [
      'source_contact_id' => $contactID,
      'target_contact_id' => $contactID,
      'activity_type_id' => "payment_statement",
      'source_record_id' => $campaignID,
      'status_id' => "Completed",
      'subject' => 'Payment Statement for ' . $displayName,
      'details' => CRM_Utils_Array::value('html', $html),
      'activity_date_time' => date('YmdHis'),
    ];

    $userContactId = CRM_Core_Session::getLoggedInContactID();
    if ($userContactId) {
      $activityParams['source_contact_id'] = $userContactId;
    }
    try {
      //$result = civicrm_api3('activity', 'create', $activityParams);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_var('FAILED : print activity Result', $e->getMessage(), FALSE, TRUE, 'com.skvare.paymentstatement');
    }
  }

  /**
   * Get Contact Email.
   *
   * @param array $allContactIds
   *   List of contact ids.
   * @param bool $polite
   *   Check for polite email.
   *
   * @return array
   * 
   * @throws \Civi\Core\Exception\DBQueryException
   */
  private function getContactEmails($allContactIds, $polite = TRUE) {
    $allContactIds = implode(',', $allContactIds);
    $query = "
    SELECT c.id, e.email as email, c.first_name as first_name, c.last_name as last_name, c.contact_type, c.household_name
      FROM civicrm_contact c
        LEFT JOIN civicrm_email e ON ( c.id = e.contact_id )
    WHERE e.is_primary = 1
      AND c.id IN ($allContactIds)";
    if ($polite) {
      $query .= '
      AND c.do_not_email = 0
      AND e.on_hold = 0';
    }
    $dao = CRM_Core_DAO::executeQuery($query);
    $contactIdsWithEmail = [];
    while ($dao->fetch()) {
      $contactIdsWithEmail[$dao->id] = [
        'email' => $dao->email,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'contact_type' => $dao->contact_type,
        'household_name' => $dao->household_name,
      ];
    }
    return $contactIdsWithEmail;
  }

}
