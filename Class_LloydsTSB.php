<?php

/**
 * PHP web scraping class to grab a transaction list from
 * Lloyds TSB bank accounts
 *
 * @date   08/08/2013
 *
 * @author Simon Gaulter <simongaulter@fmail.co.uk> 0x881E6FB9
 *
 */
class UK_LloydsTSB {

	public $loginSuccess;
	private $loginData;
	private $curl;

	private static $URL_PREFIX = 'https://secure2.lloydstsb.co.uk';
	private static $URLS = array(
		'loginInit' =>
		'https://online.lloydstsb.co.uk/personal/logon/login.jsp',
		'loginUserPass' =>
		'https://online.lloydstsb.co.uk/personal/primarylogin',
		'loginMemInfo' =>
		'https://secure2.lloydstsb.co.uk/personal/a/logon/entermemorableinformation.jsp',
		'accounts' =>
		'https://secure2.lloydstsb.co.uk/personal/a/account_overview_personal',
		'logout' =>
		'https://secure2.lloydstsb.co.uk/personal/a/viewaccount/accountoverviewpersonalbase.jsp?lnkcmd=lnkCustomerLogoff&al='
	);

	private static $CURL_OPTS = array(
		CURLOPT_RETURNTRANSFER => TRUE,
		CURLOPT_HEADER         => FALSE,
		CURLOPT_FOLLOWLOCATION => TRUE,
		CURLOPT_ENCODING       => '',
		CURLOPT_AUTOREFERER    => TRUE,
		CURLOPT_CONNECTTIMEOUT => 120,
		CURLOPT_TIMEOUT        => 120,
		CURLOPT_MAXREDIRS      => 10,
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_SSL_VERIFYPEER => 0,
		CURLOPT_COOKIEFILE     => 'lloyds_cookies.txt',
		CURLOPT_COOKIEJAR      => 'lloyds_cookies.txt',
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:23.0) Gecko/20131011 Firefox/23.0'
	);

	public function __construct($customerID, $customerPass, $memWord, $accountName) {
		parent::__construct();

		$this->loginData = array(
			'customerID'   => $customerID,
			'customerPass' => $customerPass,
			'memWord'      => $memWord,
			'accountName'  => $accountName
		);

		if(!file_exists($this::$CURL_OPTS[CURLOPT_COOKIEFILE])) {
			$fh = fopen($this::$CURL_OPTS[CURLOPT_COOKIEFILE], 'w');
			fwrite($fh, '');
			fclose($fh);
		}

		$this->curl = curl_init();
		curl_setopt_array($this->curl, $this::$CURL_OPTS);

		libxml_use_internal_errors(TRUE);
	}

	/**
	 * curl
	 *
	 * Simple wrapper around curl, to quickly switch POST/GET.
	 *
	 * @param  (string)  (url)  The url to request
	 *         (boolean) (post) True if it's a POST request
	 *         (string)  (data) URL encoded POST data
	 *
	 * @return (string)         The HTML response string
	 */
	private function easycurl($url, $post = FALSE, $data = NULL) {
		curl_setopt($this->curl, CURLOPT_POST, $post);
		curl_setopt($this->curl, CURLOPT_URL, $url);
		if ( !is_null($data) ) {
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
		}
		return curl_exec($this->curl);
	}

	/**
	 * login
	 *
	 * @return null
	 */
	public function login() {

		$this->easycurl($this::$URLS['loginInit']);

		$loginData = array(
			'frmLogin:strCustomerLogin_userID' => $this->loginData['customerID'],
			'frmLogin:strCustomerLogin_pwd'    => $this->loginData['customerPass']
		);

		$html = $this->easycurl($this::$URLS['loginUserPass'], TRUE, http_build_query($loginData));
		$DOM = new DOMDocument();
		$DOM->loadHTML($html);
		$memInfoLabels = $DOM->getElementsByTagName('label');
		for ( $i = 0; $i < 3; $i++ ) {
			$memInfo[$i] =
				$this->loginData['memWord'][
					explode(' ', $memInfoLabels->item($i)->nodeValue)[1] - 1
				];
		}

		$xpath = new DOMXPath($DOM);
		$submitToken = $xpath
			->query('//input[@name="submitToken"]')
			->item(0)
			->getAttribute('value');

		$memData = array(
			'frmentermemorableinformation1:strEnterMemorableInformation_memInfo1'
			=> '&nbsp;'.$memInfo[0],
			'frmentermemorableinformation1:strEnterMemorableInformation_memInfo2'
			=> '&nbsp;'.$memInfo[1],
			'frmentermemorableinformation1:strEnterMemorableInformation_memInfo3'
			=> '&nbsp;'.$memInfo[2],
			'frmentermemorableinformation1' => 'frmentermemorableinformation1',
			'frmentermemorableinformation1:btnContinue' => 'null',
			'submitToken' => $submitToken
		);

		$this->easycurl($this::$URLS['loginMemInfo'], TRUE, http_build_query($memData));

		$this->loginSuccess = TRUE;
	}

	/**
	 * getTransactions
	 *
	 * Scrape the transaction list from a logged in session.
	 *
	 * @return  array of
	 *          $transaction['date','description','type','in','out','balance']
	 */
	public function getTransactions() {
		$html = $this->easycurl($this::$URLS['accounts']);

		$DOM = new DOMDocument();
		$DOM->loadHTML($html);
		$xpath = new DOMXPath($DOM);
		$x_query =
			'//div[contains(@class,"accountDetails")]'
			.'//a[contains(.,"'.$this->loginData['accountName'].'")]';

		$account_url = $xpath->query($x_query)->item(0)->getAttribute('href');

		$html = $this->easycurl($this::$URL_PREFIX.$account_url);

		$DOM = new DOMDocument();
		$DOM->loadHTML($html);
		$xpath = new DOMXPath($DOM);
		$x_query = '//table[contains(@class,"statement")]/tbody/tr';

		$txnRows = $xpath->query($x_query);

		foreach ( $txnRows as $txnRow ) {
			$txn = array(
				'date'        => $txnRow->childNodes->item(0)->nodeValue,
				'description' => $txnRow->childNodes->item(1)->nodeValue,
				'type'        => $txnRow->childNodes->item(2)->nodeValue,
				'in'          => $txnRow->childNodes->item(3)->nodeValue,
				'out'         => $txnRow->childNodes->item(4)->nodeValue,
				'balance'     => $txnRow->childNodes->item(5)->nodeValue
			);
			$transactions[] = $txn;
		}
		return $transactions;
	}

	/**
	 * logout
	 *
	 * @return null
	 */
	public function logout() {

		$this->easycurl($this::$URLS['logout']);

		$this->loginSuccess = FALSE;
	}
}

?>
