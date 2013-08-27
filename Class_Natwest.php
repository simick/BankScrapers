<?php

/**
 * PHP web scraping class to grab a transaction list from
 * Natwest bank accounts.
 *
 * @date   08/08/2013
 *
 * @author Simon Gaulter <simongaulter@fmail.co.uk> 0x881E6FB9
 *
 */
class UK_Natwest {

	public $loginSuccess;
	private $loginData;
	private $curl;

	private static $URL_PREFIX = 'https://www.nwolb.com';
	private static $URLS = array(
		'loginInit' =>
		'https://www.nwolb.com/default.aspx',
		'statements' =>
		'https://www.nwolb.com/StatementsFixedperiod.aspx?id=',
		'summary' =>
		'https://www.nwolb.com/AccountSummary2.aspx',
		'logout' =>
		'https://www.nwolb.com/ServiceManagement/RedirectOutOfService.aspx?targettag=destination_ExitService&secstatus=0'
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
		CURLOPT_COOKIEFILE     => 'natwest_cookies.txt',
		CURLOPT_COOKIEJAR      => 'natwest_cookies.txt',
		CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:23.0) Gecko/20131011 Firefox/23.0'
	);

	const
		EZX_OTHER  = 0,
		EZX_FORM   = 1,
		EZX_HIDDEN = 2;

	public function __construct($customerID, $customerPass, $customerPIN, $accountNum) {

		$this->loginData = array(
			'customerID'   => $customerID,
			'customerPass' => $customerPass,
			'customerPIN'  => $customerPIN,
			'accountNum'  => $accountNum
		);

		if(!file_exists(self::$CURL_OPTS[CURLOPT_COOKIEFILE])) {
			$fh = fopen(self::$CURL_OPTS[CURLOPT_COOKIEFILE], 'w');
			fwrite($fh, '');
			fclose($fh);
		}

		$this->curl = curl_init();
		curl_setopt_array($this->curl, self::$CURL_OPTS);

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
		case self::EZX_FORM:
			$x_query = '//form[@name="aspnetForm"]';
			return $xpath->query($x_query);
			break;
		case self::EZX_HIDDEN:
			$x_query = '//form[@name="aspnetForm"]//input[@type="hidden"]';
			return $xpath->query($x_query);
			break;
		case self::EZX_OTHER:
			return $xpath->query($x_query);
			break;
		}
	}

	/**
	 * login_step1
	 *
	 * GET the login page, scrape the hidden ASP.NET fields
	 * from the inner security frame and POST them along
	 * with the customer ID.
	 *
	 * @return (string)         The HTML response of the
	 *                          PIN / password page.
	 */
	private function login_step1() {
		// Grab the login page.
		$html = $this->easycurl(self::$URLS['loginInit']);

		// Hidden security frame shenanigans.
		$secFrameURL = self::$URL_PREFIX.'/'
			.$this->easyxpath($html, self::EZX_OTHER, '//frame')
			->item(0)
			->getAttribute('src');

		$secFrameHtml = $this->easycurl($secFrameURL);

		// Grab the dynamic post url.
		$postURL = self::$URL_PREFIX.'/'
			.$this->easyxpath($secFrameHtml, self::EZX_FORM)
			->item(0)
			->getAttribute('action');

		// And the hidden inputs from the security frame...
		$secFrameInputs = $this->easyxpath($secFrameHtml, self::EZX_HIDDEN);

		// ...which we'll put into an array to be encoded.
		foreach ( $secFrameInputs as $secFrameInput ) {
			$postData[$secFrameInput->getAttribute('name')]
				= $secFrameInput->getAttribute('value');
		}
		// Here's the actual login criterion.
		$postData['ctl00$mainContent$LI5TABA$DBID_edit'] = $this->loginData['customerID'];
		$postData['ctl00$mainContent$LI5TABA$LI5-LBA_button_button'] = 'Log in';
		
		// Curl it. This should return the PIN/password page.
		return $this->easycurl($postURL, TRUE, http_build_query($postData));
	}

	/**
	 * login_step2
	 *
	 * Parse the required characters of the PIN and password
	 * and POST them. Sets the auth cookie on success.
	 *
	 * @param  (string)         The HTML of the PIN /
	 *                          password entry page.
	 */
	private function login_step2($html) {

		// Parse the form for the submission URL and hidden values.
		$postURL = self::$URL_PREFIX.'/'
			.$this->easyxpath($html, self::EZX_FORM)
			->item(0)
			->getAttribute('action');

		$inputs = $this->easyxpath($html, self::EZX_HIDDEN);
		foreach ( $inputs as $input ) {
			$postData[$input->getAttribute('name')] = $input->getAttribute('value');
		}

		// Parse the required PIN and password characters
		$x_query = '//label[contains(@for,"ctl00_mainContent_Tab1_LI6PPE")]';
		$charLabels = $this->easyxpath($html, self::EZX_OTHER, $x_query);

		for ( $i = 0; $i < 3; $i++ ) {
			preg_match('!\d+!', $charLabels->item($i)->nodeValue, $num);
			$pin[$i] = $this->loginData['customerPIN'][implode($num) - 1];
			preg_match('!\d+!', $charLabels->item($i + 3)->nodeValue, $num);
			$pass[$i] = $this->loginData['customerPass'][implode($num) - 1];
		}

		// PIN digit 1,2,3
		$postData['ctl00$mainContent$Tab1$LI6PPEA_edit'] = $pin[0];
		$postData['ctl00$mainContent$Tab1$LI6PPEB_edit'] = $pin[1];
		$postData['ctl00$mainContent$Tab1$LI6PPEC_edit'] = $pin[2];

		// Password char 1,2,3
		$postData['ctl00$mainContent$Tab1$LI6PPED_edit'] = $pass[0];
		$postData['ctl00$mainContent$Tab1$LI6PPEE_edit'] = $pass[1];
		$postData['ctl00$mainContent$Tab1$LI6PPEF_edit'] = $pass[2];

		// Submit button
		$postData['ctl00$mainContent$Tab1$next_text_button_button'] = 'Next';

		// This will set the auth cookie, but we still can't view
		// the account overview for whatever reason.
		$this->easycurl($postURL, TRUE, http_build_query($postData));
	}

	/**
	 * login
	 *
	 * @return null
	 */
	public function login() {

		$html_step1 = $this->login_step1();
		$html = $this->login_step2($html_step1);

		$this->setLoggedIn();
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
		// We still need to parse and send the statements form.
		$html = $this->easycurl(self::$URLS['statements']);

		// Grab the submit URL for the statements form.
		$postURL = self::$URL_PREFIX.'/'
			.$this->easyxpath($html, self::EZX_FORM)
			->item(0)
			->getAttribute('action');

		// Set this by default, in case there are multiple pages.
		$postURL .= '&showAll=1';

		// Scrape the hidden values for form submission.
		$inputs = $this->easyxpath($html, self::EZX_HIDDEN);
		foreach ( $inputs as $input ) {
			$postData[$input->getAttribute('name')]
				= $input->getAttribute('value');
		}

		// Select the account by the account number.
		$x_query = '//option[contains(.,"'.$this->loginData['accountNum'].'")]';
		$acOption = $this->easyxpath($html, self::EZX_OTHER, $x_query)
			->item(0)
			->getAttribute('value');

		// Select the account and the required view.
		$postData['ctl00$mainContent$SS2ACCDDA'] = $acOption;
		$postData['ctl00$mainContent$SS2SPDDA'] = 'W1';
		$postData['ctl00$mainContent$NextButton_button'] = 'View Transactions';

		// Curl it. (Finally) returns the statement, to be scraped.
		$statementHtml = $this->easycurl($postURL, TRUE, http_build_query($postData));

		// Scrape the transaction list...
		$x_query = '//table[@class="ItemTable"]/tbody/tr';
		$txnRows = $this->easyxpath($statementHtml, self::EZX_OTHER, $x_query);

		// ...and put it into an array.
		$transactions = [];
		foreach ( $txnRows as $txnRow ) {
			$txn = array(
				'date'        => strtotime($txnRow->childNodes->item(0)->nodeValue),
				'transType'   => $txnRow->childNodes->item(1)->nodeValue,
				'commentary'  => $txnRow->childNodes->item(2)->nodeValue,
				'amount'      => floatval($txnRow->childNodes->item(3)->nodeValue)
				- floatval($txnRow->childNodes->item(4)->nodeValue),
				'balance'     => $txnRow->childNodes->item(5)->nodeValue
			);
			$transactions[] = $txn;
		}
		$transactions = array_reverse($transactions);
		if ( is_null($n) ) {
			return $transactions;
		} else {
			return array_slice($transactions, 0, $n);
		}
	}

	/**
	 * logout
	 *
	 * @return null
	 */
	public function logout() {

		$this->easycurl(self::$URLS['logout']);

		$this->setLoggedOut();
	}
}

?>
