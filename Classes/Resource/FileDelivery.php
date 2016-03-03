<?php
namespace Bitmotion\NawSecuredl\Resource;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2005-2007 Dietrich Heise (typo3-ext(at)bitmotion.de)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;

/**
 * @author Dietrich Heise <typo3-ext(at)bitmotion.de>
 * @author Helmut Hummel <typo3-ext(at)bitmotion.de>
 */
class FileDelivery {

	/**
	 * @var array
	 */
	protected $extensionConfiguration = array();

	/**
	 * @var \TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication
	 */
	protected $feUserObj;

	/**
	 * @var integer
	 */
	protected $fileSize;

	/**
	 * @var integer
	 */
	protected $logRowUid;

	/**
	 * @var integer
	 */
	protected $userId;

	/**
	 * @var string
	 */
	protected $userGroups;

	/**
	 * @var integer
	 */
	protected $expiryTime;

	/**
	 * @var string
	 */
	protected $hash;

	/**
	 * @var string
	 */
	protected $file;

	/**
	 * @var string
	 */
	protected $data;

	/**
	 * @var string
	 */
	protected $calculatedHash;

	/**
	 * @var \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected $databaseConnection;

	/**
	 * Check the access rights
	 */
	function __construct() {
		$this->extensionConfiguration = $this->getExtensionConfiguration();

		$this->userId = intval(GeneralUtility::_GP('u'));
		if (!$this->userId) {
			$this->userId = 0;
		}

		$this->userGroups = GeneralUtility::_GP('g');
		if (strlen($this->userGroups) === 0) {
			$this->userGroups = 0;
		}

		$this->hash = GeneralUtility::_GP('hash');
		$this->expiryTime = GeneralUtility::_GP('t');
		$this->file = GeneralUtility::_GP('file');

		$this->data = $this->userId . $this->userGroups . $this->file . $this->expiryTime;
		$this->calculatedHash = $this->getHash($this->file, $this->userId, $this->userGroups, $this->expiryTime);

		// Hook for init:
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['init'])) {
			$_params = array(
				'pObj' => $this,
				'userId' => &$this->userId,
				'userGroups' => &$this->userGroups,
				'file' => &$this->file,
				'expiryTime' => &$this->expiryTime,
				'hash' => &$this->hash,
				'calculatedHash' => &$this->calculatedHash,
			);
			foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['init'] as $_funcRef)   {
				GeneralUtility::callUserFunction($_funcRef, $_params, $this);
			}
		}

		if (!$this->hashValid()) {
			$this->exitScript('Hash invalid! Access denied!');
		}

		if ($this->expiryTimeExceeded()){
			$this->exitScript('Link Expired. Access denied!');
		}

		$this->initializeUserAuthentication();

		if ($this->userId !== 0) {
			if (!$this->checkUserAccess() && !$this->checkGroupAccess()){
				$this->exitScript('Access denied for User!');
			}
		}
	}

	/**
	 *
	 */
	protected function initializeUserAuthentication() {
		$this->feUserObj = EidUtility::initFeUser();
		$this->feUserObj->fetchGroupData();
		$this->databaseConnection = $GLOBALS['TYPO3_DB'];
		// This is obsolete since 6.1 but required for versions before.
		// It can be removed once support for TYPO3 below 6.1 is dropped.
		if (!$this->databaseConnection->isConnected()) {
			$this->databaseConnection->connectDB();
		}
	}

	/**
	 * @return boolean
	 */
	protected function hashValid() {
		return ($this->calculatedHash === $this->hash);
	}

	/**
	 * @return boolean
	 */
	protected function expiryTimeExceeded() {
		return (intval($this->expiryTime) < time());
	}

	/**
	 * @return boolean
	 */
	protected function checkUserAccess() {

		return ($this->userId === (int)$this->feUserObj->user['uid']);
	}

	/**
	 * Returns true if the transmitted group list is identical
	 * to the group list of the current user or both have at least one group
	 * in common.
	 *
	 * @return boolean
	 */
	protected function checkGroupAccess() {
		$accessAllowed = FALSE;
		$falSupportEnabled = !empty($this->extensionConfiguration['enableFileAbstractionLayerHandling']);
		if (empty($this->extensionConfiguration['enableGroupCheck']) && !$falSupportEnabled) {
			return FALSE;
		}

		if (!$falSupportEnabled && !empty($this->extensionConfiguration['groupCheckDirs']) && !preg_match('/' . $this->softQuoteExpression($this->extensionConfiguration['groupCheckDirs']) . '/', $this->file)) {
			return FALSE;
		}

		$transmittedGroups = GeneralUtility::intExplode(',', $this->userGroups);
		$actualGroups = array_unique(array_map('intval', $this->feUserObj->groupData['uid']));
		sort($actualGroups);
		$excludedGroups = GeneralUtility::intExplode(',', $this->extensionConfiguration['excludeGroups']);
		$checkableGroups = array_diff($actualGroups, $excludedGroups);

		if ($actualGroups === $transmittedGroups) {
			return TRUE;
		}

		if ($falSupportEnabled) {
			return FALSE;
		}

		// TODO: This loosens the permission check to an extend which might lead to unexpected file access.
		// We may need to remove it or at least make it configurable
		foreach ($checkableGroups as $actualGroup) {
			if (in_array($actualGroup, $transmittedGroups, TRUE)) {
				$accessAllowed = TRUE;
				break;
			}
		}

		return $accessAllowed;
	}

	/**
	 * Output the requested file
	 */
	public function deliver() {
		$file = GeneralUtility::getFileAbsFileName(ltrim($this->file, '/'));
		$fileName = basename($file);
		// This is a workaround for a PHP bug on Windows systems:
		// @see http://bugs.php.net/bug.php?id=46990
		// It helps for filenames with special characters that are present in latin1 encoding.
		// If you have real UTF-8 filenames, use a nix based OS.
		// FIXME: needs to be checked, if the website encoding really is UTF-8 and if UTF-8 filesystem is enabled
		if (TYPO3_OS == 'WIN') {
			$file = utf8_decode($file);
		}

		// Hook for pre-output:
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['preOutput'])) {
			$_params = array('pObj' => &$this);
			foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['preOutput'] as $_funcRef)   {
				GeneralUtility::callUserFunction($_funcRef,$_params,$this);
			}
		}

		if (file_exists($file)) {

			$this->fileSize = filesize($file);

			$this->logDownload(0);

			$strFileExtension = $this->getFileExtensionByFilename($file);

			$forcedownload = FALSE;

			if ((bool)$this->extensionConfiguration['forcedownload'] === TRUE){
				$forcetypes = GeneralUtility::trimExplode("|", $this->extensionConfiguration['forcedownloadtype']);

				// Handle the regex
				foreach ($forcetypes as &$forcetype) {
					if (preg_match('/\?/',$forcetype)) {
						$position = strpos($forcetype,'?');
						$start = $position - 1;
						$end = $position + 1;
						array_push($forcetypes, substr($forcetype,0,$start) . substr($forcetype,$end));
						$forcetype = str_replace('?', '',$forcetype);
					}
				}

				if (is_array($forcetypes)){
					if (in_array($strFileExtension, $forcetypes)) {
						$forcedownload = TRUE;
					}
				}
			}

			$strMimeType = $this->getMimeTypeByFileExtension($strFileExtension);

			// Hook for output:
			// TODO: deprecate this hook?
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['output'])) {
				$_params = array(
					'pObj' => &$this,
					'fileName' => $fileName,
					'fileExtension' => '.' . $strFileExtension, // Add leading dot for compatibility in this hook
					'mimeType' => &$strMimeType,
					'feUser' => $this->feUserObj->user
				);
				foreach($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/naw_securedl/class.tx_nawsecuredl_output.php']['output'] as $_funcRef)   {
					GeneralUtility::callUserFunction($_funcRef, $_params, $this);
				}
			}

			//TODO: Check IE compatibility with these headers
			header('Pragma: private');
			header('Expires: 0'); // set expiration time
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Content-Type: ' . $strMimeType);

			$zlib_oc = @ini_get('zlib.output_compression');

			if ( ! $zlib_oc) {
				header('Content-Length: ' . $this->fileSize);
			}

			if ($forcedownload == true){
				header('Content-Disposition: attachment; filename="' . $fileName . '"');
			}else{
				header('Content-Disposition: inline; filename="' . $fileName . '"');
			}

			$strOutputFunction = trim($this->extensionConfiguration['outputFunction']);
			switch ($strOutputFunction) {
				case 'readfile_chunked':
					$this->readfile_chunked($file);
				break;

				case 'fpassthru':
					$handle = fopen($file, 'rb');
					fpassthru($handle);
					fclose($handle);
				break;

				case 'readfile':
				//fallthrough, this is the default case
				default:
					readfile($file);
				break;
			}

			// make sure we can detect an aborted connection, call flush
			ob_flush();
			flush();
			if (!connection_aborted() AND $strOutputFunction !== 'readfile_chunked') {
				$this->logDownload();
			}

		} else {
			print 'File does not exist!';
		}
	}

	/**
	 * Log the access of the file
	 *
	 * @param integer|null $intFileSize
	 */
	protected function logDownload($intFileSize = null)
	{
		if ($this->isLoggingEnabled()) {

			if (is_null($intFileSize)) {
				$intFileSize = $this->fileSize;
			}

			$data_array = array (
				'tstamp' => time(),
				'file_name' => $this->file,
				'file_size' => $intFileSize,
				'user_id' => intval($this->feUserObj->user['uid']),
			);

			if (is_null($this->logRowUid)) {
				$this->databaseConnection->exec_INSERTquery('tx_nawsecuredl_counter', $data_array);
				$this->logRowUid = $this->databaseConnection->sql_insert_id();
			} else {
				$this->databaseConnection->exec_UPDATEquery('tx_nawsecuredl_counter', '`uid`=' . (int)$this->logRowUid, $data_array);
			}

		}
	}


	/**
	 * Returns the configuration array
	 *
	 * @return array
	 */
	protected function getExtensionConfiguration()
	{
		static $extensionConfiguration = array();

		if (!$extensionConfiguration) {
			$extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['naw_securedl']);
		}

		return $extensionConfiguration;
	}

	/**
	 * In some cases php needs the filesize as php_memory, so big files cannot
	 * be transferred. This function mitigates this problem.
	 *
	 * @param string $strFileName
	 * @return bool
	 */
	protected function readfile_chunked($strFileName)
	{
		$chunksize = intval($this->extensionConfiguration['outputChunkSize']); // how many bytes per chunk
		$timeout = ini_get('max_execution_time');
		$bytes_sent = 0;
		$handle = fopen($strFileName, 'rb');
		if ($handle === false) {
			return false;
		}
		while (!feof($handle) && (!connection_aborted()) ) {
			set_time_limit($timeout);
			$buffer = fread($handle, $chunksize);
			print $buffer;
			$bytes_sent += $chunksize;
			ob_flush();
			flush();
			if (substr(TYPO3_branch, 0, 1) === '4') {
				$this->logDownload(\t3lib_div::intInRange($bytes_sent, 0, $this->fileSize,0));
			}else{
				$this->logDownload(MathUtility::forceIntegerInRange($bytes_sent, 0, $this->fileSize));
			}
		}
		return fclose($handle);
	}

	/**
	 * Extracts the file extension out of a complete file name.
	 *
	 * @param string $strFileName
	 * @return string
	 */
	protected function getFileExtensionByFilename($strFileName)
	{
		return GeneralUtility::strtolower(ltrim(strrchr($strFileName, '.'), '.'));
	}

	/**
	 * Looks up the mime type for a give file extension
	 *
	 * @param string $strFileExtension lowercase file extension
	 * @return string mime type
	 */
	protected function getMimeTypeByFileExtension($strFileExtension)
	{
		// Check files with unknown file extensions, if they are image files (currently disabled)
		$checkForImageFiles = FALSE;

		// Array with key/value pairs consisting of file extension (without dot in front) and mime type
		$arrMimeTypes = array(
			// MS-Office filetypes
			'pps' => 'application/vnd.ms-powerpoint',
			'doc' => 'application/msword',
			'xls' => 'application/vnd.ms-excel',
			'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
			'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
			'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
			'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
			'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
			'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xps' => 'application/vnd.ms-xpsdocument',

				// Open-Office filetypes
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ott' => 'application/vnd.oasis.opendocument.text-template',
			'odg' => 'application/vnd.oasis.opendocument.graphics',
			'otg' => 'application/vnd.oasis.opendocument.graphics-template',
			'odp' => 'application/vnd.oasis.opendocument.presentation',
			'otp' => 'application/vnd.oasis.opendocument.presentation-template',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
			'ots' => 'application/vnd.oasis.opendocument.spreadsheet-template',
			'odc' => 'application/vnd.oasis.opendocument.chart',
			'otc' => 'application/vnd.oasis.opendocument.chart-template',
			'odi' => 'application/vnd.oasis.opendocument.image',
			'oti' => 'application/vnd.oasis.opendocument.image-template',
			'odf' => 'application/vnd.oasis.opendocument.formula',
			'otf' => 'application/vnd.oasis.opendocument.formula-template',
			'odm' => 'application/vnd.oasis.opendocument.text-master',
			'oth' => 'application/vnd.oasis.opendocument.text-web',

			// Media file types
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'jpe' => 'image/jpeg',
			'gif' => 'image/gif',
			'png' => 'image/png',
			'mpeg' => 'video/mpeg',
			'mpg' => 'video/mpeg',
			'mpe' => 'video/mpeg',
			'mov' => 'video/quicktime',
			'avi' => 'video/x-msvideo',
			'pdf' => 'application/pdf',
			'svg' => 'image/svg+xml',
			'flv' => 'video/x-flv',
			'swf' => 'application/x-shockwave-flash',
			'htm' => 'text/html',
			'html' => 'text/html',
		);

		// Read all additional MIME types from the EM configuration into the array $strAdditionalMimeTypesArray
		if ($this->extensionConfiguration['additionalMimeTypes']) {

			$strAdditionalFileExtension = '';
			$strAdditionalMimeType = '';
			$arrAdditionalMimeTypeParts = GeneralUtility::trimExplode(',', $this->extensionConfiguration['additionalMimeTypes'], TRUE);

			foreach($arrAdditionalMimeTypeParts as $strAdditionalMimeTypeItem) {
				list($strAdditionalFileExtension, $strAdditionalMimeType) = GeneralUtility::trimExplode('|', $strAdditionalMimeTypeItem);
				if(!empty($strAdditionalFileExtension) && !empty($strAdditionalMimeType)) {
					$strAdditionalFileExtension = GeneralUtility::strtolower($strAdditionalFileExtension);
					$arrMimeTypes[$strAdditionalFileExtension] = $strAdditionalMimeType;
				}
			}

			unset($strAdditionalFileExtension);
			unset($strAdditionalMimeType);
		}

		//TODO: Add hook to be able to manipulate and/or add mime types
		// Check if an specific MIME type is configured for this file extension
		if (array_key_exists($strFileExtension, $arrMimeTypes)) {
			$strMimeType = $arrMimeTypes[$strFileExtension];
			// files bigger than 32MB are now 'application/octet-stream' by default (getimagesize memory_limit problem)
		} else if ($checkForImageFiles && ($this->fileSize < 1024*1024*32)) {
			$arrImageInfos = @getimagesize($this->file);
			$intImageType = (int)$arrImageInfos[2];

			$arrImageMimeType[0] = 'application/octet-stream';
			$arrImageMimeType[1] = 'image/gif';
			$arrImageMimeType[2] = 'image/jpeg';
			$arrImageMimeType[3] = 'image/png';

			$strMimeType = $arrImageMimeType[$intImageType];
		} else {
			$strMimeType = 'application/octet-stream';
		}

		return $strMimeType;
	}

	/**
	 * Checks if logging has been enabled in configuration
	 *
	 * @return bool
	 */
	protected function isLoggingEnabled()
	{
		return (bool)$this->extensionConfiguration['log'];
	}

	/**
	 * @param string $string
	 * @return mixed
	 */
	protected function softQuoteExpression($string) {
		return \Bitmotion\NawSecuredl\Parser\HtmlParser::softQuoteExpression($string);
	}

/*
 * HELPER METHODS
 *
 */

	/**
	 * TODO: Refactor it to a hash service
	 *
	 * @param string $resourceUri
	 * @param integer $userId
	 * @param array<integer> $userGroupIds
	 * @param integer $validityPeriod
	 * @return string
	 */
	protected function getHash($resourceUri, $userId, $userGroupIds, $validityPeriod) {
		if ($this->extensionConfiguration['enableFileAbstractionLayerHandling']) {
			$hashString = $userGroupIds . $resourceUri . $validityPeriod;
		} elseif ($this->extensionConfiguration['enableGroupCheck']) {
			$hashString = $userId . $userGroupIds . $resourceUri . $validityPeriod;
		} else {
			$hashString = $userId . $resourceUri . $validityPeriod;
		}
		return GeneralUtility::hmac($hashString, 'bitmotion_securedownload');
	}

	/**
	 * @param string $message
	 */
	protected function exitScript($message) {
		header('HTTP/1.1 403 Forbidden');
		exit($message);
	}
}

?>