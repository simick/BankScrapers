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
class UK_LloydsBank {

	public $loginSuccess;
	private $loginData;
	private $curl;
	private $availableBalance;

	private static $URL_PREFIX = 'https://secure2.lloydstsb.co.uk';
	private static $URLS = array(
		'loginInit' =>
		'https://online.lloydsbank.co.uk/personal/logon/login.jsp',
		'loginUserPass' =>
		'https://online.lloydsbank.co.uk/personal/primarylogin',
		'loginMemInfo' =>
		'https://secure.lloydsbank.co.uk/personal/a/logon/entermemorableinformation.jsp',
		'accounts' =>
		'https://secure.lloydsbank.co.uk/personal/a/account_overview_personal',
		'logout' =>
		'https://secure.lloydsbank.co.uk/personal/a/viewaccount/accountoverviewpersonalbase.jsp?lnkcmd=lnkCustomerLogoff&al='
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

	const
	EZX_OTHER  = 0;

	public function __construct($customerID, $customerPass, $memWord, $accNumber) {

		$this->loginData = array(
			'customerID'   => $customerID,
			'customerPass' => $customerPass,
			'memWord'      => $memWord,
			'accNumber'  => $accNumber
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

	public function setLoggedOut() {
		$this->loginSuccess = false;
	}

	public function setLoggedIn() {
		$this->loginSuccess = true;
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
	 * Helper to run common xpath queries.
	 *
	 * @param  (string)  (html)    The HTML string to query
	 *         (int)     (action)  Common actions to perform:
	 *                             EZX_FORM   : Return the form
	 *                             EZX_HIDDEN : Return hidden elements
	 *                             EZX_OTHER  : Return a custom query
	 *         (string)  (x_query) Optional query to run, if EZX_OTHER
	 *                             is selected
	 *
	 * @return (DOMNodeList)       The elements selected by the query.
	 */
	private function easyxpath($html, $action, $x_query = NULL) {
		$DOM = new DOMDocument();
		$DOM->loadHTML($html);
		$xpath = new DOMXPath($DOM);
		switch ( $action ) {
		case self::EZX_OTHER:
			return $xpath->query($x_query);
			break;
		}
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
			$nvExploded = explode(' ', $memInfoLabels->item($i)->nodeValue);
			$memInfo[$i] =
				$this->loginData['memWord'][
					$nvExploded[1] - 1
				];
		}

		$x_query = '//input[@name="submitToken"]';
		$submitToken = $this->easyxpath($html, self::EZX_OTHER, $x_query)
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

		# See if we can get the account number to test if the login succeeded.

		$html = $this->easycurl($this::$URLS['accounts']);

		$x_query =
			'//div[contains(@class,"accountDetails")'
			.' and contains(.,"'.$this->loginData['accNumber'].'")]//a';
		$account_url = $this->easyxpath($html, self::EZX_OTHER, $x_query);
		if ( $account_url->length == 0 ) {
			$this->setLoggedOut();
		} else {
			$this->setLoggedIn();
		}
	}

	/**
	 * selectAccount
	 *
	 * Return the 'View Product Details' page, containing
	 * recent transactions, balance, available balance etc.
	 *
	 * @return (string)           Product details HTML
	 */
	private function selectAccount() {
		$html = $this->easycurl($this::$URLS['accounts']);

		$x_query =
			'//div[contains(@class,"accountDetails")'
			.' and contains(.,"'.$this->loginData['accNumber'].'")]//a';
		$account_url = $this->easyxpath($html, self::EZX_OTHER, $x_query)
			->item(0)
			->getAttribute('href');

		return $this->easycurl($this::$URL_PREFIX.$account_url);
	}

	/**
	 * getBalance
	 *
	 * Scrapes the current balance, available balance
	 * and overdraft limit of the selected account.
	 *
	 * @return  (string array)  'balance', 'available', 'overdraft'
	 */
	public function getBalance($which = NULL) {
		$html = $this->selectAccount();

		$x_query_balance = '//div[@class="accountBalance"]//p[@class="balance"]';
		$balances['b'] =
			$this->easyxpath($html, self::EZX_OTHER, $x_query_balance)
			->item(0)
			->nodeValue;

		$x_query_available = '//div[@class="accountBalance"]//p[contains(.,"available")]';
		$available =
			$this->easyxpath($html, self::EZX_OTHER, $x_query_available)
			->item(0)
			->nodeValue;
		$balances['a'] = array_pop(explode(" ", $available));

		$x_query_overdraft = '//div[@class="accountBalance"]//p[contains(.,"Overdraft")]';
		$overdraft =
			$this->easyxpath($html, self::EZX_OTHER, $x_query_overdraft)
			->item(0)
			->nodeValue;
		$balances['o'] = array_pop(explode(" ", $overdraft));

		if ( !is_null($which) ) {
			return $balances[$which];
		} else {
			return $balances;
		}
	}

	/**
	 * getTransactions
	 *
	 * Scrape the transaction list from a logged in session.
	 *
	 * @return  array of
	 *          $transaction['date','description','type','in','out','balance']
	 */
	public function getTransactions($n = NULL) {
		if ( ! $this->loginSuccess ) {
			$this->login();
		}

		# Lloyds doesn't update the transaction list without logging out and back in.
		# It does update the available balance though, so we'll check that and logout if necessary.
		if ( ! isset($this->availableBalance) ) {
			$this->availableBalance = $this->getBalance('a');
		} else {
			if ( $this->availableBalance != $this->getBalance('a') ) {
				$this->logout();
				$this->login();
			}
		}

		$html = $this->selectAccount();

		$x_query = '//table[contains(@class,"statement")]/tbody/tr';

		$txnRows = $this->easyxpath($html, self::EZX_OTHER, $x_query);

		foreach ( $txnRows as $txnRow ) {
			$txn = array(
				'date'            => strtotime($txnRow->childNodes->item(0)->nodeValue),
				'commentary'      => $txnRow->childNodes->item(1)->nodeValue,
				'transType'       => $txnRow->childNodes->item(2)->nodeValue,
				'amount'          => floatval($txnRow->childNodes->item(3)->nodeValue)
				- floatval($txnRow->childNodes->item(4)->nodeValue),
				'balance'         => $txnRow->childNodes->item(5)->nodeValue
			);

			$commentary = $this->parseCommentary($txnRow->childNodes->item(1));
			$txn = array_merge($txn, $commentary);

			$transactions[] = $txn;
		}
		if ( is_null($n) ) {
			return $transactions;
		} else {
			return array_slice($transactions, 0, $n);
		}
	}

	private function parseCommentary($cNode) {
		// The other party seems to always be the first entry, handily in its own span.
		// However, it sometimes has the date directly afterwards, separated by multiple
		// spaces.
		$nvExploded = explode("  ",$cNode->childNodes->item(0)->nodeValue);
		$c['transOtherParty'] = $nvExploded[0];

		// Everything from now is pick and mix, depending on transaction type...

		// We'll often have a date and/or time, of the form ddMONyy hh:mm
		$months = array('JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC');
		$date_regex = '/\d{2}('.implode('|', array_map('preg_quote', $months)).')\d{2}/i';
		preg_match($date_regex, $cNode->nodeValue, $matches);
		if ( $matches ) {
			$c['date'] = $matches[0];
		}
		$time_regex = '/\d{2}:\d{2}/i';
		preg_match($time_regex, $cNode->nodeValue, $matches);
		if ( $matches ) {
			$c['time'] = $matches[0];
		}

		// If there's an FPID, it'll be an 18 character string. This is pretty
		//  much all we know about it.
		$fpid_regex = '/\w{18}/i';
		preg_match($fpid_regex, $cNode->nodeValue, $matches);
		if ( $matches ) {
			$c['fpid'] = $matches[0];
		}

		// We'll delete all of the above and hope that what's left is a reference number.
		// In practice, this probably won't be the case, but we're not going to be able
		// to differentiate between arbitrary references and, say, account numbers and
		// sort codes. I.e., USE THIS WITH CAUTION.
		$c['reference'] = trim(str_replace($c, '', $cNode->nodeValue));
		return $c;
	}

	/**
	 * logout
	 *
	 * @return null
	 */
	public function logout() {

		$this->easycurl($this::$URLS['logout']);

		$this->setLoggedOut();
	}
}

?>
