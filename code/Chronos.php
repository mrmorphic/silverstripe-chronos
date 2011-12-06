<?php

class Chronos extends Controller {
	static $allowed_actions = array(
		"execmethod"
	);

	/**
	 * Config directory where files are written. If left null, a computed directory relative to the temporary
	 * directory is used.
	 */
	protected static $config_dir = null;

	static function set_config_dir($dir) {
		self::$config_dir = $dir;
	}

	static function get_config_dir() {
		return self::$config_dir;
	}

	/**
	 * Create a scheduled action or actions.
	 * @param mixed $actions	An array of ChronosScheduledAction objects
	 */
	static function add($actions = null, $identifier = null) {
		if (!$actions) return;
		if (!is_array($actions)) throw new Exception("Chronos::add expects an array");
		foreach ($actions as $action) {
			if ($identifier) $action->identifier = $identifier;
			$action->validate();
			self::build_conf_file($action);
		}
	}

	/**
	 * Remove any scheduled action that has the the specified identifier. It may be more than one.
	 */
	static function remove($identifier) {
		// @todo Create a file glob based on the identifier and remove those files.
		// @todo This may be different on unix and windows, because windows tempnam only uses
		// @todo the first 3 characters of the identifier, and not sure what the case is.
	}

	/**
	 * Replace any scheduled actions with the old identiifer with a new set of actions, optionally
	 * with a new identifier. If $newIdentifier is not supplied, the actions are added with $oldIdentifer.
	 */
	static function replace($actions, $oldIdentifier, $newIdentifier = null) {
		self::remove($oldIdentifier);
		self::add($actions, $newIdentifier ? $newIdentifier : $oldIdentifier);
	}

	static function build_conf_file($action) {
		$file = self::action_file_name($action);
		file_put_contents($file, $action->serialise());
	}

	static function action_file_name($action) {
		return tempnam(
			self::config_directory(),
			(isset($action->groupIdentifier) ? $action->groupIdentifier : "misc") . "_"
		);
	}

	/**
	 * Return the directory where config files are stored.
	 * @return String
	 */
	static function config_directory() {
		if (self::$config_dir)
			$dir = self::$config_dir;
		else
			$dir = TEMP_FOLDER . "/chronos";
		if (!file_exists($dir)) mkdir($dir,  0777, true);
		return $dir;
	}

	function execmethod() {
		// @todo work out how to prevent web requests to execmethod()
//		if (isset($_SERVER['HTTP_HOST'])) {
//			echo "execmethod can't be run from a web request, you have to run it on the command-line.";
//			die();
//		}

		// Get the parameters. This is actually a base64 encoded, json encoded version of the ScheduledAction.
		if (!isset($_GET["params"])) throw new Exception("execmethod expects parameters");
		$params = base64_decode(urldecode($_GET['params']));
		$action = json_decode($params);

//		echo "action is " . print_r($action,true) . "\n";
		if (!isset($action->parameters)) $action->parameters = null; // make sure it's set

		$method = $action->method;
		if (($i = strpos($method, "::")) !== FALSE) {
			// static
			$klass = substr($method, 0, $i);
			$method = substr($method, $i+2);

			// hack alert. If we're running tests, we need to explicitly include ChronosTest as we're calling a method on it.
			// Because execmethod is typically run from a sub-process that doesn't know it's initiator is running tests, it relies
			// on a parameter to know whether to include the test code or not.
			if (isset($action->testRunning) && $action->testRunning)
				require_once(BASE_PATH . "/chronos/tests/ChronosTest.php");

			$klass::$method($action->parameters);
		}
		else {
			// instance
			// @todo validate class and object ID
			$instance = DataObject::get_by_id($action->ObjectClass, $action->ObjectID);
			$instance->$method($action->parameters);
		}                                                                                
	}
}

/**
 * Simple value object class.
 */
class ChronosScheduledAction {
	/**
	 * A specification of when this action should execute. Can be an explicit date/time for a one-off execution.
	 * @var String $timeSpecification
	 */
	var	$timeSpecification;

	/**
	 * Scheduled events can be have an identifier which so they can be removed or replaced. The identifier
	 * doesn't need to be unique; if it is not unique, then all actions with the same group can be replaced
	 * or removed together.
	 * @var String $identifier
	 */
	var $identifier;

	/**
	 * Specifies the way the action is executed. "url" indicates that the action is invoked by visiting a
	 * given URL. "method" indicates that either a static or instance method is called.
	 * @var String $actionType  "url" or "method"
	var $actionType;

	/**
	 * If actionType=url, this is the URL to hit.
	 * @var String $url
	 */
	var	$url;

	/**
	 * If ActionType=Method, this is the name of the method. If it is of the form X::y, it is a static call
	 * of method y on class x. Otherwise it is an instance method, and ObjectClass and ObjectID identify
	 * the instance.
	 * @var String $method
	 */
	var $method;

	/**
	 * If not null, this is a serialised PHP object that represents parameters that can be passed to the method.
	 * @var Object $parameters
	 */
	var $parameters;

	/**
	 * If actionType=method and the method is not a static method, $objectClass identifies the class of the instance
	 * to execute the method on (must be a DataObject derivative)
	 * @var String $objectClass
	 */
	var $objectClass;

	/**
	 * If actionType=method and the method is not a static method, $objectID identifies the ID of the instance.
	 * @var int $objectID
	 */
	var $objectID;

	function __construct($args = null) {
		if ($args && is_array($args)) {
			foreach ($args as $k => $v) $this->$k = $v;
		}
	}

	/**
	 * Return a string representation of the scheduled action for writing to the config files that the executor
	 * uses.
	 */
	function serialise() {
		return json_encode($this);
	}

	/**
	 * Validate an action. Throws an exception if there is a problem, otherwise returns true.
	 */
	function validate() {
		// mandatory
		foreach (array("timeSpecification", "actionType") as $prop)
			if (!isset($this->$prop)) throw new Exception("scheduled action must have '$prop' property");
			
		$this->validateTimeSpecification();
		$this->validateAction();
	}

	function validateTimeSpecification() {
		if (is_string($this->timeSpecification)) $this->timeSpecification = new ChronosTimeSingle($this->timeSpecification);
		else if (!is_object($this->timeSpecification) ||
				 !($this->timeSpecification instanceof ChronosTimeSpecification))
			throw new Exception("Invalid time specification. Expecting a ChronosTimeSpecification");
	}

	function validateAction() {
		switch ($this->actionType) {
			case "url":
				if (!isset($this->url) || !$this->url) throw new Exception("a scheduled action with actionType 'url' must specify a URL");
				break;
			case "method":
				if (!isset($this->method) || !$this->method) throw new Exception("a scheduled action with actionType 'method' must specify a URL");
				if(strpos($this->method, "::") === FALSE) {
					if (!isset($this->objectClass) || !$this->objectClass) throw new Exception("a scheduled action with actionType 'method' (instance) must specify an objectClass property");
					if (!isset($this->objectID) || !$this->objectID) throw new Exception("a scheduled action with actionType 'method' (instance) must specify an objectID property");
				}
				break;
			default:
				throw new Exception("invalid actionType {$this->actionType}. Must be 'url' or 'method'");
				break;
		}
	}
}

class ChronosTimeSpecification {
	var $type;
}

class ChronosTimeSingle extends ChronosTimeSpecification {
	var $time;

	function __construct($time) {
		$this->type = "single";
		if (strtotime($time) === FALSE) throw new Exception("Invalid absolute time specification ($time)");
		$this->time = $time;
	}
}

/**
 * Time specification for a recurring action.
 * Models a subset of RFC5545 iCalendar format. Specifically:
 * - each recurring action has a start date/time and a repeat rule.
 * - the repeat rule has the following:
 *   - frequency (required) hourly,minutely,daily,weekly (monthly, yearly not implemented yet)
 *   - interval (optional) integer
 *   - byday (optional) su,mo,tu,we,th,fr,sa  (not including 1su or -1su syntax)
 *   - byhour (optional) 0-23
 *   - byminute (optional) 0-59
 *
 * Not implemented yet:
 *   - count
 *   - bymonth
 *   - until
 *   - wkst
 *   - bymonthday
 *   - bysecond
 * @throws Exception
 *
 */
class ChronosTimeRecurring extends ChronosTimeSpecification {
	var $startTime;
	var $frequency;
	var $interval = -1;
	var $byDay = null;
	var $byHour = null;
	var $byMinute = null;

	/**
	 * @throws Exception
	 * @param $params	A map of keys to values that are used to initialise the spec.
	 */
	function __construct($params) {
		echo "constructing recurring\n";
		$this->type = "recurring";

		if (!is_array($params)) throw new Exception("ChronosTimeRecurring expects a map of initialisation properties");
		$params = array_change_key_case($params); // all keys to lower

		if (!isset($params["starttime"])) throw new Exception("ChronosTimeRecurring requires startTime");
		$this->startTime = $params["starttime"];
		if (strtotime($this->startTime) === FALSE) throw new Exception("ChronosTimeRecurring: invalid startTime ($this->startTime)");

		if (!isset($params["frequency"])) throw new Exception("ChronosTimeRecurring requires frequency");
		$this->frequency = strtolower($params["frequency"]);
		if (!in_array($this->frequency, array("hourly", "minutely", "daily", "weekly")))
			throw new Exception("ChronosTimeRecurring: invalid frequency ($this->frequency)");

		if (isset($params["interval"])) {
			$this->interval = $params["interval"];
			if (!is_numeric($this->interval) || $this->interval < 1) throw new Exception("ChronosTimeRecurring: invalid interval ($this->interval)");
		}

		if (isset($params["byday"])) {
			$this->byDay = $params["byday"];
			if (!is_array($this->byDay)) $this->byDay = array($this->byDay);
			foreach ($this->byDay as $key => $value) {
				$v = strtolower($value);
				if (!in_array($v, array("su","mo","tu","we","th","fr","sa")))
					throw new Exception("ChronosTimeRecurring: invalid day ($v)");
				$this->byDay[$key] = $v;
			}
		}

		if (isset($params["byhour"])) {
			$this->byHour = $params["byhour"];
			if (!is_array($this->byHour)) $this->byHour = array($this->byHour);
			foreach ($this->byHour as $hour) {
				if (!is_numeric($hour) || $hour < 0 || $hour > 23) throw new Exception("ChronosTimeRecurring: invalid byHour ($hour)");
			}
		}

		if (isset($params["byminute"])) {
			$this->byMinute = $params["minute"];
			if (!is_array($this->byMinute)) $this->byMinute = array($this->byMinute);
			foreach ($this->byMinute as $minute) {
				if (!is_numeric($minute) || $minute < 0 || $minute > 59) throw new Exception("ChronosTimeRecurring: invalid byMinute ($minute)");
			}
		}
	}
}