<?php

require_once('Class_Natwest.php');

$usage = "Syntax: {$argv[0]} action [arguments]\n\n"
		."Actions:\n"
		."txn  [n]     Show the last [n] recent transactions.\n"
		."             (default is all recent transactions)\n"
		."bal  [a|b|o] Show the available, balance or overdraft.\n"
		."             (default is all of the above)\n\n";

if ( !isset($argv[1]) ) {
	echo $usage;
	die();
}

$customerID = '';
$customerPass = '';
$memWord = '';
$accountNum = '';

$natwest = new UK_Natwest($customerID,$customerPass,$customerPIN,$accountNum);

switch ( strtolower($argv[1]) ) {
case 'txn':
	$natwest->login();
	if ( isset($argv[2]) ) {
		print_r($natwest->getTransactions($argv[2]));
	} else {
		print_r($natwest->getTransactions());
	}
	break;
case 'bal':
	echo 'Not implemented';
	die();
	$natwest->login();
	if ( isset($argv[2]) ) {
		print_r($natwest->getBalance($argv[2]));
	} else {
		print_r($natwest->getBalance());
	}
	break;
default:
	echo $usage;
	break;
}

