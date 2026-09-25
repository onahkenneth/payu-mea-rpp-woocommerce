<?php declare(strict_types=1);

/**
 * Copyright © 2024 PayU Financial Services. All rights reserved.
 * See COPYING.txt for license details.
 */

class PayU_Payment_Transaction extends PayU_Payment_Base {
    /**
	 * @var array
	 */
	private array $url_array = [
		'staging' => 'https://staging.payu.co.za',
		'production' => 'https://secure.payu.co.za'
	];

	/**
	 * @var string
	 */
	private ?string $base_url = null;

	/**
	 * @var string
	 */
	private ?string $wdsl_url = null;

	/**
	 * @var bool
	 */
	private bool $extended_debug = false;
	
	/**
	 * @var string
	 */
	private ?string $soap_username = null;

	/**
	 * @var string
	 */
	private ?string $soap_password = null;

	/**
	 * @var string
	 */
	private ?string $safekey = null;

	/**
	 * @var string
	 */
	private string $api_version = "ONE_ZERO";

	/**
	 * @var SoapHeader
	 */
	private ?SoapHeader $auth_header = null;

	private ?WC_PayU_XMLParser $xml_parser = null;
	
	/**
	 * @var SoapClient
	 */
	public ?SoapClient $soap_client = null;
	
	/**
	 * RedirectPayment constructor
	 *
	 * @param mixed $config
	 */
	public function __construct($config = [])
	{
		parent::__construct($config);
		
		$this->base_url = $this->url_array['staging'];
		
		if (isset($config['production']) && ($config['production'] !== false)) {
			$this->base_url = $this->url_array['production'];
		}

		if (isset($config['username']) && (!empty($config['username']))) {
			$this->soap_username = $config['username'];
		}

		if (isset($config['password']) && (!empty($config['password']))) {
			$this->soap_password = $config['password'];
		}

		if (isset($config['extended_debug']) && ($config['extended_debug'] === true)) {
			$this->extended_debug = true;
		}

		if (isset($config['safekey']) && (!empty($config['safekey']))) {
			$this->safekey = $config['safekey'];
		}

		$this->set_soap_wdsl_url();
		$this->xml_parser = new WC_PayU_XMLParser();
		$this->soap_client = new SoapClient($this->wdsl_url, ["trace" => 1, "exception" => 0]);
	}
	
	/**
	* Do the get transaction soap call against the PayU API and returns a url containing the RPP url with reference
	*
	* @param array $payload Array containing the data
	* @return stdClass Transaction response
	*/
	public function do_get_transaction($payload = [])
	{
		$response = $this->do_soap_call('getTransaction', $payload);

		return json_decode(json_encode($response['return']));
	}
	
	
	/**
	* Do the set transaction soap call against the PayU API and returns a url containing the RPP url with reference
	*
	* @param array $payload Array containing the data
	* @return stdClass response
    * @throws PayUTransactionException When PayU declines the transaction.
    * @throws Exception When the SOAP call fails.
	*/
	public function do_set_transaction($payload = []): stdClass
	{
		$response = $this->do_soap_call('setTransaction', $payload);
		$data = $response['return'];

		// If succesfull then pass back the payUreference  with return URL
		if (isset($data['successful']) && ($data['successful'] === true)) {
			$data['redirect_payment_url'] = $this->get_redirect_url($data['payUReference']);

			return json_decode(json_encode($data));
		}

		// displayMessage is written for the customer; resultMessage is the merchant-facing reason.
		$display_message = !empty($data['displayMessage'])
			? (string) $data['displayMessage']
			: __('Unspecified error. Please contact the merchant.', 'woocommerce-gateway-payu');

		throw new PayUTransactionException(
			$display_message,
			(string) ($data['resultCode'] ?? ''),
			(string) ($data['resultMessage'] ?? '')
		);
	}

	/**
	 * Do the finalize/capture/cancel/refund transaction soap call against the PayU API and returns a response
	 *
	 * @param array $payload Array containing the data
	 * @return stdClass Transaction response
	 */
	public function do_transaction($payload = [])
	{
		$response = $this->do_soap_call('doTransaction', $payload);

		return json_decode(json_encode($response['return']));
	}
	
	/**
	* Do the soap call against the PayU API
	*
	* @param ?string $method The Soap method to call
	* @param array $payload Array containing data
	* @return array Returns the soap result in array format
    * @throws Exception
	*/
	public function do_soap_call(?string $method = null , $payload = [])
	{
		// A couple of validation business ruless before doing the soap call
		if (empty($payload)) {
			throw new Exception("Please provide data to be used on the soap call");
		} elseif(empty($method)) {
			throw new Exception("Please provide a soap function to call");
		}

		if (empty($this->auth_header)) {
			$this->set_soap_header();
		}
		
		if (!empty($this->safekey)) {
			$payload['Safekey'] = $this->safekey;
		}

		$this->log("\r\n------------------   STARTING SOAP CALL: " . $method . "   -----------------------------" . PHP_EOL);
		
		$this->soap_client->__setSoapHeaders($this->auth_header);

		$payload = array_merge($payload, ['Api' => $this->api_version]);

		try{
			$result = $this->soap_client->$method($payload);
			$this->log_soap_call($method);
		} catch(Exception $e) {
			$this->log_soap_call($method);

			throw new Exception($e->getMessage(), 0, $e);
		}

		return json_decode(json_encode($result), true);
	}
	
    /**
     * @throws \exception
     * @return void
     */
	private function set_soap_header() {
		
		if (empty($this->soap_username)) {
			throw new exception('Please specify a merchant username for soap trasaction');
		} elseif(empty($this->soap_password)) {
			throw new exception('Please specify a merchant password for soap trasaction');
		}
		
		$header_xml = '<wsse:Security SOAP-ENV:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">';
		$header_xml .= '<wsse:UsernameToken wsu:Id="UsernameToken-9" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">';
		$header_xml .= '<wsse:Username>'.$this->soap_username.'</wsse:Username>';
		$header_xml .= '<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'.$this->soap_password.'</wsse:Password>';
		$header_xml .= '</wsse:UsernameToken>';
		$header_xml .= '</wsse:Security>';
		$header_body = new SoapVar($header_xml, XSD_ANYXML, null, null, null);
		
		$ns = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

		$this->auth_header = new SOAPHeader($ns, 'Security', $header_body, true);
	}
	
	/**
	 * Get the redirect url to use
     * @param $payu_reference
     * @return string
     * @throws Exception
	 */
	private function get_redirect_url($pay_u_reference = null): string
	{
		if (empty($pay_u_reference)) {
			throw new Exception('Please specify a valid payU Reference number');
		}

		return $this->base_url . '/rpp.do?PayUReference=' . $pay_u_reference;
	}

	/**
	 * Set PayU soap WDSL URL
     * @return void
	 */
	private function set_soap_wdsl_url(): void
	{
		$this->wdsl_url = $this->base_url . '/service/PayUAPI?wsdl';
	}

    /**
     * @param string $method
     * @return void
     */
	private function log_soap_call(string $method): void
	{
		if (is_object($this->soap_client)) {
			$this->log("SOAP REQUEST HEADERS: " . $method, "\r\n" . $this->soap_client->__getLastRequestHeaders());
			$this->log(
				"SOAP REQUEST: " . $method,
				"\r\n" . $this->xml_parser->prettyPrint($this->soap_client->__getLastRequest())
			);
			$this->log("SOAP RESPONSE HEADERS: " . $method, "\r\n" . $this->soap_client->__getLastResponseHeaders());
			$this->log(
				"SOAP RESPONSE: " . $method,
				"\r\n" . $this->xml_parser->prettyPrint($this->soap_client->__getLastResponse())
			);
		}
	}
}
