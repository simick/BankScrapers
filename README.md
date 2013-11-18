BankScrapers
============

PHP scrapers for Lloyds TSB and Natwest accounts

Before use, populate the variables

$customerID    Customer identification number;
$customerPass  Password;
$memWord       Memorable word;
$accountNum    Account number of the account you want to scrape.

Usage: php LloydsInterface.php action [arguments]

Actions:
txn  [n]     Show the last [n] recent transactions.
             (default is all recent transactions)
bal  [a|b|o] Show the available, balance or overdraft.
             (default is all of the above)
