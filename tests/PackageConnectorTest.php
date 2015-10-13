<?php
/*
 * «Package Connector» classes tests
 *
 * (c) Andrew Matsovkin <macik.spb@gmail.com>
 */


abstract class CommonConnector_TestCase extends PHPUnit_Framework_TestCase{
	const FIXTURES_DIR = 'tests/Fixtures/';

	protected static $testingClass;

	/**
	 * Mades selected private or protected Method accessible
	 * @param string $class
	 * @param string $targetMethod
	 * @return ReflectionMethod
	 */
	protected static function setAccessible($class, $targetMethod)
	{
		$method = new ReflectionMethod($class, $targetMethod);
		$method->setAccessible(true);
		return $method;
	}

	/**
	 * Invokes protected method with arguments
	 * @param object $object Source object
	 * @param string $invokedMethod Method name
	 * @param mixed $_ parameters for invoked method
	 */
	protected function invokeProtected($object, $invokedMethod, $_)
	{
		$args = func_get_args();
		array_shift($args);
		array_shift($args);
		$method = $this->setAccessible(get_class($object), $invokedMethod);
		return $method->invokeArgs($object, $args);
	}
}

abstract class LastError_TestCase extends CommonConnector_TestCase {
	protected static $lastError;
	protected static $lastErrorStack;
	protected static $defTestMsg = 'error {$test}';

	public function messagesProvider()
	{
		return array(
			array('errorId', array(), self::$defTestMsg),
			array('unknownId', array(), "Error: code `unknownId`"),
			array('errorId', array('test'=>'test parsing'), "error test parsing"),
		);
	}
}

class LastErrorTest extends LastError_TestCase
{

	public function testConstructor(){
		self::$lastError = new LastError(array('errorId'=>self::$defTestMsg));
		$this->assertAttributeEquals(array('errorId'=>self::$defTestMsg), 'defaultMsg', self::$lastError);
	}

	/**
	 * @depends testConstructor
	 */
	public function testMessagesInit()
	{
		// testing messages reassign
		self::$lastError->messagesInit(array('errorId'=>'Altered message'));
		$this->assertAttributeEquals(array('errorId'=>'Altered message'), 'msg', self::$lastError);
		// testing messages reset
		self::$lastError->messagesInit();
		$this->assertAttributeEquals(array('errorId'=>self::$defTestMsg), 'defaultMsg', self::$lastError);
	}

	/**
	 * @covers LastError::error
	 * @covers LastError::getLastMessage
	 * @depends testMessagesInit
	 * @dataProvider messagesProvider
	 */
	public function testError($messageId, $params, $expected){
		$this->assertEquals($expected, self::$lastError->error($messageId, $params));
		$this->assertEquals($expected, self::$lastError->getLastError());
	}

	public function testFlushErrors() {
		self::$lastError->flushErrors();
		$this->assertAttributeEquals(null, 'lastError', self::$lastError);
		$this->assertAttributeEquals(null, 'lastErrorCode', self::$lastError);
	}
}

class LastErrorStackTest extends LastError_TestCase
{
	protected static $addToStackMethod;

	public static function setUpBeforeClass(){
		parent::setUpBeforeClass();

		self::$lastErrorStack = new LastErrorStack(array('errorId'=> self::$defTestMsg));
		self::$addToStackMethod = self::setAccessible('LastErrorStack', 'addToStack');
	}

	/**
	 * @covers LastErrorStack::error
	 * @covers LastErrorStack::addToStack
	 * @dataProvider messagesProvider
	 */
	public function testError($messageId, $params, $expected)
	{
		$this->assertEquals($expected, self::$lastErrorStack->error($messageId, $params));
	}

	public function testSetStackSize(){
		self::$lastErrorStack->setStackSize(2);
		$this->assertAttributeEquals(2, 'stackSize', self::$lastErrorStack);
	}

	public function errorProvider()
	{
		$messages = self::messagesProvider();
		array_shift($messages); // as we truncated stack to size 2
		return array_reverse($messages);
	}

	/**
	 * @depends testError
	 * @dataProvider errorProvider
	 */
	public function testGetLastError($messageId, $params, $expected)
	{
		$this->assertEquals($expected, self::$lastErrorStack->getLastError());
	}


	/**
	 * @depends testGetLastError
	 */
	public function testEmptyErrorStack()
	{
		// as we pops all errors in testGetLastError test it for empty stack
		$this->assertFalse(self::$lastErrorStack->getLastError());
	}
	/**
	 * @depends testError
	 */
	public function testGetAllErrors()
	{
		self::$lastErrorStack->error('errorId');
		$this->assertEquals(array(self::$defTestMsg), self::$lastErrorStack->getAllErrors());
	}

}

abstract class PackageConnector_TestCase extends CommonConnector_TestCase
{
	protected static $connector;

	protected static $initialSettings = array(
		'vendorDir' => self::FIXTURES_DIR . 'lib',
		'locked' => true,
		'lockFilePath' => self::FIXTURES_DIR . 'composer.lock',
		'composerFilePath'=> self::FIXTURES_DIR . 'composer.json',
		'autoloadFile' => self::FIXTURES_DIR . 'lib/autoload.php',
	);

	public static function setUpBeforeClass()
	{
		parent::setUpBeforeClass();
		self::$connector = new PackageConnector();
	}
}

/**
 *
 */
class PackageConnectorBaseTest extends PackageConnector_TestCase
{

	protected static $flushMethod;

	public static function setUpBeforeClass()
	{
		parent::setUpBeforeClass();
		// make flush() method accessible for testing
		self::$flushMethod = self::setAccessible('PackageConnector', 'flush');
	}

	/**
	 *
	 */
	public function testSetup(){
		self::$connector->setup(self::FIXTURES_DIR);

		foreach (self::$initialSettings as $propertyName => $value) {
			$this->assertAttributeEquals($value, $propertyName, self::$connector, "Testing setuped property `PackageConnector::$propertyName`");
		}
		$this->assertAttributeEquals(true, 'setuped', self::$connector);

		$serialized = serialize(self::$connector);
		self::$connector = unserialize($serialized);
	}

	/**
	 * @depends testSetup
	 */
	public function testIsLocked()
	{
		$this->assertAttributeEquals(self::FIXTURES_DIR.'composer.lock', 'lockFilePath', self::$connector);
	}

	public function packagesDataProvider(){
		return array(
			array('components/bootstrap', true),
			array('not/exists', false),
		);
	}

	/**
	 * @depends testSetup
	 * @dataProvider packagesDataProvider
	 */
	public function testIsExists($package, $expected){
		$this->assertEquals($expected, self::$connector->isExists($package), self::$connector->getLastError());
	}

	/**
	 * @depends testSetup
	 * @dataProvider packagesDataProvider
	 */
	public function testIsInstalled($package, $expected)
	{
		$this->assertEquals($expected, self::$connector->isExists($package), self::$connector->getLastError());
	}

	/**
	 * @covers PackageConnector::flush
	 * @covers PackageConnector::serialize
	 * @covers PackageConnector::connectAutoloader
	 * @depends testSetup
	 */
	public function testExport()
	{
		// testing connectAutoloader() fails
		$ref_connector = new PackageConnector();
		$autoloaded = $ref_connector->connectAutoloader(); // fails as not setuped yet
		$this->assertFalse($autoloaded);

		// connectAutoloader() is OK
		$autoloaded = self::$connector->connectAutoloader();
		$this->assertEquals(1, $autoloaded);
		$this->assertTrue(defined('AUTOLOAD_INCLUDED'));

		// Flush and serialization test
		self::$flushMethod->invoke(self::$connector);
		$serialized = serialize(self::$connector);
		$this->assertEquals(serialize($ref_connector), $serialized);
	}

	/**
	 * @depends testExport
	 */
	public function testSetupFails(){
		self::$connector->setup(); // must fails as no data files in base path
		$this->assertAttributeEquals(false, 'setuped', self::$connector);
	}

}

class InstalledPackageInfoTest extends PackageConnector_TestCase
{

	protected static $packagesTestData = array(
		'package1' => array('name'=>'vendor1/some-package', 'version'=>'1.2.3', 'time' => '2015-11-01 00:00:00', 'type'=>'cotonti-siena-theme'),
		'package2' => array('name'=>'vendor2/some-package', 'version'=>'0.0.1', 'time' => '2015-07-15 06:08:09', 'type'=>'package'),
		'package3' => array('name'=>'acme/test-lib', 'version'=>'1.1.0', 'time' => '2015-06-17 01:01:01', 'type'=>'library'),
	);

	/**
	 * @var InstalledPackageInfo
	 */
	protected static $packageInfo;

	// for accessible methods
	protected static $buildNamesMethod;
	protected static $expandNameMethod;

	public static function setUpBeforeClass()
	{
		parent::setUpBeforeClass();
		self::$connector->setup(self::FIXTURES_DIR);
		// get PackageInfo as it already initialized via PackageConnector
		self::$packageInfo = self::$connector->package(); // using data from `composer.lock`
	}

	public function installedPackagesProvider(){
		return array(
			// fullName, typeFilter, expected
			array('not/installed', null, false),
			array('components/bootstrap', null, true),
			array('components/bootstrap', 'component', true),
			array('components/bootstrap', 'not-existent-type', false),
		);
	}

	/**
	 * @covered isInstalled
	 * @covered getInfo
	 * @dataProvider installedPackagesProvider
	 */
	public function testIsInstalled($fullPackageName, $typeFilter, $expected){
		$this->assertEquals(
			$expected,
			self::invokeProtected(self::$packageInfo, 'isInstalled', $fullPackageName, $typeFilter)
		);
	}

	public function magicMethodDataProvider()
	{
		return array(
			// package, propName, expected
			array('bootstrap', 'version', '3.3.5'),
			array('bootstrap', 'type', 'component'),
			array('bootstrap', 'notificationUrl', 'https://packagist.org/downloads/'),
			array('bootstrap', 'notExistsProp', null),
		);
	}

	/**
	 * @dataProvider magicMethodDataProvider
	 */
	public function testMagicMethods($packageName, $propName, $expected)
	{
		self::$packageInfo->select($packageName);
		$methodName = 'get'.ucfirst($propName);
		$actual = self::$packageInfo->__call($methodName, array());
		$this->assertEquals($expected, $actual);
	}

	/**
	 * @dataProvider magicMethodDataProvider
	 */
	public function testMagicProps($packageName, $propName, $expected)
	{
		self::$packageInfo->select($packageName);
		$actual = self::$packageInfo->__get($propName);
		$this->assertEquals($expected, $actual);
	}

	public function packagesData()
	{
		$packages = array();
		$packages[] = self::$packagesTestData['package1'];
		$packages[] = self::$packagesTestData['package2'];
		$packages[] = self::$packagesTestData['package3'];
		return $packages;
	}

	public function namesListData()
	{
		$list = array();
		$list['test-lib'][] = self::$packagesTestData['package3'];
		$list['some-package'][] = self::$packagesTestData['package2'];
		$list['some-package'][] = self::$packagesTestData['package1'];
		return $list;
	}


	// =======================================================================
	// Attention! Here and below Packages Data is reinitialized with `packagesData`
	// =======================================================================
	public function testBuildNamesList()
	{
		self::$packageInfo->initPackagesData($this->packagesData());
		$this->assertAttributeEquals($this->namesListData(), 'packagesByName', self::$packageInfo);
	}

	public function typeCheckProvider() {
		return array(
			array('package', null, true),
			array('package', 'package', true),
			array('package', 'PaCKAge', true),
			array('package', InstalledPackageInfo::COT_PACKAGES, true),
			array('package', InstalledPackageInfo::COT_PACKAGE_OTHER, true),
			array('package', InstalledPackageInfo::COT_PACKAGE_THEME, false),
			array('cotonti-siena-plugin', InstalledPackageInfo::COT_PACKAGE_THEME, false),
			array('cotonti-siena-plugin', InstalledPackageInfo::COT_PACKAGE_PLUGIN, true),
			array('cotonti-siena-plugin', InstalledPackageInfo::COT_PACKAGE_OTHER, false),
			array('cotonti-siena-plugin', InstalledPackageInfo::COT_PACKAGES, true),
		);
	}

	/**
	 * @dataProvider typeCheckProvider
	 */
	public function testIsType($type, $filter, $expected)
	{
		$this->assertEquals(
			$expected,
			$this->invokeProtected(self::$packageInfo, 'isType', $type, $filter)
		);
	}

	/**
	 * @see self::$packagesTestData for Packages data
	 */
	public function expandNameProvider()
	{
		// Array: shortName, typeFilter, expectedPackageData
		return array(
			// package not exists
			array('unknown_package', null, false),
			// package not conform type filter (string defined)
			array('test-lib', 'not-existing-type', false),
			// expand by common package name, single package exists
			array('test-lib',        null, self::$packagesTestData['package3']),
			// package by name and filter
			array('test-lib', 'library', self::$packagesTestData['package3']),
			// package not conform type filter (integer defined)
			array('test-lib', InstalledPackageInfo::COT_PACKAGE_MODULE, false),
			// package conform type filter (integer defined)
			//array('test-lib', InstalledPackageInfo::COT_PACKAGE_OTHER, self::$packagesTestData['package3']),
			// package conform type filter (integer defined)
			array('test-lib', InstalledPackageInfo::COT_PACKAGES, self::$packagesTestData['package3']),
			// testing expand by random case short name
			array('Test-Lib',        null, self::$packagesTestData['package3']),
			// in case of several packages with same short name, return oldest one
			array('some-package',    null, self::$packagesTestData['package2']),
			// same short name packages filtered by type
			array('some-package', InstalledPackageInfo::COT_PACKAGE_THEME, self::$packagesTestData['package1']),
		);
	}

	/**
	 * @dataProvider expandNameProvider
	 */
	public function testExpandName($shortName, $typeFilter, $expectedPackage)
	{
		$fullName = self::invokeProtected(self::$packageInfo, 'expandName', $shortName, $typeFilter);
		//self::$expandNameMethod->invoke(self::$packageInfo, );
		$expectedFullName = $expectedPackage['name'];
		$this->assertEquals($expectedFullName, $fullName, "Checking expandName() failed for `$shortName, $typeFilter`");
	}

	public function selectTestDataProvider()
	{
		return array(
			// name, typeFilter, version, installed
			array('not/exists', null, '1.1.0', false), // not installed
			array('acme/test-lib', null, '1.1.0', true), // by full name
			array('test-lib', null, '1.1.0', true), // by short name
			array('some-package', null, '0.0.1', true),
			array('some-package', 'cotonti-siena-theme', '1.2.3', true), // with filter
		);
	}

	/**
	 * @depends testIsType
	 * @depends testExpandName
	 * @depends testIsInstalled
	 * @dataProvider selectTestDataProvider
	 */
	public function testSelect($packageName, $packageType, $version, $expected)
	{
		$this->assertEquals(
			$expected,
			self::$packageInfo->select($packageName, $packageType)
		);

		// addition test for getting info
		if ($expected)
		{
			$fullName = self::$packageInfo->getFullName();
			list($vendor, $shortName) = self::invokeProtected(self::$packageInfo, 'splitName', $fullName);
			$this->assertEquals($shortName, self::$packageInfo->getName(), 'Error in: getName | splitName | getFullName');

			$this->assertEquals($version, self::$packageInfo->getVersion());
		}
	}
}
