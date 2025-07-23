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
  var $_from = '';
  var $_to = '';
  var $_totalFrom = '';
  var $_totalTo = '';

  /**
   * @param string $type
   *   Payment Statement Type (year, month).
   * @param string $period
   * @return string
   *   Return string.
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function generatePaymentStatement($type = 'year', $period = 'this.year') {
    [$from, $to] = CRM_Utils_Date::getFromTo($period, '', '');
    $this->_from = $from;
    $this->_to = $to;
    $this->_totalFrom = $from;
    $this->_totalTo = $to;
    if ($type == 'year') {
      $this->_frequency = 'Yearly';
      $this->_type = 'year';
      $this->_period = date('Y', strtotime($from));
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    elseif ($type == 'quarter') {
      $this->_frequency = 'Quarterly';
      $this->_type = 'quarter';
      $quarter = [
        1 => 'January - March',
        2 => 'April - June',
        3 => 'July - September',
        4 => 'October - December',
      ];
      $curMonth = date("m", strtotime($from));
      $curQuarter = ceil($curMonth / 3);
      $this->_period = date('Y', strtotime($from)) . ' - ' . $quarter[$curQuarter];
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    elseif ($type == 'week') {
      $this->_frequency = 'Weekly';
      $this->_type = 'week';
      $weekNumberOfMonth = ceil(date("j", strtotime($from)) / 7);
      $this->_period = date('F', strtotime($from)) . ' Week ' . $weekNumberOfMonth;
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    elseif ($this == 'fiscal_year') {
      $this->_frequency = 'Fiscal Year';
      $this->_type = 'fiscal year';
      $this->_period = date('F', strtotime($from));
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    else {
      $this->_frequency = 'Monthly';
      $this->_type = 'month';
      $this->_period = date('Y', strtotime($from)) . ' - ' . date('F', strtotime($from));
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
      $this->_totalFrom = date('Ymd000000', strtotime(date('Y-01-01')));
      $this->_totalTo = $to;
    }

    $this->_intitParams = [
      'frequency' => $this->_frequency,
      'type' => $this->_type,
      'period' => $this->_period,
      'paymentStartDate' => $this->_paymentStartDate,
      'paymentEndDate' => $this->_paymentEndDate,
      'currentDate' => date('l, F j, Y'),
    ];
    $settings = self::getSettings();
    if (!empty($settings['paymentstatement_default_email'])) {
      $this->_intitParams['paymentstatement_default_email'] = $settings['paymentstatement_default_email'];
    }
    if (!empty($settings['paymentstatement_default_email_cc'])) {
      $this->_intitParams['paymentstatement_default_email_cc'] = $settings['paymentstatement_default_email_cc'];
    }
    $totalRecordAttempted = $processed = $skipped = 0;
    $domainValues = CRM_Core_BAO_Domain::getNameAndEmail();
    $this->_intitParams['from'] = "$domainValues[0] <$domainValues[1]>";
    $relationshipTypes = $settings['paymentstatement_relationships'] ?? [];
    // Get all contributions for the current year
    $currentYear = date('Y');
    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'total_amount', 'receive_date', 'contact.contact_type')
      ->addJoin('Contact AS contact', 'INNER')
      ->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->addWhere('contact.is_deleted', '!=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('total_amount', '>', 0)
      ->addWhere('receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addOrderBy('contact_id', 'ASC')
      ->addOrderBy('receive_date', 'ASC')
      ->setLimit(0)
      ->execute()->getArrayCopy();

    $contributionsByContacts = $contributionsSumByContacts = [];
    foreach ($contributions as $contribution) {
      if (!array_key_exists($contribution['contact_id'], $contributionsByContacts)) {
        $contributionsByContacts[$contribution['contact_id']] = [];
      }
      $contributionsByContacts[$contribution['contact_id']][$contribution['id']] = $contribution;
    }
    // Get all contact ids.
    $allContactDirectIds = array_keys($contributionsByContacts);
    $paymentSumForDateRange = $this->getSumOfPayment($this->_totalFrom, $this->_totalTo, $allContactDirectIds);
    foreach ($paymentSumForDateRange as $paymentSum) {
      if (!array_key_exists($paymentSum['contact_id'], $contributionsSumByContacts)) {
        $contributionsSumByContacts[$paymentSum['contact_id']] = [];
        $contributionsSumByContacts[$paymentSum['contact_id']]['total_amount'] = 0;
      }
      $contributionsSumByContacts[$paymentSum['contact_id']]['total_amount'] += $paymentSum['total_Amount'];
    }

    CRM_Core_Error::debug_var('contributionsByContacts Direct', count($contributionsByContacts));

    /*
     // Comment this block as per https://projects.skvare.com/issues/24424#note-146
    $contributionSofts = \Civi\Api4\ContributionSoft::get(FALSE)
      ->addSelect('amount', 'contribution.receive_date', 'contribution.total_amount', 'contact_id', 'contact.contact_type')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addJoin('Contact AS contact', 'INNER', ['contact_id', '=', 'contact.id'])
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->addWhere('contact.is_deleted', '!=', TRUE)
      ->addWhere('contribution.is_test', '=', FALSE)
      ->addWhere('contribution.total_amount', '>', 0)
      ->addWhere('contribution.receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addWhere('contribution.contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->setLimit(0)
      ->execute()->getArrayCopy();
    CRM_Core_Error::debug_var('contributionSofts Direct', count($contributionSofts));
    $softCreditContacts = [];
    foreach ($contributionSofts as $contributionSoft) {
      $contributionSoft['total_amount'] = $contributionSoft['amount'];
      $contributionSoft['receive_date'] = $contributionSoft['contribution.receive_date'];
      $softCreditContacts[$contributionSoft['contact_id']] = $contributionSoft['contact_id'];
      if (!array_key_exists($contributionSoft['contact_id'], $contributionsByContacts)) {
        $contributionsByContacts[$contributionSoft['contact_id']] = [];
      }
      $contributionsByContacts[$contributionSoft['contact_id']][] = $contributionSoft;
    }

    // Get all contact ids.
    if (!empty($softCreditContacts)) {
      $paymentSoftCreditSumForDateRange = $this->getSumOfSoftCreditPayment($this->_totalFrom, $this->_totalTo, $softCreditContacts);
      foreach ($paymentSoftCreditSumForDateRange as $paymentSoftCreditSum) {
        if (!array_key_exists($paymentSoftCreditSum['contact_id'], $contributionsSumByContacts)) {
          $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']] = [];
          $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']]['total_amount'] = 0;
        }
        $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']]['total_amount'] += $paymentSoftCreditSum['total_Amount'];
      }
    }

    CRM_Core_Error::debug_var('$contributionsByContacts + Soft Credit : Direct', count($contributionsByContacts));
    */
    $allContactIds = array_keys($contributionsByContacts);
    // Get All contacts ids
    CRM_Core_Error::debug_var('allContactIds : Direct', count($allContactIds));
    $allContactIds = array_combine($allContactIds, $allContactIds);
    // Now check any contact have household contact, if present then get
    // household contact contribution and soft credit of household.

    CRM_Core_Error::debug_log_message('Check Relationship Details for each contact');
    if (!empty($relationshipTypes)) {
      $relatedContactIds = [];
      foreach ($allContactIds as $contactId) {
        $relatedContacts = $this->getRelatedHouseHold($contactId, $relationshipTypes);
        $unsetIndividualContact = FALSE;
        foreach ($relatedContacts as $relatedContactId) {
          [$houseHoldContributionsByContact, $houseHoldContributionsSumByContact] = $this->getContactContributionRecord($relatedContactId);
          if (!empty($houseHoldContributionsByContact)) {
            // Add household contact contribution contact.
            $unsetIndividualContact = TRUE;
            $contributionsByContacts[$relatedContactId] = $houseHoldContributionsByContact[$relatedContactId];
            $contributionsSumByContacts[$relatedContactId] = $houseHoldContributionsSumByContact[$relatedContactId];
            $relatedContactIds[$relatedContactId] = $relatedContactId;
          }
          if ($unsetIndividualContact) {
            unset($contributionsByContacts[$contactId]);
            unset($contributionsSumByContacts[$contactId]);
            unset($allContactIds[$contactId]);
          }
        }
      }
      if (!empty($relatedContactIds)) {
        $allContactIds = array_merge($allContactIds, $relatedContactIds);
      }
    }

    CRM_Core_Error::debug_var('After relationship check allContactIds count', count($allContactIds));
    // Get emails details those contact whose email is available.
    $contactEmails = $this->getContactEmails($allContactIds);
    // Get contact ids for which email only.
    $contactIDswithEmail = array_keys($contactEmails);
    CRM_Core_Error::debug_var('After relationship check contactIDswithEmail count', count($contactIDswithEmail));
    // Get Contact whose email is not available.
    $contactIDsForDownloadFile = array_diff($allContactIds, $contactIDswithEmail);
    $contactIDsForDownloadFile = array_values($contactIDsForDownloadFile);
    $contactIDsForDownloadFile = array_combine($contactIDsForDownloadFile, $contactIDsForDownloadFile);
    $this->_pdfFormat = CRM_Core_BAO_MessageTemplate::getPDFFormatForTemplate('contribution_invoice_receipt');
    CRM_Core_Error::debug_var('After array diff contactIDswithEmail Count', count($contactIDswithEmail));
    // First send email to contact who has email.
    $skippedContactIDs = [];

    // Sort the contribution by date.
    foreach ($contributionsByContacts as &$contributionsToSort) {
      usort($contributionsToSort, function ($a, $b) {
        $t1 = strtotime($a['receive_date']);
        $t2 = strtotime($b['receive_date']);
        return $t1 - $t2;
      });
    }

    CRM_Core_Error::debug_log_message('Iterate contact with email address only and create a PDF and email activity.');
    foreach ($contactIDswithEmail as $contactId) {
      $totalRecordAttempted++;
      // If activity exist, no need to re-create again.
      if ($this->checkPdfActivityExist($contactId)) {
        $skipped++;
        $skippedContactIDs[$contactId] = $contactId;
        continue;
      }
      $processed++;
      $contribution = $contributionsByContacts[$contactId];
      [$html, $paymentStatementPdfFile, $activityPdfResult] = $this->generatePdfStatementForContact($contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
      $this->generateEmailforPayment('email', $contactId, $html, $activityPdfResult, $paymentStatementPdfFile);
    }
    CRM_Core_Error::debug_var('Processed count with email', $processed);
    CRM_Core_Error::debug_var('Skipped count with email', $skipped);
    /*
    CRM_Core_Error::debug_var('Skipped contact with email', $skippedContactIDs);
    */

    CRM_Core_Error::debug_log_message('Iterate contact with without email, create a PDF and email activity.');
    CRM_Core_Error::debug_var('contactIDsForDownloadFile', count($contactIDsForDownloadFile));
    $contactGetEmailFromHeadOfHouseHold = [];
    foreach ($contactIDsForDownloadFile as $contactId) {
      $totalRecordAttempted++;
      $contribution = $contributionsByContacts[$contactId];
      // If activity exist, no need to re-create again.
      if ($this->checkPdfActivityExist($contactId)) {
        $skipped++;
        $skippedContactIDs[$contactId] = $contactId;
        $contactType = CRM_Contact_BAO_Contact::getContactType($contactId);
        if ($contactType == 'Household') {
          $headOfHouseHoldContactIds = $this->getHeadOfHouseHold($contactId);
          if (!empty($headOfHouseHoldContactIds)) {
            unset($contactIDsForDownloadFile[$contactId]);
          }
        }
        continue;
      }
      $processed++;

      [$html, $paymentStatementPdfFile, $activityPdfResult] = $this->generatePdfStatementForContact($contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
      $contactType = CRM_Contact_BAO_Contact::getContactType($contactId);
      if ($contactType == 'Household' && !empty($paymentStatementPdfFile)) {
        $headOfHouseHoldContactIds = $this->getHeadOfHouseHold($contactId);
        if (!empty($headOfHouseHoldContactIds)) {
          $contactGetEmailFromHeadOfHouseHold[$contactId] = $headOfHouseHoldContactIds;
          $this->generateEmailforPayment('email', $contactId, $html, $activityPdfResult, $paymentStatementPdfFile, $headOfHouseHoldContactIds);
          unset($contactIDsForDownloadFile[$contactId]);
        }
      }
    }
    CRM_Core_Error::debug_var('Processed count after NO email', $processed);
    CRM_Core_Error::debug_var('Skipped count after NO email', $skipped);
    /*
    CRM_Core_Error::debug_var('Skipped contact after NO email', $skippedContactIDs);
    */
    CRM_Core_Error::debug_var('contactGetEmailFromHeadOfHouseHold count', count($contactGetEmailFromHeadOfHouseHold));
    //CRM_Core_Error::debug_var('contactGetEmailFromHeadOfHouseHold', $contactGetEmailFromHeadOfHouseHold);
    $sharedContactID = $settings['paymentstatement_contact_id'] ?? NULL;
    // Generate PDF for contact who has no email.
    $htmlArray = [];
    $sharedActivityExist = 'No';
    if (!empty($sharedContactID)) {
      // unset contact id for which email is available.
      CRM_Core_Error::debug_log_message('Unset the contact ID for which email is available through the relationship.');
      foreach ($contactIDsForDownloadFile as $contactId) {
        if (array_key_exists($contactId, $contactGetEmailFromHeadOfHouseHold)) {
          unset($contactIDsForDownloadFile[$contactId]);
        }
      }
      CRM_Core_Error::debug_var('contactIDsForDownloadFile Count after unset', count($contactIDsForDownloadFile));
      // If activity exist, no need to re-create again.
      if (!empty($contactIDsForDownloadFile) && !$this->checkPdfActivityExist($sharedContactID, 'shared')) {
        CRM_Core_Error::debug_log_message('Generate Shared PDF HTML');
        // Created activity to shared pdf.
        foreach ($contactIDsForDownloadFile as $contactId) {
          $contribution = $contributionsByContacts[$contactId];
          [$html, $paymentStatementPdfFile] = $this->generatePdfStatementForContact($contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount'], TRUE);
          $htmlArray[$contactId] = $html;
        }
        CRM_Core_Error::debug_log_message('Generate Shared PDF file and activity');
        [$paymentStatementPdfFileShared, $activityPdfResult] = $this->createPDfActivity($sharedContactID, $htmlArray, 'shared');
        CRM_Core_Error::debug_log_message('Generated Shared Email');
        $this->generateEmailforPayment('shared', $sharedContactID, $htmlArray, $activityPdfResult, $paymentStatementPdfFileShared);
        $sharedActivityExist = 'Yes';
      }
      else {
        CRM_Core_Error::debug_log_message('Common PDF not generated');
      }
    }
    CRM_Core_Error::debug_log_message('Process completed...');
    return "Number of record processed $totalRecordAttempted, Created $processed, Skipped $skipped, Shared PDF Activity created: $sharedActivityExist";
  }


  private function getSumOfPayment($from, $to, $contactIDs = []) {
    $contributionSums = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contact_id', 'SUM(total_amount) AS total_Amount')
      ->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->addWhere('contact_id', 'IN', $contactIDs)
      ->addWhere('is_test', '=', FALSE)
      ->addWhere('total_amount', '>', 0)
      ->addWhere('receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addGroupBy('contact_id')
      ->setLimit(0)
      ->execute()->getArrayCopy();

    return $contributionSums;
  }

  private function getSumOfSoftCreditPayment($from, $to, $contactIDs = []) {
    $contributionSoftsSums = \Civi\Api4\ContributionSoft::get(FALSE)
      ->addSelect('contact_id', 'SUM(amount) AS total_Amount')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addJoin('Contact AS contact', 'INNER', ['contact_id', '=', 'contact.id'])
      ->addWhere('contact_id', 'IN', $contactIDs)
      ->addWhere('contact.is_deleted', '!=', TRUE)
      ->addWhere('contribution.is_test', '=', FALSE)
      ->addWhere('contribution.total_amount', '>', 0)
      ->addWhere('contribution.receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addWhere('contribution.contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->addGroupBy('contact_id')
      ->setLimit(0)
      ->execute()->getArrayCopy();
    return $contributionSoftsSums;
  }

  private function checkPdfActivityExist($contactId, $type = 'email') {
    $subject = $this->_frequency . ' Statement ' . $this->_period;
    if ($type == 'shared') {
      $subject = 'Common: ' . $subject;
    }
    $result = civicrm_api3('Activity', 'getcount', [
      'activity_type_id' => "Print PDF Letter",
      'subject' => $subject,
      'source_contact_id' => $contactId,
    ]);
    if ($result) {
      return TRUE;
    }
    return FALSE;
  }

  private function checkEmailActivityExist($contactId, $type = 'email') {
    $subject = $this->_frequency . ' Statement ' . $this->_period;
    if ($type == 'shared') {
      $subject = 'Common: ' . $subject;
    }
    $result = civicrm_api3('Activity', 'getcount', [
      'activity_type_id' => "Email",
      'subject' => $subject,
      'source_contact_id' => $contactId,
    ]);
    if ($result) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param $contactID
   *   Individual Contact ID.
   * @return $relatedContacts
   *   Contact list.
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getRelatedHouseHold($contactID, $relationshipTypes = []) {
    $relationshipCaches = \Civi\Api4\RelationshipCache::get(FALSE)
      ->addSelect('far_contact_id')
      ->addWhere('relationship_type_id', 'IN', $relationshipTypes)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('near_contact_id', '=', $contactID)
      ->setLimit(0)
      ->execute()->getArrayCopy();
    $relatedContacts = [];
    foreach ($relationshipCaches as $relationshipCache) {
      if (!empty($relationshipCache['far_contact_id'])) {
        $isDeleted = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $relationshipCache['far_contact_id'], 'is_deleted', 'id');
        // if contact is deleted then do not go further.
        if ($isDeleted) {
          continue;
        }
        $relatedContacts[$relationshipCache['far_contact_id']] = $relationshipCache['far_contact_id'];
      }
    }
    return $relatedContacts;
  }

  private function getHeadOfHouseHold($contactID) {
    $relationshipCaches = \Civi\Api4\RelationshipCache::get(FALSE)
      ->addSelect('far_contact_id')
      ->addWhere('near_contact_id', '=', $contactID)
      ->addWhere('relationship_type_id', '=', 7)
      ->addWhere('is_active', '=', TRUE)
      ->setLimit(25)
      ->execute();
    $headHouseHold = [];
    foreach ($relationshipCaches as $relationshipCache) {
      $emailPrimary = CRM_Contact_BAO_Contact::getPrimaryEmail($relationshipCache['far_contact_id']);
      if ($emailPrimary) {
        $headHouseHold[] = $relationshipCache['far_contact_id'];
      }
    }
    $headHouseHold = array_values(array_unique($headHouseHold));
    return $headHouseHold;
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
    $from = $this->_from;
    $to = $this->_to;
    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'total_amount', 'receive_date', 'contact.contact_type')
      ->addJoin('Contact AS contact', 'INNER', ['contact_id', '=', 'contact.id'])
      ->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
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
      if (!array_key_exists($contribution['contact_id'], $contributionsByContacts)) {
        $contributionsByContacts[$contribution['contact_id']] = [];
      }
      $contributionsByContacts[$contribution['contact_id']][$contribution['id']] = $contribution;
    }
    $relatedContactIds = [$contactID];

    $paymentSumForDateRange = $this->getSumOfPayment($this->_totalFrom, $this->_totalTo, $relatedContactIds);
    foreach ($paymentSumForDateRange as $paymentSum) {
      if (!array_key_exists($paymentSum['contact_id'], $contributionsSumByContacts)) {
        $contributionsSumByContacts[$paymentSum['contact_id']] = [];
        $contributionsSumByContacts[$paymentSum['contact_id']]['total_amount'] = 0;
      }
      $contributionsSumByContacts[$paymentSum['contact_id']]['total_amount'] += $paymentSum['total_Amount'];
    }
    $contributionSofts = \Civi\Api4\ContributionSoft::get(FALSE)
      ->addSelect('amount', 'contribution.receive_date', 'contribution.total_amount', 'contact_id' ,'contact.contact_type')
      ->addJoin('Contribution AS contribution', 'INNER')
      ->addJoin('Contact AS contact', 'INNER', ['contact_id', '=', 'contact.id'])
      ->addWhere('contribution.is_test', '=', FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('contribution.total_amount', '>', 0)
      ->addWhere('contribution.receive_date', 'BETWEEN', ["{$from}", "{$to}"])
      ->addWhere('contribution.contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->setLimit(0)
      ->execute()->getArrayCopy();

    $softCreditContacts = [];
    foreach ($contributionSofts as $contributionSoft) {
      $contributionSoft['total_amount'] = $contributionSoft['amount'];
      $contributionSoft['receive_date'] = $contributionSoft['contribution.receive_date'];
      $softCreditContacts[$contributionSoft['contact_id']] = $contributionSoft['contact_id'];
      if (!array_key_exists($contributionSoft['contact_id'], $contributionsByContacts)) {
        $contributionsByContacts[$contributionSoft['contact_id']] = [];
      }
      $contributionsByContacts[$contributionSoft['contact_id']][] = $contributionSoft;
    }
    // Get all contact ids.
    if (!empty($softCreditContacts)) {
      $paymentSoftCreditSumForDateRange = $this->getSumOfSoftCreditPayment($this->_totalFrom, $this->_totalTo, $relatedContactIds);
      foreach ($paymentSoftCreditSumForDateRange as $paymentSoftCreditSum) {
        if (!array_key_exists($paymentSoftCreditSum['contact_id'], $contributionsSumByContacts)) {
          $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']] = [];
          $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']]['total_amount'] = 0;
        }
        $contributionsSumByContacts[$paymentSoftCreditSum['contact_id']]['total_amount'] += $paymentSoftCreditSum['total_Amount'];
      }
    }
    return [$contributionsByContacts, $contributionsSumByContacts];
  }

  /**
   * Generate Email for Payment Statement.
   * @param $type
   * @param $contactId
   * @param $html
   * @param $activityPdfResult
   * @param $paymentStatementPdfFile
   * @param $ccContactIds
   * @return void
   */
  private function generateEmailforPayment($type = 'shared', $contactId = NULL, $html = '', $activityPdfResult = [], $paymentStatementPdfFile = '', $ccContactIds = []) {
    static $sendCount = 0;
    static $totalEmailSent = 0;
    // Wait for 10ms before sending next email.
    usleep(10000);
    if ($sendCount >= 50) {
      $sendCount = 0;
      CRM_Core_Error::debug_log_message('Total Email sent: ' . $totalEmailSent);
    }
    $pdfFileName = $this->_frequency . '_Statement_' . $this->_type . '_' . rand() . '.pdf';
    $email = NULL;
    $sourceContactID = $contactId;
    $ccContactIdsOriginal = $ccContactIds;
    if ($ccContactIds) {
      $contactId = array_shift($ccContactIds);
    }
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
        'contactID' => $contactId,
      ],
    ];
    $sendTemplateParams['from'] = $this->_intitParams['from'];
    $sendTemplateParams['toEmail'] = $email ?? $this->_intitParams['paymentstatement_default_email'];
    // If email is share  then cc to default cc email.
    if ($type == 'shared' && !empty($this->_intitParams['paymentstatement_default_email_cc'])) {
      $sendTemplateParams['cc'] = $this->_intitParams['paymentstatement_default_email_cc'];
    }
    else {
      if (!empty($ccContactIds)) {
        $ccEmails = [];
        foreach ($ccContactIds as $ccContactId) {
          $ccEmails[] = CRM_Contact_BAO_Contact::getPrimaryEmail($ccContactId);
        }
        $sendTemplateParams['cc'] = implode(',', $ccEmails);
      }
    }
    if (empty($sendTemplateParams['attachments'])) {
      $sendTemplateParams['attachments'] = [];
    }
    if ($contactId && !empty($paymentStatementPdfFile)) {
      // $paymentStatementPdfFile = $paymentStatementPdfFile;
      // attachment file is already available.
    }
    else {
      $paymentStatementPdfFile = CRM_Utils_Mail::appendPDF($pdfFileName, $html, $this->_pdfFormat);
    }
    $sendTemplateParams['attachments'][] = $paymentStatementPdfFile;
    [$sent, $subject, $message, $html] = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
    $sendCount++;
    $totalEmailSent++;
    if ($sent && $contactId) {
      // Create Activity for Payment Printing..., Attached PDF to Activity.
      $subject = $this->_frequency . ' Statement ' . $this->_period;
      if ($type == 'shared') {
        $subject = 'Common: ' . $subject;
      }
      $activityDetail = '';
      if (!empty($html)) {
        preg_match("/<body[^>]*>(.*?)<\/body>/is", $html, $matches);
        if (!empty($matches[1])) {
          $activityDetail = $matches[1];
        }
      }
      $activityParams = [
        'subject' => $subject,
        'source_contact_id' => $sourceContactID,
        'target_contact_id' => $contactId,
        'activity_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Email'),
        'activity_date_time' => date('YmdHis'),
        'details' => $activityDetail,
      ];
      // Keep all Head of house as target contact.
      if (!empty($ccContactIdsOriginal)) {
        $activityParams['target_contact_id'] = $ccContactIdsOriginal;
      }
      try {
        $result = civicrm_api3('activity', 'create', $activityParams);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_var('FAILED : print activity Result', $e->getMessage(), FALSE, TRUE, 'com.skvare.paymentstatement');
      }
    }
    else {
      CRM_Core_Error::debug_log_message("Email stopped");
      CRM_Core_Error::debug_log_message('Total Email sent: ' . $totalEmailSent);
      CRM_Core_Error::debug_var('sendTemplateParams', $sendTemplateParams);
      // delete pdf activity on the contact.
      // this is to make sure email sent with pdf attachment.
      if (!empty($activityPdfResult)) {
        $activityId = $activityPdfResult['id'];
        if ($activityId) {
          CRM_Core_Error::debug_log_message("Deleting PDF Activity ID {$activityId} for contact id {$sourceContactID}");
          civicrm_api3('Activity', 'delete', ['id' => $activityId]);
        }
      }
      exit;
    }
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
  private function generatePdfStatementForContact($contactId, $contributions, $totalAmount, $htmlReturn = FALSE) {
    // Fetch contact details
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactId]);
    $address = CRM_Core_BAO_Address::getValues(['contact_id' => $contactId], TRUE);
    $address = reset($address);
    if (is_array($address) && !empty($address['display_text'])) {
      $addressDisplay = $address['display_text'];
      if (!empty($addressDisplay)) {
        $addressDisplay = nl2br($addressDisplay);
      }
    }
    else {
      $addressDisplay = 'No Address';
    }
    $settings = self::getSettings();
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
        'contactID' => $contactId,
      ],
    ];
    if (!empty($settings['paymentstatement_logo'])) {
      $headerImagePath = '';
      if (strpos($settings['paymentstatement_logo'], 'http') === 0) {
        $headerImagePath = $settings['paymentstatement_logo'];
      }
      elseif (file_exists($settings['paymentstatement_logo'])) {
        $headerImagePath = self::imageEncodeBase64($headerImagePath);
      }
      $sendTemplateParams['tplParams']['headerImage'] = $headerImagePath;
    }

    [$sent, $subject, $message, $html] = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
    if ($htmlReturn) {
      return [$html, [], []];
    }

    [$paymentStatementPdfFile, $activityPdfResult] = $this->createPDfActivity($contactId, $html);

    return [$html, $paymentStatementPdfFile, $activityPdfResult];
  }

  /**
   * Create PDF activity.
   *
   * @param $contactId
   * @param $html
   * @param $type
   * @return array|array[]
   */
  private function createPDfActivity($contactId, $html, $type = 'email') {
    $subject = $this->_frequency . ' Statement ' . $this->_period;
    if ($type == 'shared') {
      $subject = 'Common: ' . $subject;
    }
    $activityDetail = $html;
    $paymentStatementPdfFile = $activityResult = [];
    if (!empty($html)) {
      if ($type == 'shared') {
        $activityDetail = 'Shared PDF';
      }
      else {
        preg_match("/<body[^>]*>(.*?)<\/body>/is", $html, $matches);
        if (!empty($matches[1])) {
          $activityDetail = $matches[1];
        }
      }

      $activityParams = [
        'subject' => $subject,
        'source_contact_id' => $contactId,
        'target_contact_id' => $contactId,
        'activity_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Print PDF Letter'),
        'activity_date_time' => date('YmdHis'),
        'details' => $activityDetail,
      ];

      $pdfFileName = $this->_frequency . '_Statement_' . $this->_type . '_' . rand() . '.pdf';
      $paymentStatementPdfFile = CRM_Utils_Mail::appendPDF($pdfFileName, $html, $this->_pdfFormat);

      // Attach pdf file to activity
      if (!empty($paymentStatementPdfFile)) {
        $config = CRM_Core_Config::singleton();
        // make file name unique, in case of re-printing, file should not be overwrite.
        $name = "Payment_" . $this->_frequency . '_Statement_' . $this->_type . '_' . rand() . ".pdf";

        $fileName = $config->uploadDir . $name;

        // copy file from temporary location to upload directory.
        copy($paymentStatementPdfFile['fullPath'], $fileName);

        $activityParams['attachFile_1'] = [
          'uri' => $fileName,
          'type' => 'application/pdf',
          'location' => $fileName,
          'upload_date' => date('YmdHis'),
          'description' => 'Payment Statement PDF File',
        ];
      }
      try {
        $activityResult = civicrm_api3('activity', 'create', $activityParams);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_var('FAILED : print activity Result', $e->getMessage(), FALSE, TRUE, 'com.skvare.paymentstatement');
      }

      return [$paymentStatementPdfFile, $activityResult];
    }
    return [$paymentStatementPdfFile, $activityResult];
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

  /**
   * Function to return global setting for.
   *
   * @return array
   */
  public static function getSettings() {
    $domainID = CRM_Core_Config::domainID();
    $settings = Civi::settings($domainID);
    $mainSettings = [];
    $elementNames = ['paymentstatement_logo', 'paymentstatement_default_email_cc', 'paymentstatement_default_email', 'paymentstatement_contact_id', 'paymentstatement_relationships'];
    foreach ($elementNames as $elementName) {
      $mainSettings[$elementName] = $settings->get($elementName);
    }

    return $mainSettings;
  }

  /**
   * Image Base 64.
   *
   * @param string $filePath
   *   File path.
   *
   * @return string
   *   Image Data.
   */
  public static function imageEncodeBase64(string $filePath): string {
    if (file_exists($filePath)) {
      $imageMeta = getimagesize($filePath);
      $data = file_get_contents($filePath);
      $base64 = base64_encode($data);
      $base64 = preg_replace('/\s+/', '', $base64);
      return "data:{$imageMeta['mime']};base64," . rawurlencode($base64);
    }
    return '';
  }

  /**
   * Get Relationship types.
   *
   * @return array
   *   relationship type list.
   *
   * @throws CRM_Core_Exception
   */
  public static function relationshipTypes() {
    $result = civicrm_api3('RelationshipType', 'get', [
      'sequential' => 1,
      'is_active' => 1,
      'options' => ['limit' => 0],
    ]);


    $relationshipTypes = [];
    foreach ($result['values'] as $type) {
      if ($type['label_a_b'] == $type['label_b_a']) {
        $relationshipTypes[$type['id']] = $type['label_a_b'];
      }
      else {
        $relationshipTypes[$type['id']] = $type['label_a_b'] . ' ( ' . $type['contact_type_a'] . ' ) ' . ' / ' .
          $type['label_b_a'] . ' ( ' . $type['contact_type_b'] . ' )';
      }
    }

    return $relationshipTypes;
  }

}
