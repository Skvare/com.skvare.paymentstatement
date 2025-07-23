# com.skvare.paymentstatement

# Payment Statement Extension for CiviCRM

This extension provides enhanced payment statement functionality for CiviCRM, enabling organizations to generate, manage, and distribute comprehensive payment statements to their constituents.

## Overview

The Payment Statement extension extends CiviCRM's native financial reporting capabilities by providing:

- **Automated Statement Generation**: Create detailed payment statements for donors, members, and event participants
- **Customizable Templates**: Design professional-looking statements that match your organization's branding
- **Flexible Reporting Periods**: Generate statements for any date range or specific time periods
- **Multiple Delivery Options**: Email statements directly to constituents or download for offline distribution
- **Bulk Processing**: Generate statements for multiple contacts efficiently

## Features

### Statement Generation
- Generate comprehensive payment statements showing all transactions within a specified period
- Include donations, membership payments, event registrations, and other financial transactions
- Automatic calculation of totals.

### Customization Options
- Customizable statement templates with organization branding
- PDF format.

### Automation & Scheduling
- Schedule automatic statement generation and distribution
- Set up recurring statement cycles (monthly, quarterly, annually)
- Automated email delivery with customizable messaging
- Integration with CiviCRM's scheduled jobs system

### Reporting & Analytics
- Track statement generation and delivery status
- Monitor constituent engagement with statements

## Requirements

- **CiviCRM Version**: 5.40 or higher
- **PHP Version**: 7.4 or higher
- **CMS Compatibility**: Drupal, WordPress, Joomla, Backdrop
- **Required Extensions**: None
- **Recommended Extensions**:
  - [Crontab Extension (for advanced scheduling)](https://github.com/Skvare/com.skvare.crontab)

## Installation

### Manual Installation
1. Download the extension from the [GitHub repository](https://github.com/Skvare/com.skvare.paymentstatement)
2. Extract the files to your CiviCRM extensions directory
3. Navigate to **Administer** → **System Settings** → **Extensions**
4. Find "Payment Statement" in the list and click **Install**


## Configuration

After installation, configure the extension:

1. **Navigate to Configuration**
  - Go to **Administer** → **CiviContribute** → **Payment Statement Settings**
  - Put logo URL.
  - Share Contact ID, used to keep pdf activity with attachment.(used for those does not have email address).
  - Email address for sending statements (used for those does not have email address).
  - Relationship type used go get contact associated with individual contact.
  -

2. **Template Setup**
  - Design your statement template
  - Add your organization's logo and branding
  - Configure header and footer information

3. **Default Settings**
  - Set default reporting periods
  - Configure automatic email settings

## Usage

### Automated Statements
- Scheduled job on set interval generate the pdf statement and sent an email.

### Professional Support
For professional support, custom development, or consulting services, contact [Skvare LLC](https://skvare.com/contact).


---

**[Contact us](https://skvare.com/contact) for support or to learn more** about implementing the CiviCRM Payment Statement Extension in your organization.


## Credits

**Developed by**: [Skvare LLC](https://skvare.com/)
**Maintainer**: Skvare Development Team

---

*Skvare helps others help others by providing strategy consultation, CiviCRM development, and ongoing support for nonprofit organizations, professional societies, membership-driven associations, and small businesses.*
