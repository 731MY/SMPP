<?php
/**
 * Created by PhpStorm.
 * User: 731MY
 * Date: 17/05/31
 * Time: 11:32 PM
 */

namespace SMPP;


class Command {
	const ESME_BNDRCV  = 0x00000001;
	const ESME_BNDTRN = 0x00000002;
	const ESME_SUB_SM = 0x00000004;
	const SMSC_DELIVER_SM = 0x00000005;
	const ESME_UBD = 0x00000006;
	const ESME_QRYLINK = 0x00000015;

	const ESME_NACK = 0x80000000;
	const SMSC_DELIVER_SM_RESP = 0x80000005;
	const ESME_QRYLINK_RESP = 0x80000015;

}