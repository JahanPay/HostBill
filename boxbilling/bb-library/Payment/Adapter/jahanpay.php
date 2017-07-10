<?php
/*
Plugin Name: Boxbiling - jahanpay
Plugin URI: http://jahanpay.me
Description: Boxbiling - jahanpay
Version: 1.0
Author: jahanpay
Author URI: http://jahanpay.me
Copyright: 2016 jahanpay.me
*/

class Payment_Adapter_jahanpay
{
    private $config = array();
    
    public function __construct($config)
    {
        $this->config = $config;    
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments'   =>  true,
            'supports_subscriptions'     =>  false,
            'description'     =>  'پلاگین درگاه جهان پی برای باکس بیلینگ',
            'form'  => array(
                'api_jahanpay' => array('text', array(
                            'label' => 'شناسه درگاه پرداخت شما',
                            'validators'=>array('noempty'),
                    ),
                 ),
            ),
        );
    }

    public function getHtml($api_admin, $invoice_id, $subscription=false)
    {
    	$invoice = $api_admin->invoice_get(array('id'=>$invoice_id));
		$buyer = $invoice['buyer'];
                	
        $data = array();
        $data['invoice']            = $invoice['id'];
        $data['currency_code']      = $invoice['currency'];
        $data['api_jahanpay']     = $this->config['api_jahanpay'];  
        $data['amount']             = $invoice['total'];    
        $data['callback']           = $this->config['redirect_url'] . '&amount=' . $data['amount'];    
        $data['desc']               = 'پرداخت فاکتور : ' . $invoice['id'] . ' توسط : ' . $buyer['first_name'] . $buyer['last_name']; 
		
        return $this->_generateForm($data);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
		if(!empty($data['get']['au']) && !empty($data['get']['amount']))
		{
			$amount = $data['get']['amount']/10;
	 
	 	session_start();
			$au=$_SESSION['jp_au'];
			$order_id=$_SESSION['order_id'];
			$api=$this->config['api_jahanpay'];
	        $client = new SoapClient("http://www.jpws.me/directservice?wsdl");
            $res = $client->verification($this->config['api_jahanpay'], $amount , $au , $order_id, $_POST + $_GET );
			
			
			if($res['result'] == 1) 
			{ 
		
				$tx = $api_admin->invoice_transaction_get(array('id'=>$id));
				$invoice = $api_admin->invoice_get(array('id'=>$data['get']['bb_invoice_id']));				

				if(!$tx['invoice_id'])
				{
					$d = array(
						'id' => $id,
						'invoice_id' => $data['get']['bb_invoice_id'],
						'currency' => $invoice['currency'],
						'txn_status' => 'complete',
						'txn_id' => $au,
						'amount' => $invoice['total'],
						'type' => 'payment',
						'status' => 'complete',
						'updated_at' => date('c'),
					);
					$api_admin->invoice_transaction_update($d);
				}
				
				$bd = array(
					'id'            =>  $invoice['client']['id'],
					'amount'        =>  $data['get']['amount'],
					'description'   =>  'jahanpay transaction id : '.$au,
					'type'          =>  'jahanpay',
					'rel_id'        =>  $data['get']['bb_invoice_id'],
				);

				$api_admin->client_balance_add_funds($bd);
				$api_admin->invoice_update(array('id'=>$invoice['id'], 'status'=>'paid'));
				$api_admin->invoice_batch_pay_with_credits(array('client_id'=>$invoice['client']['id']));
			}
			else
				throw new Exception($res['result']);	
		}	
		else
			throw new Exception('پارامتر های ارسالی صحیح نمیباشد .');		
    }

    private function _generateForm($data)
    {
		$form  = '';
		if($data['currency_code'] != 'IRR')
			$form  = 'خطای واحد پولی';
		else
		{		
			$amount = (int) $data['amount'] / 10;
			$order_id = $data['invoice'] . rand(1,9999);
				
			$client = new SoapClient("http://www.jpws.me/directservice?wsdl");
            $res = $client->requestpayment($data['api_jahanpay'], $amount , $data['callback'].'&au=' . $order_id, $order_id );
			if($res['result']==1)
			{
			session_start();
			$_SESSION['jp_au']=$res['au'];
			$_SESSION['order_id']=$order_id;
			
			if(isset($this->config['auto_redirect']) && $this->config['auto_redirect']) 
				{
								$form = ('<div style="display:none;">'.$res['form'].'</div><script>document.forms["jahanpay"].submit();</script>');

				}
				else
				{
					$form .=  ''.$res['form'].'' . PHP_EOL . PHP_EOL;       
				}
			
			}
			else
				$form = $this->jahanpay_err($res['result']);
		}
        return $form;
    }
	
   
}