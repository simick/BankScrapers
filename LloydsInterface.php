<?php

require_once('Class_LloydsBank.php');

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

##################################
# Complete these fields before use
$customerID = '';
$customerPass = '';
$memWord = '';
$accountNum = '';
##################################

$lloyds = new UK_LloydsBank($customerID,$customerPass,$memWord,$accountNum);

switch ( strtolower($argv[1]) ) {
case 'txn':
	$lloyds->login();
	if ( isset($argv[2]) ) {
		echo json_encode($lloyds->getTransactions($argv[2]), JSON_PRETTY_PRINT);
	} else {
		echo json_encode($lloyds->getTransactions(), JSON_PRETTY_PRINT);
	}
	break;
case 'bal':
	$lloyds->login();
	if ( isset($argv[2]) ) {
		echo json_encode($lloyds->getBalance($argv[2]), JSON_PRETTY_PRINT);
	} else {
		echo json_encode($lloyds->getBalance(), JSON_PRETTY_PRINT);
	}
	break;
default:
	echo $usage;
	break;
}

