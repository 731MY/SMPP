<?php
ob_start();

require 'src/SMPP/Command.php';
require 'src/SMPP/SMPP.php';

use SMPP\SMPP;


$smpp = new SMPP();

$smpp->connect('smpp.gateway.com');

if($smpp->isConnected()){
	$smpp->setLogin("username", "password");
	$smpp->setFrom('731my')->setTo('00000000000')->setUnicodeMessage("تجربة")->send();

	$smpp->receive();
	do{
		$sms = $smpp->SMS();
		if($sms){
			//print_r($sms);
		}

		$output = ob_get_clean();
		echo $output;
	}while(true);
}