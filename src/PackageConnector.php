<?php
/**
 * Composer Packages connector
 * Utilize Composer installed packages within Cotonti
 *
 * @package PackageConnector
 * @version 0.2.2
 * @author Andrew `Macik` Matsovkin
 * @copyright Copyright (c) Cotonti Team 2010-2015
 * @license BSD
 */
defined('COT_CODE') or die('Wrong URL');

/**
 * Stores instance of Package connector
 *
 * @var PackageConnector
 */
$cot_packages = null;

/**
 * Common Error handling interface
 */
interface LastErrorInterface
{

	/**
	 * Extracts last error message
	 *
	 * @return string
	 */
	public function getLastError();
}

/**
 * Extension for Error messages l10n
 */
interface CustomLastErrorInterface extends LastErrorInterface
{

	/**
	 * Init class with defined error messages.
	 * Can be used alter messages for l10n
	 *
	 * @param array $messages Messages array
	 * @see $defaultMsg
	 */
	public function messagesInit($messages = null);
}

/**
 * Base class for Error handilng
 */
abstract class LastErrorHandler implements CustomLastErrorInterface
{

	/**
	 *
	 * @var array Error messages in 'id'=>'message' format
	 */
	private $msg = array();

	/**
	 *
	 * @var array Default error messages
	 */
	private $defaultMsg = array();

	/**
	 *
	 * @var string Last error message
	 */
	protected $lastError;

	/**
	 * @var bool is any error
	 */
	public $errors;

	/**
	 *
	 * @var string Error ID
	 */
	protected $lastErrorCode;

	public function __construct($defaultMessages = null)
	{
		if (is_array($defaultMessages)) $this->defaultMsg = $defaultMessages;
		$this->messagesInit();
	}

	public function hasErrors()
	{
		return (bool) $this->lastError;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getLastError()
	{
		$message = $this->lastError;
		$this->lastError = null;
		$this->lastErrorCodeor = null;
		return $message;
	}

	/**
	 * Trigger error for further use of error message
	 *
	 * @param string $messageId Message ID in $msg array
	 * @param array|string $params Addition params to parse as template variables
	 * @return string Error text message
	 */
	public function error($messageId, $params = array())
	{
		$this->lastErrorCode = $messageId;
		$message = $this->getMessage($messageId, $params);
		$this->lastError = $message;
		return $message;
	}

	/**
	 * Returns error message text with variable parsing
	 *
	 * @param string $messageId Message ID in $msg array
	 * @param array $params Addition params to parse as template variables
	 * @return void|string
	 */
	protected function getMessage($messageId, $params = array())
	{
		if (!array_key_exists($messageId, $this->msg)) return "Error: code `$messageId`";
		if (is_string($params) && strpos($params, '=') === false) $params=array($params);
		is_array($params) ? $args = $params : parse_str($params, $args);
		$res = $this->msg[$messageId];
		if (preg_match_all('#\{\$(\w+)\}#', $res, $matches, PREG_SET_ORDER))
		{
			foreach ($matches as $var)
			{
				$varName = $var[1];
				$res = str_replace($var[0], (isset($args[$varName]) ? $args[$varName] : $var[0]), $res);
			}
		}
		return $res;
	}

	/**
	 * {@inheritDoc}
	 */
	public function messagesInit($messages = null)
	{
		if (is_array($messages))
		{
			$this->msg = array_merge($this->msg, $messages);
		}
		else
		{
			$this->messagesInit($this->defaultMsg);
		}
	}

	public function flushErrors()
	{
		unset($this->lastError);
		unset($this->lastErrorCode);
	}
}

class LastError extends LastErrorHandler
{

}

/**
 * Class for Error stack
 */
class LastErrorStack extends LastErrorHandler
{
	protected $errorStack = array();
	protected $stackSize = 5;

	public function setStackSize($int)
	{
		if (is_int($int) && $int > 0) $this->stackSize = $int;
		while (sizeof($this->errorStack) > $this->stackSize)
		{
			array_shift($this->errorStack);
		}
	}

	public function hasErrors()
	{
		return (bool) sizeof($this->errorStack);
	}

	/** (non-PHPdoc)
	 * @see LastErrorHandler::error()
	 */
	public function error($messageId, $params = array()) {
		parent::error($messageId, $params);
		$message = parent::getLastError(); // to unset ErrorCode
		$this->addToStack($messageId, $message);
		return $message;
	}

	/**
	 * Adds message to stack
	 * @param unknown $messageId
	 * @param unknown $message
	 */
	protected function addToStack($messageId, $message)
	{
		while (sizeof($this->errorStack) >= $this->stackSize)
		{
			array_shift($this->errorStack);
		}
		array_push($this->errorStack, array('id'=>$messageId, 'msg'=>$message));
	}


	/** (non-PHPdoc)
	 * Get last message from stack
	 * @see LastErrorHandler::getLastError()
	 */
	public function getLastError() {
		if (sizeof($this->errorStack))
		{
			$message = array_pop($this->errorStack);
			return $message['msg'];
		}
		return false;
	}

	/**
	 * Returns list of all errors
	 * @return array
	 */
	public function getAllErrors()
	{
		$list = array();
		while (sizeof($this->errorStack))
		{
			$message = array_pop($this->errorStack);
			$list[] = $message['msg'];
		}
		return $list;
	}
}

/**
 * Simple class to utilize Composer installed packages within Cotonti
 */
class PackageConnector implements Serializable, CustomLastErrorInterface
{

	const LOCK_FILE = 'composer.lock';
	// Name of lock file
	const COMPOSER_FILE = 'composer.json';
	// Name of Composer package settings file

	/**
	 * @var string Base path for locating Composer files (json/lock)
	 */
	protected $basePath = '';

	/**
	 * @var string Vendor folder used by Composer
	 */
	protected $vendorDir = '';

	/**
	 * @var boolean Lock state of install
	 */
	protected $locked = false;

	/**
	 * @var string Path to `composer.lock` file
	 */
	protected $lockFilePath = '';

	/**
	 * @var string Path to `composer.json` file
	 */
	protected $composerFilePath = '';

	/**
	 * @var array Stored data from composer.lock
	 */
	protected $storedLock = null;

	/**
	 * @var array Stored data from composer.json
	 */
	protected $storedJson = null;

	/**
	 * @var hash to identify current state of Composer data files
	 */
	protected $stateHash = '';

	/**
	 * @var array Config block data from composer.json
	 */
	protected $config = array();

	/**
	 * @var string Full path to Composer autoload file
	 */
	protected $autoloadFile = '';

	/**
	 *
	 * @var array Default error messages
	 */
	private static $defaultMsg = array(
		'format_error' => 'Can not get data from "{$0}". Check its format.',
		'not_setuped' => 'Connector not setuped yet. Use PackageConnector::setup() method.',
		'no_autoload' => 'Can not locate autoload file "{$0}".',
		'no_package' => 'No composer.json found for package "{$0}".',
		'no_lock' => 'No composer.lock data found.',
		'no_installed' => "No installed packages found.",
		'not_found' => 'File not found "{$0}" or empty.',
		'not_utf8' => '"{$0}" is not UTF-8, could not parse as JSON.',
		'not_json' => '"{$file}" does not contain valid JSON: {$msg}'
	);

	/**
	 *
	 * @var array Data last parsed from `composer.json`
	 */
	private $composerData;

	/**
	 *
	 * @var array Data last parsed from `composer.lock`
	 */
	private $lockData;

	/**
	 *
	 * @var boolean Initialization state
	 */
	protected $setuped;

	/**
	 *
	 * @var PackageInfo
	 */
	private $packageInfo;

	/**
	 * Error handling class
	 *
	 * @var LastError;
	 */
	public $errorHandler;

	/**
	 * Simple class constructor
	 */
	public function __construct()
	{
		$this->init();
	}

	/**
	 * Does base internal initialization
	 */
	protected function init()
	{
		$this->errorHandler = new LastErrorStack(self::$defaultMsg);
		$this->packageInfo = new InstalledPackageInfo($this);
	}
	/**
	 * (non-PHPdoc)
	 *
	 * @see CustomLastErrorInterface::messagesInit()
	 */
	public function messagesInit($messages = null)
	{
		$this->errorHandler->messagesInit($messages);
	}

	/**
	 * (non-PHPdoc)
	 *
	 * @see LastErrorInterface::getLastError()
	 */
	public function getLastError()
	{
		return $this->errorHandler->getLastError();
	}

	/**
	 * Setup all internal with composer data
	 *
	 * @param string $configFilesPath Path to composer.json
	 * @return boolean
	 */
	public function setup($configFilesPath=null)
	{
		$this->flush();		if (!$configFilesPath) $configFilesPath = '.'; // looking for composer.json in site root folder
		$composerFile = $configFilesPath . '/' . $this::COMPOSER_FILE;
		$composerFile = $this->normalizePath($composerFile);
		$composerJson = $this->getJson($composerFile);
		if (is_array($composerJson) && sizeof($composerJson))
		{
			$this->basePath = $configFilesPath;
			$this->composerFilePath = $composerFile;
			$this->composerData = $composerJson;
			$this->locked = $this->isLocked();
			$this->vendorDir = $this->normalizePath($this->basePath . '/' . ($this->composerData['config']['vendor-dir'] ?  : 'vendor'));
			$autoloadFile = $this->normalizePath($this->vendorDir . '/' . 'autoload.php');
			$this->autoloadFile = is_file($autoloadFile) ? $autoloadFile : null;
			$this->storeData();
			$this->stateFix();
			$this->setuped = true;
		}
		else
		{
			if (!$this->errorHandler->hasErrors()) $this->errorHandler->error('format_error', $composerFile);
			$this->flush();
			$this->setuped = false;
		}
		return $this->setuped;
	}

	/**
	 * Stores sensible data from composer files
	 */
	private function storeData()
	{
		if (is_array($this->composerData) && sizeof($this->composerData))
		{
			$allowedKeys = array(
				'require',
				'repositories',
				'config',
				'extra'
			);
			foreach ($this->composerData as $key => $data)
			{
				if (in_array(strtolower($key), $allowedKeys))
				{
					$this->storedJson[strtolower($key)] = $data;
				}
			}
		}
		if (is_array($this->lockData) && sizeof($this->lockData))
		{
			$allowedKeys = array(
				'hash',
				'packages'
			);
			foreach ($this->lockData as $key => $data)
			{
				if (in_array(strtolower($key), $allowedKeys))
				{
					$this->storedLock[strtolower($key)] = $data;
				}
			}
		}
	}

	/**
	 * Resets internal properties
	 *
	 * @param string $propName Property name to reset. Resets all if no name given.
	 */
	protected function reset($propName = null)
	{
		if ($propName)
		{
			unset($this->$propName);
		}
		else
		{
			$reflect = new ReflectionClass($this);
			$properties = $reflect->getProperties(ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE);
			foreach ($properties as $property)
			{
				if (!$property->isStatic())
				{
					$propName = $property->name;
					unset($this->$propName);
				}
			}
		}
	}

	/**
	 * Flushes operational data
	 */
	protected function flush(){
		$reflect = new ReflectionClass($this);
		$properties = $reflect->getProperties(ReflectionProperty::IS_PROTECTED);
		$defaultValues = $reflect->getDefaultProperties();
		foreach ($properties as $property)
		{
			$propName = $property->name;
			if (array_key_exists($propName, $defaultValues))
			{
				$this->$propName = $defaultValues[$propName];
			}
			else
			{
				unset($this->$propName);
			}

		}
		$this->composerData = array();
		$this->lockData = array();
		$this->packageInfo->resetSelected();
		$this->errorHandler->flushErrors();
	}
	/**
	 * Exports important class data to array.
	 * Used for further serialization
	 *
	 * @return array Class data
	 */
	protected function export()
	{
		$noExport = array();
		$classData = array();
		$reflect = new ReflectionClass($this);
		$properties = $reflect->getProperties(ReflectionProperty::IS_PROTECTED);
		foreach ($properties as $property)
			{
			$propName = $property->name;

			if (!in_array($propName, $noExport))
			{
				$classData[$propName] = $this->$propName;
			}
		}
		return $classData;
	}

	/**
	 * Imports (implodes) data into the Class. Uses only defined properties list
	 *
	 * @param array $dataData to import in `property_name => value` format
	 */
	protected function import($serializedData)
	{
		$reflect = new ReflectionClass($this);
		$data = unserialize($serializedData);
		foreach ($data as $property => $value)
		{
			if ($reflect->hasProperty($property)) $this->$property = $value;
		}
	}

	public function serialize()
	{
		return serialize($this->export());
	}

	/** (non-PHPdoc)
	 * @see Serializable::unserialize()
	 */
	public function unserialize($serializedData)
	{
		$this->init();
		$this->import($serializedData);
		$packagesData = $this->getPackagesData();
		if (isset($packagesData))
		{
			$this->packageInfo->initPackagesData($packagesData);
		}
	}

	/**
	 * Connect Composer generated autoloader
	 *
	 * @return boolean
	 */
	public function connectAutoloader()
	{
		if (!$this->setuped) {
			//$this->setup();
			$this->errorHandler->error('not_setuped');
			return false;
		}
		$autoloadFile = $this->autoloadFile;
		if (is_file($autoloadFile))
		{
			return require_once $autoloadFile;
		}
		$this->errorHandler->error('no_autoload', $autoloadFile);
		return false;
	}

	/**
	 * Returns list of installed packages
	 */
	public function listInstalled()
	{
	}

	/**
	 * Simple check whether Package is installed via composer
	 *
	 * @param string $packageName Package name in Vendor/Package format
	 * @param boolean $autoloadCheck Force autoload file exists check
	 */
	public function isInstalled($packageName, $autoloadCheck = false)
	{
		$this->lastError = null;
		if (!$this->isExists($packageName))
		{
			$this->errorHandler->error('no_package', $packageName);
			return false;
		}
		if (!$skipAutoloadCheck && ($autoloadFile = $this->autoloadFile) && !is_file($autoloadFile))
		{
			$this->errorHandler->error('no_autoload', $autoloadFile);
			return false;
		}

		if (!$this->lockFilePath)
		{
			$this->errorHandler->error('no_lock');
			return false;
		}

		$installedPackages = $this->getPackagesData();
		if (!$installedPackages)
		{
			$this->errorHandler->error('no_installed');
			return false;
		}
		foreach ($installedPackages as $package)
		{
			if ($package['name'] == $packageName) return $package['version'];
		}
		return false;
	}

	/**
	 * Simple check is package located in target folder
	 *
	 * Used to check packages served within Cotonti distribution or
	 * web components manually placed by user.
	 *
	 * Warning! Actually it checks only `composer.json` of a package
	 * for existence and not package integrity or composer settings.
	 *
	 * @param string $packageName Package name in Vendow/Package format
	 * @param string $vendorPath user defined base Vendor folder. If not
	 *        defined then user from composer.json
	 */
	public function isExists($packageName, $vendorPath = '')
	{
		if (!$vendorPath) $vendorPath = $this->vendorDir;
		$packageJson = $vendorPath . '/' . $packageName . '/' . $this::COMPOSER_FILE;
		$packageJson = $this->normalizePath($packageJson);
		return is_file($packageJson);
	}

	/*
	 * protected function getComposerData($jsonFile)
	 * {
	 * return $this->getJson($jsonFile);
	 * }
	 */

	/**
	 * Fixes current state and returns its hash
	 *
	 * @return string Current hash
	 */
	protected function stateFix()
	{
		$this->stateHash = $this->stateGet();
		return $this->stateHash;
	}

	/**
	 * Checks whether state is changed
	 *
	 * @return boolean
	 */
	public function stateChanged()
	{
		return $this->stateGet() !== $this->stateHash;
	}

	/**
	 * Calculates hash of current state of project composer files
	 *
	 * @return string State Hash
	 */
	protected function stateGet()
	{
		$stateHash = null;
		if ($this->lockFilePath || $this->isLocked())
		{
			if (!$this->lockFilePath) $this->lockFilePath = $this->composerFilePath . '/' . $this::LOCK_FILE;
			$stateHash = $this->getHash($this->lockFilePath);
		}
		else
		{
			// if we have not lock file so we relies only on composer.json
			$stateHash = $this->getHash($this->composerFilePath);
		}
		return $stateHash;
	}

	/**
	 * Returns modification check hash
	 *
	 * @param string $filePath Path to file
	 * @return string|NULL Hash or Null if file not found
	 */
	protected function getHash($filePath)
	{
		if (is_file($filePath))
		{
			$hash = hash_init('sha256');
			hash_update($hash, filesize($filePath));
			hash_update($hash, filemtime($filePath));
			return hash_final($hash);
		}
		return null;
	}

	/**
	 * Return parsed content of JSON file
	 *
	 * @param string $jsonFile File path
	 * @return Ambigous <mixed, void, boolean>|boolean
	 */
	protected function getJson($jsonFile)
	{
		if ($jsonFile && is_file($jsonFile) && $json = file_get_contents($jsonFile))
		{
			return $this->parseJson($json, $jsonFile);
		}
		$this->errorHandler->error('not_found', $jsonFile);
		return false;
	}

	/**
	 * Parses json string and returns hash.
	 *
	 * @param string $json json string
	 * @param string $file the json file
	 *
	 * @return mixed modified version from original Composer code
	 * @see Composer\Json\JsonFile.php
	 */
	public static function parseJson($json, $file = null)
	{
		if (null === $json)
		{
			return;
		}
		$data = json_decode($json, true);
		if (null === $data && JSON_ERROR_NONE !== json_last_error())
		{
			return self::validateSyntax($json, $file);
		}

		return $data;
	}

	/**
	 * Validates the syntax of a JSON string
	 *
	 * @param string $json
	 * @param string $file
	 * @return bool true on success
	 *
	 *         modified version from original Composer code
	 * @see Composer\Json\JsonFile.php
	 */
	protected static function validateSyntax($json, $file = null)
	{
		$parser = new JsonParser();
		$result = $parser->lint($json);
		if (null === $result)
		{
			if (defined('JSON_ERROR_UTF8') && JSON_ERROR_UTF8 === json_last_error())
			{
				$this->errorHandler->error('not_utf8', $file);
				return false;
			}

			return true;
		}

		$this->errorHandler->error('not_json', array(
			'file' => $file,
			'msg' => $result->getMessage()
		));
		// $result->getDetails()
		return false;
	}

	/**
	 * Normalize a path.
	 * This replaces backslashes with slashes, removes ending
	 * slash and collapses redundant separators and up-level references.
	 *
	 * @param string $path Path to the file or directory
	 * @return string
	 *
	 * @see Composer\Util\Filesystem.php
	 */
	public function normalizePath($path)
	{
		$parts = array();
		$path = strtr($path, '\\', '/');
		$prefix = '';
		$absolute = false;

		if (preg_match('{^([0-9a-z]+:(?://(?:[a-z]:)?)?)}i', $path, $match))
		{
			$prefix = $match[1];
			$path = substr($path, strlen($prefix));
		}

		if (substr($path, 0, 1) === '/')
		{
			$absolute = true;
			$path = substr($path, 1);
		}

		$up = false;
		foreach (explode('/', $path) as $chunk)
		{
			if ('..' === $chunk && ($absolute || $up))
			{
				array_pop($parts);
				$up = !(empty($parts) || '..' === end($parts));
			}
			elseif ('.' !== $chunk && '' !== $chunk)
			{
				$parts[] = $chunk;
				$up = '..' !== $chunk;
			}
		}

		return $prefix . ($absolute ? '/' : '') . implode('/', $parts);
	}

	/**
	 * Returns lock file main data
	 *
	 * @return array
	 */
/*	protected function getLockData()
	{
		if (is_array($this->lockData) && sizeof($this->lockData)) return $this->lockData;
		return false;
	}*/

	/**
	 * Returns info data for specified package
	 *
	 * @param string $packageName Full packagename in `vendor/package` format
	 * @return array|boolean
	 */
	/*public function getPackageInfo($packageName)
	{
		if (!is_array($this->lockData) || !sizeof($this->lockData)) return false;
		$packageName = mb_strtolower($packageName);
		foreach ($this->lockData['packages'] as $package)
		{
			if ($package['name'] == $packageName) return $package;
		}
		return false;
	}*/

	/**
	 * Returns packages data
	 *
	 * @return array|boolean
	 */
	public function getPackagesData()
	{
		$packagesData = null;
		if (is_array($this->lockData) && array_key_exists('packages', $this->lockData))
		{
			$packagesData = $this->lockData['packages'];
		}
		if (is_array($this->storedLock) && array_key_exists('packages', $this->storedLock))
		{
			$packagesData = $this->storedLock['packages'];
		}
		if (is_array($packagesData)) return $packagesData;
		return null;
	}

	/**
	 * Simple checker whether locker were been locked (lockfile found).
	 *
	 * @return bool
	 */
	protected function isLocked()
	{
		$lockFile = $this->normalizePath($this->basePath . '/' . $this::LOCK_FILE);

		if (!file_exists($lockFile))
		{
			return false;
		}

		$this->lockFilePath = $lockFile;
		$data = $this->loadLockData($lockFile);

		$packagesData = $this->getPackagesData();
		if (isset($packagesData))
		{
			$this->packageInfo->initPackagesData($packagesData);
			return true;
		}

		return false;
	}

	/**
	 * Returns array with lock file data.
	 * Store data in lockData property
	 *
	 * @param string $lockFile
	 * @return array Lock file data
	 */
	protected function loadLockData($lockFile)
	{
		$data = $this->getJson($lockFile);
		$this->lockData = $data;
		return $data;
	}

	/**
	 * Represents PackageInfo
	 *
	 * @param string[optional] $packageName Selects package by name
	 * @return InstalledPackageBaseInfo::
	 */
	public function package($packageName = null)
	{
		if ($packageName) $this->packageInfo->select($packageName);
		return $this->packageInfo;
	}
}

/**
 * Interface for Installed packages info
 */
interface InstalledPackageInterface
{

	/**
	 * Returns active (selected) Package short name
	 */
	public function getName();

	/**
	 * Returns active package fullname
	 */
	public function getFullName();
	/*
	 * public function getType();
	 * public function getSourceUrl();
	 * public function getRequires();
	 */
}

/**
 * Abstract class with basics package info routines
 */
abstract class InstalledPackageBaseInfo implements InstalledPackageInterface, CustomLastErrorInterface
{

	const COT_PACKAGE_PLUGIN = 1;

	const COT_PACKAGE_MODULE = 2;

	const COT_PACKAGE_THEME = 4;

	// for native Composer package types
	const COT_PACKAGE_OTHER = 64;

	// all of types
	const COT_PACKAGES = 255;


	protected $fullName;

	protected $vendor;

	protected $name;

	protected $version;

	protected $type;

	/**
	 * Allowed types strings for Cotonti package type
	 *
	 * @var
	 *
	 */
	public static $packageTypes = array(
		self::COT_PACKAGE_PLUGIN => array(
			'cotonti-siena-plugin'
		),
		self::COT_PACKAGE_MODULE => array(
			'cotonti-siena-module'
		),
		self::COT_PACKAGE_THEME => array(
			'cotonti-siena-theme'
		)
	);

	/**
	 *
	 * @var PackageConnector
	 */
	protected $connector;

	private static $defaultMsg = array();

	public $errorHandler;

	/**
	 * All descendants' constructors should call this parent constructor
	 *
	 * @param PackageConnector $connector
	 */
	public function __construct(PackageConnector $connector)
	{
		$this->connector = $connector;
		$this->errorHandler = new LastErrorStack($this->defaultMsg);
	}

	/**
	 * {@inheritDoc}
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * {@inheritDoc}
	 */
	public function getFullName()
	{
		return $this->fullName;
	}

	/**
	 * Checks whether string represents full package name (in vendor/package format)
	 *
	 * @param string $packageName
	 * @return boolean
	 */
	protected function isFullName($packageName)
	{
		return (bool) strpos($packageName, '/');
	}

	/**
	 * Splits full name for vendor / package parts
	 *
	 * @param string $packageName
	 * @return Ambiguous
	 */
	protected function splitName($packageName)
	{
		return preg_split('@\/@', $packageName, 2);
	}

	/**
	 * (non-PHPdoc)
	 *
	 * @see CustomLastErrorInterface::messagesInit()
	 */
	public function messagesInit($messages = null)
	{
		$this->errorHandler->messagesInit($messages);
	}

	/**
	 * (non-PHPdoc)
	 *
	 * @see LastErrorInterface::getLastError()
	 */
	public function getLastError()
	{
		return $this->errorHandler->getLastError();
	}
}

/**
 * Base class to get package info
 */
class InstalledPackageInfo extends InstalledPackageBaseInfo implements CustomLastErrorInterface
{

	/**
	 * Reference for Packages data
	 *
	 * @var array
	 */
	protected $packagesData = array();

	/**
	 * Packages data stored by short names
	 *
	 * @var array
	 */
	protected $packagesByName = array();

	/**
	 * stores hash of name/type queried last time
	 *
	 * @var string
	 */
	private $lastQueryHash = '';

	private $defaultMsg = array(
		'not_found' => 'No package found for given name "{$name}" and type {$type}'
	);

	/**
	 * Active package data
	 *
	 * @var array
	 */
	private $package = null;

	/**
	 * Notes package selection state
	 *
	 * @var boolean
	 */
	private $packageSelected = false;

	public function __construct(PackageConnector $connector, $packagesData = null)
	{
		parent::__construct($connector);
		$this->initPackagesData($packagesData ?  : $this->connector->getPackagesData());
	}

	/**
	 * Inits class with Packages data and rebuild Names list
	 */
	public function initPackagesData($packagesData)
	{
		if (is_array($packagesData))
		{
			$this->packagesData = $packagesData;
			$this->buildNamesList();
		}
	}

	/**
	 * Builds Name list with package data.
	 * Uses short name as keys
	 */
	private function buildNamesList()
	{
		if ($this->packagesData && is_array($this->packagesData))
		{
			$this->packagesByName = array();
			foreach ($this->packagesData as $packageInfo)
			{
				list($vendor, $package) = $this->splitName($packageInfo['name']);
				if ($package) $this->packagesByName[$package][] = $packageInfo;
			}
			ksort($this->packagesByName);

			foreach ($this->packagesByName as $name => &$packages)
			{
				// addition sort by install time
				if (sizeof($packages) > 1)
				{
					usort($packages,
						function ($a, $b)
						{
							$time_a = strtotime($a['time']);
							$time_b = strtotime($b['time']);
							if ($time_a != $time_b)
							{
								return ($time_a < $time_b) ? -1 : 1;
							}
							return 0;
						});
				}
			}
		}
	}

	/**
	 * Selects package for further
	 *
	 * @param string $packageName
	 * @param int $packageType
	 * @return boolean
	 */
	public function select($packageName, $packageType = null, $force_reselect = false)
	{
		$packageName = mb_strtolower($packageName);
		$hash = hash('sha256', $packageName.'|'.$packageType);
		if ($this->packageSelected && !$force_reselect && ($hash == $this->lastQueryHash)) return true; // already selected
		$this->lastQueryHash = $hash;
		if (!$this->isFullName($packageName))
		{
			$fullPackageName = $this->expandName($packageName, $packageType);
		}
		else
		{
			$fullPackageName = ($this->isInstalled($packageName, $packageType)) ? $packageName : null;
		}
		if ($fullPackageName)
		{
			if ($targetPackage = $this->getInfo($fullPackageName, $packageType))
			{
				$this->package = $targetPackage;
				list($vendor, $package) = $this->splitName($fullPackageName);
				$this->vendor = $vendor;
				$this->name = $package;
				$this->fullName = $fullPackageName;
				$this->type = $targetPackage['type'];
				$this->version = $targetPackage['version'];
				$this->packageSelected = true;
				return true;
			}
		}
		$this->resetSelection();
		return false;
	}

	/**
	 * Returns package selection state
	 */
	public function selected()
	{
		return $this->packageSelected;
	}

	public function resetSelection()
	{
		$resetProperties = array(
			'vendor',
			'name',
			'fullName',
			'type',
			'version',
			'package'
		);
		$reflect = new ReflectionClass($this);
		$defaultValues = $reflect->getDefaultProperties();

		foreach ($resetProperties as $propName)
		{
			if (array_key_exists($propName, $defaultValues))
			{
				$this->$propName = $defaultValues[$propName];
			}
			else
			{
				unset($this->$propName);
			}
		}
		$this->packageSelected = false;
	}

	/**
	 * Returns installed package info
	 *
	 * @param string $fullPackageName
	 * @param int[optional]|string $typeFilter Package type filter
	 * @see PackageInfo::isType
	 * @return array|bool Package data or FALSE in case it not souond
	 */
	public function getInfo($fullPackageName, $typeFilter = null)
	{
		if (!is_array($this->packagesData)) return false;
		foreach ($this->packagesData as $packageInfo)
		{
			if ($packageInfo['name'] == $fullPackageName && self::isType($packageInfo['type'], $typeFilter))
			{
				return $packageInfo;
			}
		}
		return false;
	}

	/**
	 * Checks whether package is installed
	 *
	 * @param string $fullPackageName Full package name
	 */
	private function isInstalled($fullPackageName, $typeFilter = null)
	{
		return ($this->getInfo($fullPackageName, $typeFilter) !== false);
	}

	/**
	 * Expands installed package short name to full vendor/package format
	 *
	 * @param string $shortName
	 * @param int[optional] $typeFilter Package type filter. It's configured using the PackageInfo constants, and defaults to all package types
	 * @return string Full name
	 */
	protected function expandName($shortName, $typeFilter = null)
	{
		$shortName = mb_strtolower($shortName);
		if ($this->packagesByName && is_array($this->packagesByName))
		{
			if (array_key_exists($shortName, $this->packagesByName) && $foundPackages = $this->packagesByName[$shortName])
			{
				// only one package with given short name installed
				if (1 == sizeof($foundPackages) && !$typeFilter)
				{
					$desiredPackage = array_shift($foundPackages);
					return $desiredPackage['name'];
				}
				// for packages with same short name does addition filtering
				foreach ($foundPackages as $package)
				{
					if (self::isType($package['type'], $typeFilter)) return $package['name'];
				}
			}
		}
		return false;
	}

	/**
	 * Checks whether type meets filter
	 *
	 * @param string $type
	 * @param int|string $filter Filter for type check. It's configured using
	 *        the PackageInfo constants or exact type string. Defaults to all package types
	 */
	protected static function isType($type, $filter = null)
	{
		if (is_null($filter) || !$filter) return true;
		if (is_string($filter))
		{
			return $type == mb_strtolower($filter);
		}
		elseif (is_int($filter))
		{
			if ($filter > InstalledPackageBaseInfo::COT_PACKAGE_OTHER) return true;
			$cot_type = false;
			foreach (InstalledPackageBaseInfo::$packageTypes as $typeId => $allowedTypes)
			{
				if (in_array($type, $allowedTypes)) $cot_type = true;
				if (($typeId & $filter) && in_array($type, $allowedTypes)) return true;
			}
			if (!$cot_type && InstalledPackageBaseInfo::COT_PACKAGE_OTHER <= $filter)
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Magic method to get Package info data
	 *
	 * @param string $propName
	 * @return NULL|property data
	 */
	public function __get($propertyName)
	{
		if ($this->packageSelected)
		{
			$composerPropertyName = $this->expandToPropertyName($propertyName);
			return isset($this->package[$composerPropertyName]) ? $this->package[$composerPropertyName] : null;
		}
		return null;
	}

	/**
	 * Magic method to handle get* calls for selected package data
	 *
	 * @param string $methodName
	 * @return NULL|property data
	 */
	public function __call($methodName, array $params)
	{
		if (substr($methodName, 0, 3) == 'get')
		{
			// convert getSomePropertyName to some-property-name
			$propName = $this->expandToPropertyName(substr($methodName, 3));
			if (is_array($this->package) && array_key_exists($propName, $this->package))
			{
				return $this->package[$propName];
			}
		}
		return null;
	}

	/**
	 * Convert «camel cased» name to Composer property styled one.
	 * Example: `someKindProperty` would be converted to `some-kind-property`
	 *
	 * @param $camelCased Property/method name in Camel case
	 * @return string
	 */
	protected function expandToPropertyName($camelCased)
	{
		return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $camelCased));
	}
}

