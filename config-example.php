<?php

$COOKIE_PREFIX           = "company";
$HOSTED_DOMAIN           = "www.example.com";
$JIRA_DOMAIN             = "example.atlassian.com";
$WEBSITE_TITLE           = "Jira Time Tracking for Example Company";
$REPORTING_WEBSITE_TITLE = "Reporting: Jira Time Tracking for Example Company";
$EXTRA_HEAD_HTML         = "";
// See top of AuthJiraCert.php for instructions on how to generate these parameters
$OAUTH_CONSUMER_KEY      = "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff";
$OAUTH_PRIVATE_KEY_FILE  = "/path/to/myrsakey.pem";
$PATH_TO_HLEDGER_BINARY  = "/path/to/hledger";
$COMPANY_PAYROLL_HEADER  = "<table cellpadding=\"3\"><tr><td rowspan=\"3\"><img width=\"80\" src=\"/path/to/logo.png\"></td><td>Example, Inc.</td></tr><tr><td>123 Anystreet Boulevard</td></tr><tr><td>Podunk, ZZ 99999</td></tr></table>";
$STORAGE_PATH            = "/path/to/writable/storage";
// These values must be determined by examining an export of time data from quickbooks
$QUICKBOOKS_COMPANY_NAME = "Example, Inc";
$QUICKBOOKS_CREATE_TIME  = "9999999999";
