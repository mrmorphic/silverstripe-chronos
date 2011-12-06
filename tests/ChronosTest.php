<?php

class ChronosTest extends SapphireTest {

	function setUp() {
		parent::setUp();

		// Avoid any conflict of confs between dev and testing execution.
		$this->oldConf = Chronos::get_config_dir();
		Chronos::set_config_dir(TEMP_FOLDER . "/chronos/tests/");
		$this->clearTempFiles(); // start clean
	}

	function tearDown() {
		parent::tearDown();

		Chronos::set_config_dir($this->oldConf);
		$this->clearTempFiles(); // finish clean
	}
           
	function clearTempFiles() {
		foreach (glob(TEMP_FOLDER . "/chronos/tests/*") as $filename)
			unlink($filename);
	}

	/**
	 * Get the config files for the specified identifier.
	 * @param String $identifier Identifier name, null to retrieve only actions with no identifier,
	 *							or "*" to retrieve all actions irrespective of identifier.
	 */
	function getConfigFiles($identifier = null) {
		if ($identifier == "*") $pattern = "*";
		else if ($identifier) $pattern = $identifier . "_*";
		else $pattern = "misc_*";
		return glob(TEMP_FOLDER . "/chronos/tests/" . $pattern);
	}

	function testAddItemsUnGrouped() {
		echo "TESTADDITEMSUNGROUPED\n";
		Chronos::add(array(
			new ChronosScheduledAction(
				array(
					"timeSpecification" => new ChronosTimeRecurring(array(
						"startTime" => "1-jan-2010 9:00",
						"frequency" => "daily"
					)),
					"actionType" => "url",
					"url" => "http://disney.com"
				)
			),
			new ChronosScheduledAction(
				array(
					"timeSpecification" => "1-jan-2010 9:00",
					"actionType" => "method",
					"method" => 'AClass::a_method'
				)
			)
		));

		// @todo test what is left in the directory. There should be two files.
		$files = $this->getConfigFiles("misc");
		$this->assertEquals(count($files), 2, "Two misc files after ungrouped insertion");
	}

	function testAddValidation() {
		// @todo Add a variety of invalid scheduled actions, and ensure exceptions are thrown for them
	}

	function testAddItemsGrouped() {
	}

	function testInvalidRemoved() {
		// @todo Add a variety of valid and invalid actions, run the executor and determine that the invalid
		// @todo actions are removed. Not critical, invalids should not be added in the first place.
	}

	function testNonRecurringExecution() {
		echo "TESTNONRECURRINGEXECUTION\n";
		$id = rand();

		// 2 seconds ago, as a string
		$t = date("d-M-Y H:i:s", time() - 2);

		// @todo Add 3 non recurring actions, one in the past, one within the next 60 seconds, and one
		// @todo beyond. Test that the first and second are executed, the third is not and its file remains.
		Chronos::add(array(
			new ChronosScheduledAction(
				array(
					"timeSpecification" => $t,
					"actionType" => "method",
					"method" => 'ChronosTest::staticTestMethod',
					"parameters" => array("id" => $id)
				)
			)
		));

		$this->runScheduledExecutor();

		// wait for a bit. We should see a file get created in the temp directory called chronos_test_ suffixed with id
		$count = 10;
		$filename = TEMP_FOLDER . "/chronos_test_" . $id;
		while ($count > 0) {
			sleep(10);
			echo "...testing ($count)\n";
			if (file_exists($filename)) break;
			$count--;
		}
		$this->assertTrue($count > 0, "test file was created asynchronously");
	}

	/**
	 * Set up a recurring action with frequency of 1 minute. The start time is 55 seconds ago, so the
	 * first execution is in a few seconds. Get the first execution output, then delete it. In another minute,
	 * check the output is back again.
	 * @return void
	 */
	function testRecurringExecution() {
		echo "TESTRECURRINGEXECUTION\n";
		$t = date("d-M-Y H:i:s", time() - 55); // 55 seconds ago

		$id = "testrecurring";

		Chronos::add(array(
			new ChronosScheduledAction(
				array(
					"timeSpecification" => new ChronosTimeRecurring(array(
						"startTime" => $t,
						"frequency" => "minutely",
						"interval" => 1
					)),
					"actionType" => "method",
					"method" => 'ChronosTest::staticTestMethod',
					"parameters" => array("id" => $id)
				)
			)
		));

		$filename = TEMP_FOLDER . "/chronos_test_" . $id;

		if (file_exists($filename)) unlink($filename);

		// run asynch for 75 seconds.
		$this->runScheduledExecutor(75, true);

		// check file doesn't exist to start
		$this->assertTrue(!file_exists($filename), "output file doesnt exist to start");

		sleep(10);
		// check exists, delete
		$this->assertTrue(file_exists($filename), "output file exists for first execution");
		unlink($filename);

		sleep(60);
		// check exists again
		$this->assertTrue(file_exists($filename), "output file exists for second execution");
		unlink($filename);
	}

	static function staticPing() {
		echo "PING\n";
	}

	static function staticTestMethod($params) {
		$id = $params->id;
		$filename = TEMP_FOLDER . "/chronos_test_" . $id;
		file_put_contents($filename, "test");
	}

	/**
	 * Run the schedule executor on the current contents of the config files in chronos/tests
	 */
	function runScheduledExecutor($timeLimit = 5, $async = false) {
		$script = BASE_PATH . "/chronos/scripts/_daemon.php";
		$temp = TEMP_FOLDER . "/chronos/tests";
		$cmd = "php $script temp=$temp time_limit=$timeLimit test_mode=1";
		echo "running command $cmd\n";
		if ($async) {
			`$cmd > /dev/null 2>/dev/null &`;
			$out = "";
		}
		else
			exec($cmd, $out);
//		echo "output was: " . print_r($out,true) . "\n";
	}
}

