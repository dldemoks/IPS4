<?php
/**
 * @brief		Payeer Gateway
 * @author		<a href='http://www.payeer.com'>Payeer</a>
 * @version		SVN_VERSION_NUMBER
 */

require_once '../../../../../init.php';
\IPS\Session\Front::i();

try
{
	$transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->m_orderid);
	
	if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING )
	{
		throw new \OutofRangeException;
	}
}
catch ( \OutOfRangeException $e )
{
	\IPS\Output::i()->redirect(\IPS\Http\Url::internal("app=nexus&module=payments&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->m_orderid, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https));
}

$settings = json_decode( $transaction->method->settings, TRUE );

if (isset(\IPS\Request::i()->m_operation_id) && isset(\IPS\Request::i()->m_sign))
{
	// проверка принадлежности ip списку доверенных ip

	$list_ip_str = str_replace(' ', '', $settings['IPFilter']);

	if (!empty($list_ip_str)) 
	{
		$list_ip = explode(',', $list_ip_str);
		$this_ip = $_SERVER['REMOTE_ADDR'];
		$this_ip_field = explode('.', $this_ip);
		$list_ip_field = array();
		$i = 0;
		$valid_ip = FALSE;
		foreach ($list_ip as $ip)
		{
			$ip_field[$i] = explode('.', $ip);
			if ((($this_ip_field[0] ==  $ip_field[$i][0]) or ($ip_field[$i][0] == '*')) and
				(($this_ip_field[1] ==  $ip_field[$i][1]) or ($ip_field[$i][1] == '*')) and
				(($this_ip_field[2] ==  $ip_field[$i][2]) or ($ip_field[$i][2] == '*')) and
				(($this_ip_field[3] ==  $ip_field[$i][3]) or ($ip_field[$i][3] == '*')))
				{
					$valid_ip = TRUE;
					break;
				}
			$i++;
		}
	}
	else
	{
		$valid_ip = TRUE;
	}
	
	$log_text = 
		"--------------------------------------------------------\n" .
		"operation id		" . \IPS\Request::i()->m_operation_id . "\n" .
		"operation ps		" . \IPS\Request::i()->m_operation_ps . "\n" .
		"operation date		" . \IPS\Request::i()->m_operation_date . "\n" .
		"operation pay date	" . \IPS\Request::i()->m_operation_pay_date . "\n" .
		"shop				" . \IPS\Request::i()->m_shop . "\n" .
		"order id			" . \IPS\Request::i()->m_orderid . "\n" .
		"amount				" . \IPS\Request::i()->m_amount . "\n" .
		"currency			" . \IPS\Request::i()->m_curr . "\n" .
		"description			" . base64_decode(\IPS\Request::i()->m_desc) . "\n" .
		"status				" . \IPS\Request::i()->m_status . "\n" .
		"sign				" . \IPS\Request::i()->m_sign . "\n\n";

	if (!empty($settings['PathLogFile']))
	{
		file_put_contents($_SERVER['DOCUMENT_ROOT'] . $settings['PathLogFile'], $log_text, FILE_APPEND);
	}
	
	$arHash = array(
		\IPS\Request::i()->m_operation_id,
		\IPS\Request::i()->m_operation_ps,
		\IPS\Request::i()->m_operation_date,
		\IPS\Request::i()->m_operation_pay_date,
		\IPS\Request::i()->m_shop,
		\IPS\Request::i()->m_orderid,
		\IPS\Request::i()->m_amount,
		\IPS\Request::i()->m_curr,
		\IPS\Request::i()->m_desc,
		\IPS\Request::i()->m_status,
		$settings['SecretKey']
	);
	
	$sign_hash = strtoupper(hash('sha256', implode(':', $arHash)));
	
	if (\IPS\Request::i()->m_sign == $sign_hash && \IPS\Request::i()->m_status == 'success' && $valid_ip)
	{
		try
		{					
			$maxMind = NULL;
			if (\IPS\Settings::i()->maxmind_key)
			{
				$maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
				$maxMind->setTransaction($transaction);
				$maxMind->setTransactionType('payeer');
			}
			
			$transaction->checkFraudRulesAndCapture($maxMind);
			$transaction->sendNotification();
			\IPS\Session::i()->setMember($transaction->invoice->member);
		}
		catch ( \Exception $e )
		{
			\IPS\Output::i()->redirect($transaction->invoice->checkoutUrl()->setQueryString(array( '_step' => 'checkout_pay', 'err' => $e->getMessage())));
		}
		
		exit (\IPS\Request::i()->m_orderid . '|success');
	}
	else
	{
		if (!empty($settings['EmailError']))
		{
			$language = \IPS\Lang::load(\IPS\Lang::defaultLanguage());
			$subject = $language->get('payeer_email_subject');
			$message = $language->get('payeer_email_message1') . "\n\n";
			
			if (\IPS\Request::i()->m_sign != $sign_hash)
			{
				$message .= $language->get('payeer_email_message2') . "\n";
			}
			
			if (\IPS\Request::i()->m_status != "success")
			{
				$message .= $language->get('payeer_email_message3') . "\n";
			}
				
			if (!$valid_ip)
			{
				$message .= $language->get('payeer_email_message4') . "\n";
				$message .= $language->get('payeer_email_message5') . $settings['IPFilter'] . "\n";
				$message .= $language->get('payeer_email_message6') . $_SERVER['REMOTE_ADDR'] . "\n";
			}
			
			$message .= "\n" . $log_text;
			$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\nContent-type: text/plain; charset=utf-8 \r\n";
			mail($settings['EmailError'], $subject, $message, $headers);
		}
		
		exit (\IPS\Request::i()->m_orderid . '|error');
	}
}
?>