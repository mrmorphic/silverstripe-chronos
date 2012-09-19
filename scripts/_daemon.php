<?php

/**
 * Executor for scheduled tasks. This expects that the scheduled actions defined have been serialised to configuration
 * files that contain the scheduling and action information, per action.
 *
 * This process is designed to be executed as a daemon. Generally it will sleep, until a scheduled event is
 * scheduled to occur. It also wakes periodically to refresh its internal copy of the schedule, in case the application
 * changes it.
 *
 * Scheduled actions are either once-only or recurring.
 *
 * Expected usage:
 *    php _execute_schedule.php temp=path
 *
 * The temp parameter must be set. It is how the executor knows where to find configuration files. By default, this is
 * the temp folder for the SilverStripe site, in the 'chronos' subdirectory, unless overridden in the site code with
 * a call to Chronos::set_config_dir().
 * It also understands the following optional parameters:
 * - config_refresh=n		Specifies the number of seconds between refreshes of the schedule. If not supplied,
 * 							defaults to 30 seconds. This is the amount of time the daemon will take to respond
 * 							to changes in calendar.
 * - tolerance=n			Specifies the number of seconds of tolerance. A scheduled action will execute if
 * 							its date/time is the current time, or up to 'tolerance' seconds late.
 * - time_limit=n			If specified, sets the execution time limit to n seconds. If 0, unlimited time.
 */

class ChronosDaemon {
	// Parameter defaults
	var $params = array(
		"config_refresh"	=> 30,
		"time_limit"		=> 0,		// no time limit by default
		"tolerance"			=> 10
	);

	var $confs = array();

	/**
	 * Read all the scheduled actions into internal representation. Replaces any existing
	 * representation.
	 * @return void
	 */
	function refreshActionStructure() {
		// Grab all the files to process
		$this->confs = array();
		foreach (glob($this->params['temp'] . "/*.json") as $file) {
			$s = file_get_contents($file);

			$this->confs[$file] = json_decode($s);
		}
//		echo "All config: " . print_r($this->confs,true) . "\n";
	}

	/**
	 * Return the timestamp of the next scheduled action to be executed.
	 * @return void
	 */
	function determineNextEventFromSchedule() {
		$result = 0;
		foreach ($this->confs as $file => $c) {
			if (!isset($c->timeSpecification)) continue;
			$t = $c->timeSpecification;
			if ($t->type == "recurring") {
				$np = $this->determinePrevNextRecurrence($t, time());
				$t = $np["next"];
			}
			else {
				// absolute times are only considered if they are not in the past outside our
				// window of consideration.
				$t = strtotime($t->time);
				if ($t < (time() - $this->params["tolerance"])) $t = FALSE;
			}

			if ($t !== FALSE && ($result == 0 || $t < $result)) $result = $t;
		}

		return $result;
	}

	/**
	 * Given a recurring time specification $timeSpec, and a reference time, work out the previous and next
	 * occurrence relative to $baseTime.
	 * @param $timeSpec
	 * @param $baseTime
	 * @return array		Map with "prev" and "next" keys, timestamp values, or null if not applicable.
	 */
	function determinePrevNextRecurrence($timeSpec, $baseTime) {
		// determine period in seconds
		switch ($timeSpec->frequency) {
			case "hourly":
				$period = 3600;
				break;
			case "minutely":
				$period = 60;
				break;
			case "daily":
				$period = 86400;
				break;
			case "weekly":
				$period = 604800;
				break;
		}
		if ($timeSpec->interval > 1) $period *= $timeSpec->interval;

		$start = strtotime($timeSpec->startTime);

		if ($start > $baseTime) return array("prev" => null, "next" => $start);  // start in future

		// @todo take into account byday, byhour, byminute
		$delta = $baseTime - $start; // difference between start and now.
		$t = $delta % $period;
		$prev = $baseTime - $t;
		$next = $prev + $period;
		return array("prev" => $prev, "next" => $next);
	}

	/**
	 * Execute all actions that are due to be executed right now. It's also responsible for purging out
	 * actions that have been done or are in the past only.
	 * @return void
	 */
	function executeDueActions() {
		clearstatcache();

		$forExecution = array();
		$forRemoval = array();
		$now = time();

		foreach ($this->confs as $file => $c) {
			if (!isset($c->timeSpecification)) {
				$forRemoval[] = $file;
				continue;
			}

			$execute = false;

			// Determine if its time to execute this.
			$t = $c->timeSpecification;
			if ($t->type == "recurring") {
				$np = $this->determinePrevNextRecurrence($t, $now);
				echo "(" . date("H:i:s", $np["prev"]) . "," . date("H:i:s", $np["next"]) . ") at " . date("H:i:s", $now) . "\n";

				if ($np["next"] == $now) $execute = true;
				else if ($np["prev"] && $np["prev"] >= ($now - $this->params["tolerance"])) {
					// If the previous scheduled execution point is defined, and is within the tolerance for execution,
					// and its last execution time is less than the previous scheduled execution, then we haven't executed
					// the action for previous execution point. So we do that now.
					$lastExecution = filemtime($file);
					if ($lastExecution < $np["prev"]) $execute = true;
				}
			}
			else {
				// one off
				$t = strtotime($t->time);
				if ($t === FALSE || $t < ($now - $this->params["tolerance"])) {
					// if it has an invalid date, or if t is old, mark it for removal
					$forRemoval[] = $file;
					continue;
				}

				// If the date is on or before the current time, we'll execute it. But it can't be outside the
				// tolerance, or we wouldn't have got here.
				if ($t <= time())
					$execute = true;
			}

			if ($execute) $forExecution[$file] = $c;
		}

		// Any one-off's for execution should be removed, so we don't execute them again. For recurring executions,
		// we touch the file with the current time.
		foreach ($forExecution as $file => $c) {
			if ($c->timeSpecification->type == "single") {
				unlink($file);
				unset($this->confs[$file]);
			}
			else if ($c->timeSpecification->type == "recurring") {
				touch($file, $now);
			}
		}

		// If there are any configs in $forRemoval, get rid of them, they are invalid.
		// @todo These should be logged.
		foreach ($forRemoval as $file) {
			unlink($file);
			unset($this->confs[$file]);
		}

		// Execute actions in sub-processes.
		$exec = dirname(dirname(dirname(__FILE__))) . "/sapphire/sake";
		$log = dirname($this->params['temp']) . "/chronos_subprocess.log";
		foreach ($forExecution as $file => $c) {
			switch ($c->actionType) {
				case "url":
					// @todo implement sub-process execution of URL. Could call controller action, or use wget.
					break;
				case "method":
					if (isset($this->params['test_mode']) && $this->params['test_mode'] == 1) $c->testRunning = true;
//echo "execution params are: " . print_r($c, true) . "\n";
					$param = urlencode(base64_encode(json_encode($c)));

					echo "executing: $exec Chronos/execmethod params={$param} > $log 2>$log &\n";
					`$exec Chronos/execmethod params={$param} >> $log 2>>$log &`;
					break;
			}
		}
	}

	/**
	 * Main execution method for daemon.
	 * @param $argv
	 * @return void
	 */
	function execute($argv) {
		// Ensure that this can't be accessed this from an HTTP request.
		if(isset($_SERVER['HTTP_HOST'])) {
			echo "_daemon.php can't be run from a web request, you have to run it on the command-line.";
			die();
		}

		set_time_limit(0);

		// Get parameters.
		foreach ($argv as $n => $v) {
			if ($n == 0) continue;

			if (($i = strpos($v, '=')) !== FALSE) {
				$this->params[substr($v, 0, $i)] = substr($v, $i+1);
			}
			else
				$this->params[$v] = null;
		}

		echo "params are: " . print_r($this->params,true) . "\n";

		if (!isset($this->params['temp'])) {
			echo "temp parameter must be set\n";
			die();
		}

		// Create the temp folder if it doesn't exist
		if (!is_dir($this->params['temp'])) mkdir($this->params['temp']);

		// Real work starts here
		$this->refreshActionStructure();
//		echo "time now is " . print_r(time(), true) . "\n";
//		echo "refresh is " . print_r($this->params["config_refresh"],true) . "\n";
		$nextRefreshTime = time() + (int) $this->params["config_refresh"];
//		echo "nextRefreshTime is " . print_r($nextRefreshTime, true) . "\n";

		$endTime = $this->params["time_limit"] > 0 ? time() + $this->params["time_limit"] : false;
//echo "start time is " . date("H:i:s", time()) . "\n";
//echo "end time is " . date("H:i:s", $endTime) . "\n";

		while(true) {
//			$nextActionTime = $this->determineNextEventFromSchedule();
////			echo "nextActionTime is " . print_r($nextActionTime,true) . "\n";
////			echo "nextRefreshTime is " . print_r($nextRefreshTime, true) . "\n";
////			echo "time now is " . print_r(time(), true) . "\n";

			// maybe there are no actions yet
//			if ($nextActionTime == 0)
//				$waitUntil = $nextRefreshTime;
//			else
//				$waitUntil = min($nextActionTime, $nextRefreshTime);

			// only sleep if we're not once-only.
//			if (!$this->params["once_only"]) {
//				$s = $waitUntil - time();
//				if ($s < 1) $s = 1;
//				sleep($s);
//			}

			// OK, when we wake up, we need to process any scheduled actions that need to be executed now.
			$this->executeDueActions();

			if ($endTime && $endTime < time()) break;
//			if ($this->params["once_only"]) break;

			// If we are due to refresh the schedule from the file system, do that now.
			if (time() > $nextRefreshTime) {
				$this->refreshActionStructure();
				$nextRefreshTime = time() + $this->params["config_refresh"];
			}

			sleep(2);
		}
	}
}

echo "Starting daemon\n";
$daemon = new ChronosDaemon();
$daemon->execute($argv);

/**----------------------------------------------------------------------------------------------------**/
/*

// Filter out those that are not in this process's execution window. While we do it, we augment the remaining
// conf entries with addition properties for later.

*/