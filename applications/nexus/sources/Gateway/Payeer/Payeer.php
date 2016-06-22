<?php

namespace IPS\nexus\Gateway;

if (!defined('\IPS\SUITE_UNIQUE_KEY'))
{
	header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
	exit;
}

class _Payeer extends \IPS\nexus\Gateway
{
	const SUPPORTS_REFUNDS = FALSE;
	const SUPPORTS_PARTIAL_REFUNDS = FALSE;

	public function canStoreCards()
	{
		return FALSE;
	}
	
	public function canAdminCharge()
	{
		$settings = json_decode($this->settings, TRUE);
		return ($settings['method'] === 'direct');
	}
	
	public function auth(\IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL)
	{
		$settings = json_decode($this->settings, TRUE);
		$transaction->save();
		
		$m_shop = $settings['MerchantID'];
		$m_orderid = $transaction->id;
		$m_amount = number_format((string)$transaction->amount->amount, 2, '.', '');
		$m_curr = $transaction->amount->currency == 'RUR' ? 'RUB' : $transaction->amount->currency;
		$m_desc = base64_encode($transaction->invoice->title);

		$arHash = array(
			$m_shop,
			$m_orderid,
			$m_amount,
			$m_curr,
			$m_desc,
			$settings['SecretKey']
		);
		
		$sign = strtoupper(hash('sha256', implode(':', $arHash)));
		
		$data = array(
			'm_shop' => $m_shop,
			'm_orderid' => $m_orderid,
			'm_amount' => $m_amount,
			'm_curr' => $m_curr,
			'm_desc' => $m_desc,
			'm_sign' => $sign
		);

		\IPS\Output::i()->redirect(\IPS\Http\Url::external($settings['MerchantURL'])->setQueryString($data));
	}
	
	public function settings(&$form)
	{
		$settings = json_decode($this->settings, TRUE);
		
		$form->add(new \IPS\Helpers\Form\Text('payeer_MerchantURL', $settings ? $settings['MerchantURL'] : 'https://payeer.com/merchant/', TRUE));
		$form->add(new \IPS\Helpers\Form\Text('payeer_MerchantID', $settings['MerchantID'], TRUE));
		$form->add(new \IPS\Helpers\Form\Text('payeer_SecretKey', $settings['SecretKey'], TRUE));
		$form->add(new \IPS\Helpers\Form\Text('payeer_PathLogFile', $settings['PathLogFile'], FALSE));
		$form->add(new \IPS\Helpers\Form\Text('payeer_IPFilter', $settings['IPFilter'], FALSE));
		$form->add(new \IPS\Helpers\Form\Text('payeer_EmailError', $settings['EmailError'], FALSE));
	}
	
	public function testSettings($settings)
	{		
		return $settings;
	}
}