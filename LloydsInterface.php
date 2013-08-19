<?php

require_once('Class_LloydsTSB.php');

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

$lloyds = new UK_LloydsTSB($customerID,$customerPass,$memWord,$accountNum);

switch ( strtolower($argv[1]) ) {
case 'txn':
	$lloyds->login();
	if ( isset($argv[2]) ) {
		print_r($lloyds->getTransactions($argv[2]));
	} else {
		print_r($lloyds->getTransactions());
	}
	break;
case 'bal':
	$lloyds->login();
	if ( isset($argv[2]) ) {
		print_r($lloyds->getBalance($argv[2]));
	} else {
		print_r($lloyds->getBalance());
	}
	break;
default:
	echo $usage;
	break;
}

