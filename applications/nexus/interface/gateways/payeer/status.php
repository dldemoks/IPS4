<?php
/**
 * @brief		Payeer Gateway
 * @author		<a href='https://payeer.com'>Payeer</a>
 * @version		SVN_VERSION_NUMBER
 */

require_once '../../../../../init.php';
\IPS\Session\Front::i();

try
{
	$transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->m_orderid);
	
	if ($transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING)
	{
		throw new \OutofRangeException;
	}
}
catch (\OutOfRangeException $e)
{
	\IPS\Output::i()->redirect(\IPS\Http\Url::internal("app=nexus&module=payments&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->m_orderid, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https));
}

if (isset(\IPS\Request::i()->m_operation_id) && isset(\IPS\Request::i()->m_sign))
{
	$err = false;
	$message = '';
	$language = \IPS\Lang::load(\IPS\Lang::defaultLanguage());
	$settings = json_decode($transaction->method->settings, TRUE);
	
	// запись логов
	
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
		"description		" . base64_decode(\IPS\Request::i()->m_desc) . "\n" .
		"status				" . \IPS\Request::i()->m_status . "\n" .
		"sign				" . \IPS\Request::i()->m_sign . "\n\n";
	
	$log_file = $settings['PathLogFile'];
	
	if (!empty($log_file))
	{
		file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
	}
	
	// проверка цифровой подписи и ip

	$sign_hash = strtoupper(hash('sha256', implode(":", array(
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
	))));
	
	$valid_ip = true;
	$sIP = str_replace(' ', '', $settings['IPFilter']);
	
	if (!empty($sIP))
	{
		$arrIP = explode('.', $_SERVER['REMOTE_ADDR']);
		if (!preg_match('/(^|,)(' . $arrIP[0] . '|\*{1})(\.)' .
		'(' . $arrIP[1] . '|\*{1})(\.)' .
		'(' . $arrIP[2] . '|\*{1})(\.)' .
		'(' . $arrIP[3] . '|\*{1})($|,)/', $sIP))
		{
			$valid_ip = false;
		}
	}
	
	if (!$valid_ip)
	{
		$message .= $language->get('payeer_email_message4') . "\n" .
		$language->get('payeer_email_message5') . $sIP . "\n" . 
		$language->get('payeer_email_message6') . $_SERVER['REMOTE_ADDR'] . "\n";
		$err = true;
	}

	if (\IPS\Request::i()->m_sign != $sign_hash)
	{
		$message .= $language->get('payeer_email_message2') . "\n";
		$err = true;
	}
	
	if (!$err)
	{
		// проверка статуса
		
		switch (\IPS\Request::i()->m_status)
		{
			case 'success':
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
				catch (\Exception $e)
				{
					\IPS\Output::i()->redirect($transaction->invoice->checkoutUrl()->setQueryString(array( '_step' => 'checkout_pay', 'err' => $e->getMessage())));
				}
				break;
				
			default:
				$message .= $language->get('payeer_email_message3') . "\n";
				$err = true;
				break;
		}
	}
	
	if ($err)
	{
		$to = $settings['EmailError'];

		if (!empty($to))
		{
			$message = $language->get('payeer_email_message1') . "\n\n" . $message . "\n" . $log_text;
			$headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" . 
			"Content-type: text/plain; charset=utf-8 \r\n";
			mail($to, $language->get('payeer_email_subject'), $message, $headers);
		}
		
		exit(\IPS\Request::i()->m_orderid . '|error');
	}
	else
	{
		exit(\IPS\Request::i()->m_orderid . '|success');
	}
}
?>