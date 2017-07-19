<?php
/**
 * Created by PhpStorm.
 * User: 731MY
 * Date: 17/05/31
 * Time: 10:56 PM
 */

namespace SMPP;

class SMPP {

	private $_socket;
	private $_sequence_number = 1;
	private $_debug = true;
	private $_auto_receive_response = true;

	private $_username,
			$_password;

	private $_unicode = false;

	private $_message = null,
			$_from = null,
			$_to = null;

	private $_system_type="WWW";
	private $_interface_version=0x34;
	private $_addr_ton=0;
	private $_addr_npi=0;
	private $_address_range="";

	// transmitter
	private $_service_type,
			$_source_addr_ton=0,
			$_source_addr_npi=0,
			$_dest_addr_ton=0,
			$_dest_addr_npi=0,
			$_esm_class=0,
			$_protocol_id=0,
			$_priority_flag=0,
			$_schedule_delivery_time="",
			$_validity_period="",
			$_registered_delivery_flag=1,
			$_replace_if_present_flag=0,
			$_data_coding=0x01,
			$_sm_default_msg_id=0;

	public function connect($host,$port=2775,$timeout=10){
		$this->_socket = fsockopen($host, $port, $errno, $errstr, $timeout);

		if($this->isConnected()){
			$this->_log('connected successfully');
			return true;
		}

		return false;
	}

	public function isConnected(){
		return ($this->_socket) ? true : false;
	}

	public function setLogin($username, $password){
		if(!$this->isConnected()){
			throw new \Exception('no connection is established');
			return $this->_log('no connection');
		}

		$this->setUsername($username);
		$this->setPassword($password);
		return $this;
	}

	public function send(){
		if(!$this->isConnected()) return $this->_log('no connection');

		if (is_null($this->getFrom()) || is_null($this->getTo()) || is_null($this->getMessage())){
			$this->_log("some field is empty \r\nfrom:".$this->getFrom()."\r\nto:".$this->getTo()."\r\nmessage:".$this->getMessage());
			return false;
		}

		$this->_source_addr_npi = 1;
		$this->_dest_addr_ton = 1;
		$this->_dest_addr_npi = 1;
		$this->_addr_npi=1;

		$this->_bind($this->getUsername(), $this->getPassword(), Command::ESME_BNDTRN); // Bind to SMSC Kernel as transmitter
		$this->_log('login ....');

		$pdu = $this->_PDUBuilder();

		$this->_log('PDU : '.$pdu);
		$this->_log('sending ....');

        $Message = $this->getMessage();

        $MultipartMessage = $this->MultiPartMessage($Message);

        if(count($MultipartMessage) == 0){
            $SendStatus = $this->_command(Command::ESME_SUB_SM,$pdu); // Submit a short-message
        }else{
            $this->_log("Message is multipart so we will send ".count($MultipartMessage)." messages ".print_r($MultipartMessage,true));

            $this->setEsmClass(0x40);
            foreach($MultipartMessage as $id=>$MessageContent){
                //if($id == 3) break;
                $MessageContent = ltrim($MessageContent,'\x30'); // bad byte added to the beggening and i dont know from where the fuck it come from.
                $this->setMessage($this->BuildUDHHeader(count($MultipartMessage)).$MessageContent);
                $pdu = $this->_PDUBuilder();

                $SendStatus = $this->_command(Command::ESME_SUB_SM,$pdu); // Submit a short-message
                $this->_addSequenceNumber();
            }
        }


		if($SendStatus){
			$this->_log("Message Response".print_r($SendStatus,true));
			$this->_log("Message Send Successfully \r\nfrom:".$this->getFrom()."\r\nto:".$this->getTo()."\r\nmessage:".$this->getMessage());
			return $SendStatus;
		}

		return false;
	}


    private function BuildUDHHeader($total){
        $hexed = [
            1=>"\x01",
            2=>"\x02",
            3=>"\x03",
            4=>"\x04",
            5=>"\x05",
            6=>"\x06",
            7=>"\x07",
            8=>"\x08",
            9=>"\x09",
            10=>"\x0a",
            11=>"\x0b",
            12=>"\x0c",
            13=>"\x0d",
            14=>"\x0e",
            15=>"\x0f",
            16=>"\x10",
            17=>"\x11",
            18=>"\x12",
            19=>"\x13",
            20=>"\x14",
            21=>"\x15",
        ];

        return "\x05\x00\x03\xA4".$hexed[$total].$hexed[$this->_getSequenceNumber()];
    }

    private function MultiPartMessage($Text){
        $SplitedWords = mb_split(' ',$Text,64);

        $t=0;
        $output = [];

        while(true){

            $Word = array_shift($SplitedWords);

            if(is_null($Word)) break;
            if(mb_strlen($output[$t].$Word) < 55){
                $output[$t] .= $Word." ";
            }else{
                $t++;
                $output[$t] = $Word." ";
            }
        }
        return $output;
    }

	public function receive(){
		$this->_log('login ...');
		$this->_bind($this->getUsername(), $this->getPassword(), Command::ESME_BNDRCV); // Bind to SMSC Kernel as a receiver
		$this->_log('receiving ...');
		return true;
	}


	private function _PDUBuilder(){

		return pack('a1cca'.(strlen($this->getFrom())+1).'cca'.(strlen($this->getTo())+1).'ccca1a1ccccca'.(strlen($this->getMessage())+20),
			$this->_service_type,
			$this->_source_addr_ton,
			$this->_source_addr_npi,
			$this->getFrom(),
			$this->_dest_addr_ton,
			$this->_dest_addr_npi,
			$this->getTo(),
            $this->getEsmClass(),
			$this->_protocol_id,
			$this->_priority_flag,
			$this->_schedule_delivery_time,
			$this->_validity_period,
			$this->_registered_delivery_flag,
			$this->_replace_if_present_flag,
			$this->_data_coding,
			$this->_sm_default_msg_id,
			strlen($this->getMessage()),
			$this->getMessage()
		);
	}

    private function setEsmClass($code = 0x00){
        $this->_esm_class = $code;
    }

    private function getEsmClass(){
        return $this->_esm_class;
    }

	private function _command($command_id,$pdu=''){
		if(!$this->isConnected()) return $this->_log('no connection');

		$this->_sendPDU($command_id, $pdu, $this->_getSequenceNumber());
		$PDUResponse=$this->_readPDUResponse($command_id);
		//$this->_addSequenceNumber();

		return $PDUResponse;
	}

	private function _parsePDU($pdu){
		//check command id
		if($pdu['id'] != Command::SMSC_DELIVER_SM) return false; // Submit a short-message to ESME
		//unpack PDU
		$ar=unpack("C*",$pdu['body']);
		$sms=array('service_type'=>$this->getString($ar,6),
			'source_addr_ton'=>array_shift($ar),
			'source_addr_npi'=>array_shift($ar),
			'source_addr'=>$this->getString($ar,21),
			'dest_addr_ton'=>array_shift($ar),
			'dest_addr_npi'=>array_shift($ar),
			'destination_addr'=>$this->getString($ar,21),
			'esm_class'=>array_shift($ar),
			'protocol_id'=>array_shift($ar),
			'priority_flag'=>array_shift($ar),
			'schedule_delivery_time'=>array_shift($ar),
			'validity_period'=>array_shift($ar),
			'registered_delivery'=>array_shift($ar),
			'replace_if_present_flag'=>array_shift($ar),
			'data_coding'=>array_shift($ar),
			'sm_default_msg_id'=>array_shift($ar),
			'sm_length'=>array_shift($ar),
			'short_message'=>trim($this->getString($ar,255),"\2\4")
		);

		if($this->_getAutoReceiveResponse()) $this->_sendReceipt($pdu);
		return $sms;
	}

	private function _sendReceipt($pdu){
		$PDUlength = strlen($pdu['body'])+16;
		$header=pack("NNNN", $PDUlength, $pdu['id'], $pdu['status'], $pdu['sn']);
		fwrite($this->_socket, $header.$pdu['body'], $PDUlength);
	}

	private function _sendPDU($command_id, $pdu, $sequence_number){
		$PDUlength = strlen($pdu)+16;
		$header=pack("NNNN", $PDUlength, $command_id, 0, $sequence_number);
		fwrite($this->_socket, $header.$pdu, $PDUlength);
	}

	public function SMS(){
		do{
			$pdu=$this->_readPDU();

			if($pdu['id'] == Command::ESME_QRYLINK){ // Link confidence check
				$this->_sendPDU(Command::ESME_QRYLINK_RESP, "", $pdu['sn']); // Response to enquire_link
				return false;
			}
		}while($pdu && $pdu['id'] != Command::SMSC_DELIVER_SM); // Submit a short-message to ESME

		if($pdu) return $this->_parsePDU($pdu);
		return false;
	}

	private function _readPDUResponse($command_id){
		$command_id = $command_id | Command::ESME_NACK; // Negative Acknowledgement

		do{
			$pdu = $this->_readPDU();
		}while($pdu && ($pdu['sn']!=$this->_getSequenceNumber() || $pdu['id']!=$command_id));

		if($pdu) return $pdu;
		return false;
	}

	private function _readPDU(){
		//read PDU length
		$tmp = fread($this->_socket, 4);
		if(!$tmp) return false;
		$length = unpack("Nlength", $tmp);
		//read PDU headers
		$tmp2 = fread($this->_socket, 12);
		if(!$tmp2)return false;
		$command = unpack("Ncommand_id/Ncommand_status/Nsequence_number", $tmp2);
		//read PDU body
		if($length['length']-16>0){
			$body = fread($this->_socket, $length['length']-16);
			if(!$body)return false;
		}else{
			$body="";
		}

		$pdu=array(
			'id'=>$command['command_id'],
			'status'=>$command['command_status'],
			'sn'=>$command['sequence_number'],
			'body'=>$body);
		return $pdu;
	}





	function _bind($login, $pass, $command_id){
		//make PDU
		$pdu = pack(
			'a'.(strlen($login)+1).
			'a'.(strlen($pass)+1).
			'a'.(strlen($this->_system_type)+1).
			'CCCa'.(strlen($this->_address_range)+1),
			$login, $pass, $this->_system_type,
			$this->_interface_version, $this->_addr_ton,
			$this->_addr_npi, $this->_address_range);
		$this->_command($command_id,$pdu);
		return $pdu;
	}



	private function isUnicode(){
		return $this->_unicode;
	}

	function _text2Hex($Text){
		$Encode = iconv('UTF-8', 'UCS-2', $Text);
		$Convert2Hex = bin2hex($Encode);
		$SplitedHex = str_split($Convert2Hex,2);
		if(version_compare("5.6.19",phpversion()) != 0){
			$SplitedHex = array_chunk($SplitedHex,2);
			$SplitedHex = array_map(function($val){
				return array_reverse($val);
			},$SplitedHex);

			$SplitedHex = array_reduce($SplitedHex, 'array_merge', array());

			foreach($SplitedHex as $key=>$val){
				$SplitedHex[$key] = chr(hexdec($val));
			}
		}else{
			$SplitedHex = array_map(function($val){
				return "\\x".$val;
			},$SplitedHex);
		}

		return implode('',$SplitedHex);
	}

	private function getMessage(){
		return $this->_message;
	}

	private function getFrom(){
		return $this->_from;
	}

	private function getTo(){
		return $this->_to;
	}

	public function setMessage($text){
		$this->_message = $text;
		$this->_unicode = false;
		return $this;
	}

	public function setUnicodeMessage($text){
		//$this->_message = str_replace("\x06 ","\x00 ",trim("\x06".$this->_text2Hex($text),"\x00"));
		$this->_message = $this->_text2Hex($text);

		$this->_unicode = true;
		$this->_data_coding = 0x08;
		return $this;
	}

	public function setFrom($from){
		$this->_from = $from;
		return $this;
	}

	public function setTo($to){
		$this->_to = $to;
		return $this;
	}

	public function setUsername($username){
		$this->_username = $username;
		return $this;
	}

	public function setPassword($password){
		$this->_password = $password;
		return $this;
	}

	public function getUsername(){
		return $this->_username;
	}

	public function getPassword(){
		return $this->_password;
	}

	private function _getSequenceNumber(){
		return $this->_sequence_number;
	}

	private function _addSequenceNumber(){
		return $this->_sequence_number++;
	}

	private function _log($message){
		if($this->_debug) print $message."\r\n";
		return false;
	}

	private function _getAutoReceiveResponse(){
		return $this->_auto_receive_response;
	}

	private function setAutoReceiveResponse($status){
		$this->_auto_receive_response = $status;
	}

	function getString(&$ar, $maxlen=255){
		$s="";
		$i=0;
		do{
			$c=array_shift($ar);
			if($c!=0)$s.=chr($c);
			$i++;
		}while($i<$maxlen && $c!=0);
		return $s;
	}

	function __destruct() {
		if($this->isConnected()) {
			$this->_command(Command::ESME_UBD); // Unbind from SMSC Kernel
			fclose($this->_socket);
		}

		$this->_log('connect closed');
	}
}