<?php
/**
 * @brief		Payeer Gateway
 * @author		<a href='http://www.payeer.com'>Payeer</a>
 * @version		SVN_VERSION_NUMBER
 */

require_once '../../../../../init.php';
\IPS\Session\Front::i();

$transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->m_orderid);
\IPS\Output::i()->redirect($transaction->url());
?>