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
   * @return string
   *   Return string.
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function generatePaymentStatement($type = 'year', $period = 'this.year') {
    [$from, $to] = CRM_Utils_Date::getFromTo($period, '', '');
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
      $this->_period = $quarter[$curQuarter];
      $this->_paymentStartDate = date('F j, Y', strtotime($from));
      $this->_paymentEndDate = date('F j, Y', strtotime($to));
    }
    elseif ($this == 'week') {
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
    $settings = self::getSettings();
    if (!empty($settings['paymentstatement_default_email'])) {
      $this->_intitParams['paymentstatement_default_email'] = $settings['paymentstatement_default_email'];
    }
    $totalRecordAttempted = $processed = $skipped = 0;
    $domainValues = CRM_Core_BAO_Domain::getNameAndEmail();
    $this->_intitParams['from'] = "$domainValues[0] <$domainValues[1]>";
    $relationshipTypes = $settings['paymentstatement_relationships'] ?? [];
    // Get all contributions for the current year
    $currentYear = date('Y');
    $contributions = \Civi\Api4\Contribution::get(TRUE)
      ->addSelect('id', 'contact_id', 'total_amount', 'receive_date')
      ->addJoin('Contact AS contact', 'INNER')
      ->addWhere('contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
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
      ->addWhere('contribution.contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
      ->setLimit(0)
      ->execute()->getArrayCopy();
    foreach ($contributionSofts as $contributionSoft) {
      $contributionSoft['total_amount'] = $contributionSoft['amount'];
      $contributionSoft['receive_date'] = $contributionSoft['contribution.receive_date'];
      $contributionsByContacts[$contributionSoft['contact_id']][] = $contributionSoft;
      $contributionsSumByContacts[$contributionSoft['contact_id']]['total_amount'] += $contributionSoft['total_amount'];
    }
    // Get All contacts ids
    $allContactIds = array_keys($contributionsByContacts);
    $allContactIds = array_combine($allContactIds, $allContactIds);
    // Now check any contact have household contact, if present then get
    // household contact contribution and soft credit of household.
    if (!empty($relationshipTypes)) {
      foreach ($allContactIds as $contactId) {
        [$houseHoldContactID, $houseHoldContributionsByContact,
          $houseHoldContributionsSumByContact] = $this->getRelatedHouseHold($contactId, $relationshipTypes);
        if ($houseHoldContactID && !empty($houseHoldContributionsByContact)) {
          // If household contact have contribution then remove individual contact.
          unset($contributionsByContacts[$contactId]);
          unset($contributionsSumByContacts[$contactId]);
          unset($allContactIds[$contactId]);
          // Add household contact contribution contact.
          $contributionsByContacts[$houseHoldContactID] = $houseHoldContributionsByContact[$houseHoldContactID];
          $contributionsSumByContacts[$houseHoldContactID] = $houseHoldContributionsByContact[$houseHoldContactID];
          $allContactIds[$houseHoldContactID] = $houseHoldContactID;
        }
      }
    }
    // Get emails details those contact whose email is available.
    $contactEmails = $this->getContactEmails($allContactIds);
    // Get contact ids for which email only.
    $contactIDswithEmail = array_keys($contactEmails);
    // Get Contact whose email is not availble.
    $contactIDsForDownloadFile = array_diff($allContactIds, $contactIDswithEmail);
    $this->_pdfFormat = CRM_Core_BAO_MessageTemplate::getPDFFormatForTemplate('contribution_invoice_receipt');

    // First send email to contact who has email.
    foreach ($contactIDswithEmail as $contactId) {
      $totalRecordAttempted++;
      // If activity exist, no need to re-create again.
      if ($this->checkPdfActivityExist($contactId)) {
        $skipped++;
        continue;
      }
      $processed++;
      $contribution = $contributionsByContacts[$contactId];
      [$html, $paymentStatementPdfFile] = $this->generatePdfStatementForContact($contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
      $this->generateEmailforPayment('email', $contactId, $html, $paymentStatementPdfFile);
    }
    // Generate PDF for contact who has no email.
    $htmlArray = [];
    foreach ($contactIDsForDownloadFile as $contactId) {
      $totalRecordAttempted++;
      $contribution = $contributionsByContacts[$contactId];
      // If activity exist, no need to re-create again.
      if ($this->checkPdfActivityExist($contactId)) {
        $skipped++;
        continue;
      }
      $processed++;
      [$html, $paymentStatementPdfFile] = $this->generatePdfStatementForContact($contactId, $contribution, $contributionsSumByContacts[$contactId]['total_amount']);
      $htmlArray[$contactId] = $html;
    }
    $sharedContactID = $settings['paymentstatement_contact_id'] ?? NULL;
    if (!empty($htmlArray)) {
      // If activity exist, no need to re-create again.
      if (!$this->checkPdfActivityExist($sharedContactID, 'shared')) {
        // Created actiivty to shared pdf.
        $paymentStatementPdfFileShared = $this->createPDfActivity($sharedContactID, $htmlArray, 'shared');
        $this->generateEmailforPayment('shared', $sharedContactID, $htmlArray, $paymentStatementPdfFileShared);
      }
    }
    return "Number of record processed $totalRecordAttempted, Created $processed, Skipped $skipped";
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

  /**
   * @param $contactID
   *   Individual Contact ID.
   * @return void
   * @throws CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private function getRelatedHouseHold($contactID, $relationshipTypes = []) {
    $relationshipCaches = \Civi\Api4\RelationshipCache::get(TRUE)
      ->addSelect('far_contact_id')
      ->addWhere('relationship_type_id', 'IN', $relationshipTypes)
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
      ->addWhere('contribution.contribution_status_id', 'IN', [1, 8]) // Completed,// Partial.
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
  private function generateEmailforPayment($type = 'shared', $contactId = NULL, $html = '', $paymentStatementPdfFile = '') {
    $pdfFileName = $this->_frequency . '_Statement_' . $this->_type . '_' . rand() . '.pdf';
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
        'contactID' => $contactId,
      ],
    ];
    $sendTemplateParams['from'] = $this->_intitParams['from'];
    $sendTemplateParams['toEmail'] = $email ?? $this->_intitParams['paymentstatement_default_email'];
    if (empty($sendTemplateParams['attachments'])) {
      $sendTemplateParams['attachments'] = [];
    }
    if ($contactId && !empty($paymentStatementPdfFile)) {
      // $paymentStatementPdfFile = $paymentStatementPdfFile;
      // attachment file is already availbale.
    }
    else {
      $paymentStatementPdfFile = CRM_Utils_Mail::appendPDF($pdfFileName, $html, $this->_pdfFormat);
    }
    $sendTemplateParams['attachments'][] = $paymentStatementPdfFile;
    [$sent, $subject, $message, $html] = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
    if ($sent && $contactId) {
      // Create Activity for Payment Printing..., Attached PDF to Activity.
      $subject = $this->_frequency . ' Payment Statement ' . $this->_period;
      if ($type == 'shared') {
        $subject .= ' (Common PDF)';
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
        'source_contact_id' => $contactId,
        'target_contact_id' => $contactId,
        'activity_type_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Email'),
        'activity_date_time' => date('YmdHis'),
        'details' => $activityDetail,
      ];

      try {
        $result = civicrm_api3('activity', 'create', $activityParams);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_var('FAILED : print activity Result', $e->getMessage(), FALSE, TRUE, 'com.skvare.paymentstatement');
      }
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
  private function generatePdfStatementForContact($contactId, $contributions, $totalAmount) {
    // Fetch contact details
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contactId]);
    $address = CRM_Core_BAO_Address::getValues(['contact_id' => $contactId], TRUE);
    $address = reset($address);
    $addressDisplay = $address['display_text'];
    if (!empty($addressDisplay)) {
      $addressDisplay = nl2br($addressDisplay);
    }
    $settings = self::getSettings();
    if (!empty($settings['paymentstatement_custom_css'])) {
      $customCss = $settings['paymentstatement_custom_css'];
      CRM_Core_Region::instance('export-document-header')->add(['style' => "{$customCss}"]);
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
    $paymentStatementPdfFile = $this->createPDfActivity($contactId, $html);

    return [$html, $paymentStatementPdfFile];
  }

  private function createPDfActivity($contactId, $html, $type = 'email') {
    $subject = $this->_frequency . ' Statement ' . $this->_period;
    if ($type == 'shared') {
      $subject = 'Common: ' . $subject;
    }

    $activityDetail = $html;
    $paymentStatementPdfFile = [];
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
        $result = civicrm_api3('activity', 'create', $activityParams);
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_var('FAILED : print activity Result', $e->getMessage(), FALSE, TRUE, 'com.skvare.paymentstatement');
      }

      return $paymentStatementPdfFile;
    }
    return $paymentStatementPdfFile;
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
    $elementNames = ['paymentstatement_logo', 'paymentstatement_custom_css', 'paymentstatement_default_email', 'paymentstatement_contact_id', 'paymentstatement_relationships'];
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
