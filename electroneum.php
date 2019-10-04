<?php
/*
 * A payment plugin called "example". This is the main file of the plugin.
 */

// You need to extend from the hikashopPaymentPlugin class which already define lots of functions in order to simplify your work
class plgHikashoppaymentElectroneum extends hikashopPaymentPlugin
{


	var $multiple = true;

	var $name = 'electroneum';

	var $pluginConfig = array(

		'apikey' => array("API Key",'input'),
		'secret' => array("API Secret",'input'),
		'outlet' => array('Vendor ID', 'input'),
		'verified_status' => array('VERIFIED_STATUS', 'orderstatus')
	);

	function __construct(&$subject, $config)
	{
		$ajaxtask = JRequest::getVar("ajaxtask");

		$task = JRequest::getVar("task");
		


		if($ajaxtask == 'getresponse')
		{

			 ob_clean();
			 require_once("plugins/hikashoppayment/electroneum/src/Vendor.php");
			 require_once("plugins/hikashoppayment/electroneum/src/Exception/VendorException.php");
			
			 $apikey = JRequest::getVar("apikey"); 
			 $secret = JRequest::getVar("secret"); 
			 $outlet = JRequest::getVar("outlet"); 
			
			 $etn = JRequest::getVar("etn"); 
			 $paymentid = JRequest::getVar("paymentid"); 
			 $order_id = JRequest::getVar("order_id"); 
			 $orderstaus = JRequest::getVar("orderstaus"); 
			 
			 
			 $vendor = new \Electroneum\Vendor\Vendor($apikey, $secret);
			 
			 $payload = array();
			 $payload['payment_id'] = $paymentid;
 	         $payload['vendor_address'] = 'etn-it-'.$outlet;
			 
			 $result = $vendor->checkPaymentPoll(json_encode($payload));
			 
			 $return = array();
	 	     if($result['status'] == 1) 
			 {
				 $return['success'] = 1;
				 $return['amount'] = $result['amount'];
				 $result['message'] = '';
				 
				  $this->modifyOrder($order_id, $orderstaus, true, true);
			 }
			 else if (!empty($result['message']))  
			 {
				 $return['success'] = 0;
				 $return['message'] = $result['message'];
				 
			 }
			 else
			 {
				  $return['success'] = 0;
				  $return['message'] = 'Unknown Error was found';
			 }
			echo json_encode($return);
			exit;
		}
				
		return parent::__construct($subject, $config);
	}
	
	function onBeforeOrderCreate(&$order,&$do)
	{
		
		if(parent::onBeforeOrderCreate($order, $do) === true)
			return true;


		if (empty($this->payment_params->apikey) || empty($this->payment_params->secret) || empty($this->payment_params->outlet))
		{
			$app = JFactory::getApplication();
			$app->enqueueMessage('You have to configure an Vendor settings for Electroneum Payment First', 'error');
			$do = false;
		}
	}
	function onAfterOrderConfirm(&$order, &$methods, $method_id)
	{
		parent::onAfterOrderConfirm($order,$methods,$method_id);
		
		
		if (empty($this->payment_params->apikey) || empty($this->payment_params->secret) || empty($this->payment_params->outlet))
		{
			

			$this->app->enqueueMessage('You have to configure an Vendor settings for Electroneum Payment First', 'error');
			return false;
		}
		else
		{

			$amout = round($order->cart->full_total->prices[0]->price_value_with_tax, 2);
			
			$vars = array(
				'CLIENTIDENT' => $order->order_user_id,
				'DESCRIPTION' => "order number : ".$order->order_number,
				'ORDERID' => $order->order_id,
				'AMOUNT' => $amout
			);

			
			$this->vars = $vars;
			$this->removeCart = true;
			
			return $this->showPage('end'); 
		}
	}

	/**
	 * To set the specific configuration (back end) default values (see $pluginConfig array)
	 */
	function getPaymentDefaultValues(&$element)
	{
		$element->payment_name = 'Electroneum';
		$element->payment_description = 'Accept Payment from Electroneum Wallet';
		$element->payment_params->verified_status = 'confirmed';
	}

	/**
	 * After submiting the plateform payment form, this is where the website will receive the response information from the payment gateway servers and then validate or not the order
	 */
	function onPaymentNotification(&$statuses)
	{
		// We first create a filtered array from the parameters received
		$vars = array();
		
		$filter = JFilterInput::getInstance();
		
		// A loop to create an array $var with all the parameters sent by the payment gateway with a POST method, and loaded in the $_REQUEST
		foreach($_REQUEST as $key => $value)
		{
			$key = $filter->clean($key);
			$value = JRequest::getString($key);
			$vars[$key] = $value;
		}

		// The load the parameters of the plugin in $this->payment_params and the order data based on the order_id coming from the payment platform
		$order_id = (int)@$vars['ORDERID'];
		$dbOrder = $this->getOrder($order_id);

		// With the order, we can load the payment method, and thus all the payment parameters
		$this->loadPaymentParams($dbOrder);
		if(empty($this->payment_params))
			return false;
		$this->loadOrderData($dbOrder);

		// Here we are configuring the "succes URL" and the "fail URL". After checking all the parameters sent by the payment gateway, we will redirect the customer to one or another of those URL (not necessary for our example platform).
		$return_url = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id='.$order_id.$this->url_itemid;
		$cancel_url = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id='.$order_id.$this->url_itemid;

		// With its answer, the payment gateway sent a hash. This time, it's our turn to recalculate the hash with all the parameters received ($vars) to check if all the information received are identical to those sent by the payment platform ($decode mode)
		$hash = $this->example_signature($this->payment_params->password, $vars, false, true);

		// Debug mode activated or not
		if($this->payment_params->debug)
		{
			// Here we display debug information which will be catched by HikaShop and stored in the payment log file available in the configuration's Files section.
			echo print_r($vars,true)."\n\n\n";
			echo print_r($dbOrder,true)."\n\n\n";
			echo print_r($hash,true)."\n\n\n";
		}

		// Here is the last step : depending of the information received, we will validate or not the order, and redirect the user
		// We compare the hash generated on our side with the one sent by the payment gateway. If they are different, informations are distorted > process aborted
		if (strcasecmp($hash, $vars['HASH']) != 0)
		{
			// Here we display debug information which will be catched by HikaShop and stored in the payment log file available in the configuration's Files section.
			if($this->payment_params->debug)
				echo 'Hash error '.$vars['HASH'].' - '.$hash."\n\n\n";
			return false;
		}
		// The payment platform returns a code, corresponding to the state of the operation. Here, the "success" code is 0000. It means that any other code correspond to a payment failure > process aborted
		elseif($vars['EXECCODE'] != '0000')
		{
			// Here we display debug information which will be catched by HikaShop and stored in the payment log file available in the configuration's Files section.
			if($this->payment_params->debug)
				echo 'payment '.$vars['MESSAGE']."\n\n\n";
				
			// This function modifies the order with the id $order_id, to attribute it the status invalid_status.
			$this->modifyOrder($order_id, $this->payment_params->invalid_status, true, true);

			//To redirect the user, if needed. Here the redirection is useless : we are on server side (and not user side, so the redirect won't work), and the cancel url has been set on the payment platform merchant account
			// $this->app->redirect($cancel_url);
			return false;
		}
		//If everything's OK, the payment has been done. Order is validated -> success
		else
		{
			$this->modifyOrder($order_id, $this->payment_params->verified_status, true, true);
			
			// $this->app->redirect($return_url);
			return true;
		}
	}

	/**
	 * To generate the Hash, according to the payment platform requirement
	 * $password is the merchant's password, $parameters is an array of all required parameters, $debug is the debug mode, $decode is 'true' when we want to re-generate the hash after the payment platform answer
	 */
	
}
