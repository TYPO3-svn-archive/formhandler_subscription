<?php

/*                                                                        *
 * This script belongs to the TYPO3 extension "formhandler_subscription". *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * A class providing helper functions for auth codes stored in the database
 */
class Tx_FormhandlerSubscription_Utils_AuthCode {

	const ACTION_ENABLE_RECORD = 'enableRecord';
	const ACTION_ACCESS_FORM = 'accessForm';

	/**
	 * Globals of the formhandler extension
	 *
	 * @var Tx_Formhandler_Globals
	 */
	protected $globals;

	/**
	 * This string is parsed by strtotime and specifies
	 * the timestamp when the auth codes are expired
	 *
	 * @var string
	 */
	protected $authCodeExpiryTime = '1 day ago';

	/**
	 * Contains the timestamp that is generated by
	 * parsing $authCodeExpiryTime with strtotime
	 *
	 * @var int
	 */
	protected $authCodeExpiryTimestamp;

	/**
	 * The table that contains the auth codes
	 *
	 * @var string
	 */
	protected $authCodeTable = 'tx_formhandler_subscription_authcodes';

	/**
	 * If this is true every time an auth code is read from the
	 * database expired auth codes will be deleted from the database
	 *
	 * @var bool
	 */
	protected $autoDeleteExpiredAuthCodes = TRUE;

    /**
     * Formhandler utility functions
     *
     * @var Tx_Formhandler_UtilityFuncs
     */
	protected $formhandlerUtils;

	/**
	 * Stores the current instance of the utils class
	 *
	 * @var Tx_FormhandlerSubscription_Utils_AuthCode
	 */
	static protected  $instance = NULL;

	/**
	 * Singleton for getting the current instance of the utils class
	 *
	 * @static
	 * @return Tx_FormhandlerSubscription_Utils_AuthCode
	 */
	static public function getInstance() {
		if (self::$instance === NULL) {
			self::$instance = new Tx_FormhandlerSubscription_Utils_AuthCode();
		}
		return self::$instance;
	}

	/**
	 * Initializes the formhandler globals and the expiry timestamp
	 */
	public function __construct() {

		$this->formhandlerUtils = Tx_Formhandler_UtilityFuncs::getInstance();
		$this->globals = Tx_Formhandler_Globals::getInstance();

		$settings = $this->globals->getSettings();
		if (array_key_exists('authCodeDBExpiryTime', $settings)) {
			$this->setAuthCodeExpiryTime($settings['authCodeDBExpiryTime']);
		}
		else {
			$this->setAuthCodeExpiryTime($this->authCodeExpiryTime);
		}

		if (array_key_exists('authCodeDBAutoDeleteExpired', $settings)) {
			$this->autoDeleteExpiredAuthCodes = intval($settings['authCodeDBAutoDeleteExpired']);
		}
	}

	/**
	 * Removes all auth codes that reference the given record
	 *
	 * @param $table string
	 * @param $uidField string
	 * @param $uid string
	 */
	public function clearAuthCodes($table, $uidField, $uid) {

			// remove old entries for the same record
		$GLOBALS['TYPO3_DB']->exec_DELETEquery(
			$this->authCodeTable,
			'reference_table=' .  $GLOBALS['TYPO3_DB']->fullQuoteStr($table, $this->authCodeTable) .
			'AND reference_table_uid_field=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($uidField, $this->authCodeTable) .
			'AND reference_table_uid=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($uid, $this->authCodeTable)
		);
	}

	/**
	 * Removes all auth codes that reference the given record
	 *
	 * @param array $authCodeRow
	 */
	public function clearAuthCodesByRowData($authCodeRow) {
		$this->clearAuthCodes(
			$authCodeRow['reference_table'],
			$authCodeRow['reference_table_uid_field'],
			$authCodeRow['reference_table_uid']
		);
	}

	/**
	 * Checks if the given action is valid
	 *
	 * @param string $action the action that should be checked
	 * @throws Exception if action is invalid
	 */
	public function checkAuthCodeAction($action) {
		switch ($action) {
			case Tx_FormhandlerSubscription_Utils_AuthCode::ACTION_ENABLE_RECORD:
			case Tx_FormhandlerSubscription_Utils_AuthCode::ACTION_ACCESS_FORM:
				break;
			default:
				throw new Exception("Invalid auth code action: " . $action);
				break;
		}
	}

	/**
	 * Generates a new auth code based on the given row data and clears
	 * all other auth codes that reference the same row
	 *
	 * @param array $row
	 * @param string $action
	 * @param string $table
	 * @param string $uidField
	 * @param string $hiddenField
	 * @return string
	 */
	public function generateAuthCode($row, $action, $table, $uidField, $hiddenField) {

		$serializedRowData = serialize($row);
		$authCode = t3lib_div::getRandomHexString(16);
		$authCode = md5($serializedRowData . $authCode);
		$time = time();

		$this->clearAuthCodes($table, $uidField, $row[$uidField]);

		$authCodeInsertData = array(
			'pid' => '',
			'tstamp' => $time,
			'reference_table' => $table,
			'reference_table_uid_field' => $uidField,
			'reference_table_uid' => $row[$uidField],
			'reference_table_hidden_field' => $hiddenField,
			'action' => $action,
			'serialized_auth_data' => $serializedRowData,
			'auth_code' => $authCode
		);

		$GLOBALS['TYPO3_DB']->exec_INSERTquery(
			$this->authCodeTable,
			$authCodeInsertData
		);

		return $authCode;
	}

	/**
	 * Tries to read the auth code from the GET/POST data array or
	 * from the session.
	 *
	 * @return string
	 */
	public function getAuthCode() {

		$authCode = '';

			// We need to use the global GET/POST variables because if
			// the form is not submitted $this->gp will be empty
			// because Tx_Formhandler_Controller_Form::reset
			// is called
		$formValuesPrefix = $this->globals->getFormValuesPrefix();
		if (empty($formValuesPrefix)) {
			$authCode = t3lib_div::_GP('authCode');
		} else {
			$gpArray = t3lib_div::_GP($formValuesPrefix);
			if (is_array($gpArray) && array_key_exists('authCode', $gpArray)) {
				$authCode = $gpArray['authCode'];
			}
		}

		if (empty($authCode)) {
			$authCode = $this->getAuthCodeFromSession();
		}

		return $authCode;
	}

	/**
	 * Retrieves the data of the given auth code from the database. Before
	 * executing the query to get the auth code data expired auth codes
	 * are deleted from the database if this is not disabled in the settings.
	 *
	 * @param string $authCode the submitted auth code
	 * @return NULL|array NULL if no data was found, otherwise an associative array of the auth code data
	 */
	public function getAuthCodeDataFromDB($authCode) {

		if ($this->autoDeleteExpiredAuthCodes) {
			$this->deleteExpiredAuthCodesFromDatabase();
		}

		$authCodeData = NULL;

		$authCode = $GLOBALS['TYPO3_DB']->fullQuoteStr($authCode, $this->authCodeTable);
		$query = $GLOBALS['TYPO3_DB']->SELECTquery(
			'*',
			$this->authCodeTable,
			'auth_code=' . $authCode . ' AND tstamp > ' . $this->authCodeExpiryTimestamp
		);

		$this->formhandlerUtils->debugMessage('Trying to read auth code data from database');
		$this->formhandlerUtils->debugMessage('sql_request', array($query));
		$res = $GLOBALS['TYPO3_DB']->sql_query($query);
		if ($GLOBALS['TYPO3_DB']->sql_error()) {
			$this->formhandlerUtils->debugMessage('error', array($GLOBALS['TYPO3_DB']->sql_error()), 3);
		}

		if ($res && $GLOBALS['TYPO3_DB']->sql_num_rows($res)) {

			$authCodeData = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);

				// when the auth code was used successfully refresh the timestamp
				// to prevent the user from running into an error after successfully
				// accessing and filling out a protected form
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
				$this->authCodeTable,
				'uid=' . $authCodeData['uid'],
				array('tstamp' => time())
			);
		}

		$GLOBALS['TYPO3_DB']->sql_free_result($res);

		return $authCodeData;
	}

	/**
	 * Reads the data of the record that is referenced by the auth code
	 * from the database
	 *
	 * @param $authCodeData
	 * @return NULL|array NULL if no data was found, otherwise an associative array of the record data
	 */
	public function getAuthCodeRecordFromDB($authCodeData) {

		$authCodeRecord = NULL;

		$table = $authCodeData['reference_table'];
		$uidField = $authCodeData['reference_table_uid_field'];
		$uid = $GLOBALS['TYPO3_DB']->fullQuoteStr($authCodeData['reference_table_uid'], $table);

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*', $table, $uidField . '=' . $uid);
		if ($res && $GLOBALS['TYPO3_DB']->sql_num_rows($res)) {
			$authCodeRecord = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
		}

		$GLOBALS['TYPO3_DB']->sql_free_result($res);

		return $authCodeRecord;
	}

	/**
	 * Stores the given auth code in the session
	 *
	 * @param string $authCode
	 */
	public function storeAuthCodeInSession($authCode) {

		$sesAuthCode = $GLOBALS['TSFE']->fe_user->getKey('ses', 'formhandler_auth_code');

			// Performance: Only update the auth code in the session if it is
			// not already stored
		if ($sesAuthCode !== $authCode) {
			$GLOBALS['TSFE']->fe_user->setKey('ses', 'formhandler_auth_code', $authCode);
			$GLOBALS['TSFE']->fe_user->storeSessionData();
		}
	}

	/**
	 * Deletes the records that is referenced by the auth code from
	 * the database
	 *
	 * @param array $authCodeData
	 * @param bool $markAsDeleted
	 */
	public function removeAuthCodeRecordFromDB($authCodeData, $markAsDeleted = FALSE) {

		$table = $authCodeData['reference_table'];
		$uidField = $authCodeData['reference_table_uid_field'];
		$uid = $GLOBALS['TYPO3_DB']->fullQuoteStr($authCodeData['reference_table_uid'], $table);

		t3lib_div::loadTCA($table);

		if ($markAsDeleted && array_key_exists('delete', $GLOBALS['TCA'][$table]['ctrl'])) {
			$deleteColumn = $GLOBALS['TCA'][$table]['ctrl']['delete'];
			$fieldValues[$deleteColumn] = 1;
			$GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $uidField . '=' . $uid, $fieldValues);
		} else {
			$GLOBALS['TYPO3_DB']->exec_DELETEquery($table, $uidField . '=' . $uid);
		}
	}

	/**
	 * Tries to read the auth code from the session
	 *
	 * @return string
	 */
	public function getAuthCodeFromSession() {
		return $GLOBALS['TSFE']->fe_user->getKey('ses', 'formhandler_auth_code');
	}

	/**
	 * Removes the auth code from the session
	 */
	public function clearAuthCodeFromSession() {
		$GLOBALS['TSFE']->fe_user->setKey('ses', 'formhandler_auth_code', NULL);
		$GLOBALS['TSFE']->fe_user->storeSessionData();
	}

	/**
	 * Clears the auth code from the given $gp array and
	 * the global $gp array
	 *
	 * @param array $gp
	 * @return array
	 */
	public function clearAuthCodeFromGP($gp) {

		$globalGP = $this->globals->getGP();
		unset($globalGP['authCode']);
		unset($globalGP['authCodeData']);
		unset($globalGP['authCodeRecord']);
		$this->globals->setGP($globalGP);

		unset($gp['authCode']);
		unset($gp['authCodeData']);
		unset($gp['authCodeRecord']);
		return $gp;
	}

	/**
	 * Removes all auth codes from the database where the tstamp
	 * is older than the allowed timestamp defined in
	 * expiredAuthCodeTimestamp
	 */
	public function deleteExpiredAuthCodesFromDatabase() {

		$GLOBALS['TYPO3_DB']->exec_DELETEquery(
			$this->authCodeTable,
			'tstamp < ' . $this->authCodeExpiryTimestamp
		);
	}

	/**
	 * Sets a new auth code expiry time, if you want to use it you have
	 * to call it before running getAuthCodeDataFromDBI() or
	 * deleteExpiredAuthCodesFromDatabase()
	 *
	 * @param string $authCodeExpiryTime Time that will be parsed with strtotime
	 * @throws Exception if string can not be parsed
	 */
	public function setAuthCodeExpiryTime($authCodeExpiryTime) {

		$authCodeExpiryTimestamp = strtotime($authCodeExpiryTime);
		if ($authCodeExpiryTimestamp === FALSE) {
			throw new Exception('An invalid auth code expiry time was provided: ' . $authCodeExpiryTime);
		}

		$this->authCodeExpiryTime = $authCodeExpiryTime;
		$this->authCodeExpiryTimestamp = $authCodeExpiryTimestamp;
	}
}
?>