# Chronos Module

## Overview

Chronos executes actions to a schedule that be flexibly defined by the application. The schedule can be altered at
any time, and is fine-grained, with actions schedulable to a one second resolution.

## Maintainer Contact

* Mark Stephens  (mark at silverstripe dot com)

## Requirements

* SilverStripe 2.4 or newer
* *nix operating system

## Module Status

Still under active development. Contact author if you are planning to use it.

## Overview

The module has two parts. Within the application, a simple API is provided to the application to write schedules
of actions to be executed. Each action has a time specification that indicates when it is to be executed. This includes
one-time actions, and recurring actions. The action can execute a static method or ping a URL.

The second part to the module is a PHP daemon that is run on system start-up, and runs continuously. The script
monitors the schedule, and the times on the scheduled actions, and executes the actions when appropriate. The script
executes all actions in subprocesses so that it's timing is not affected.

# Installation

* Install the module code in the project root and perform a /dev/build.
* Any functions in your project that need actions executed to a schedule need to call the API to define the schedule
  (and redefine it as and when necessary)
* Add execution of the daemon to system startup. This should be run as the same user as apache. Use a command like:
  sudo -u www-data /path_to_site/chronos/scripts/chronos-daemon.sh temp=/path_to_ss_temp

# Usage

## Adding Scheduled Actions

The following schedules a single scheduled action to execute on-off at a specified time. At that time the daemon will
execute a method on a class.

	Chronos::add(array(
		new ChronosScheduledAction(
			array(
				"timeSpecification" => "1-jan-2013 9:00",
				"actionType" => "method",
				"method" => 'MyClass::my_method'
			)
		)
	));

More than one action can be schedule simultaneously:

	Chronos::add(array(
		new ChronosScheduledAction(
			array(
				"timeSpecification" => "1-jan-2013 9:00",
				"actionType" => "method",
				"method" => 'MyClass::my_method'
			)
		),
		new ChronosScheduledAction(
			array(
				"timeSpecification" => "1-jan-2013 10:00",
				"actionType" => "url",
				"url" => "http://disney.com"
			)
		)
	));

## Grouping Actions

When actions are added to the schedule, you can provide an optional identifier as the second parameter to ::add().
This lets multiple actions to be added that form a logical group. When calling ::remove() or ::replace(), you can
provide this identifier to remove or replace the whole group, which lets different parts of your application define
their own schedules that don't interfere with each other.

	Chronos::add(array(
		new ChronosScheduledAction(
			array(
				"timeSpecification" => "1-jan-2013 9:00",
				"actionType" => "method",
				"method" => 'MyClass::my_method'
			)
		)
	), "myfancyschedule");

To remove all scheduled actions with that identifier:

	Chronos::remove("my-fabulous-schedule");

This will only remove those with the identifier.

## Time Specifications

Time specifications can be single-execution, or recurring.

* If timeSpecification is a string, or is an instance of ChronosTimeSingle which is passed a time string, it is a
  single-execution action, and the time is passed to strtotime(). The daemon will execute the action if the time
  specification is equal to strtotime (to the second, but within the configured tolerance for the daemon).
* If timeSpecification is a ChronosTimeRecurring, the constructor of that object will contain a number of options
  that determine the schedule of the recurring action. The daemon will execute this action every time the specification
  matches the current time. Additionally, the daemon touches the configuration file for the action every time it
  executes to keep of track when it last executed.

ChronosTimeRecurring options include a combination of the following options (which are largely a subset of
iCalendar options for recurring actions):

* starttime - (required) a date/time string parsed by strtotime that determines when the first execution of the action
  				should be.
* frequency - (required) the frequency of execution. Valid values are "hourly", "minutely", "daily", "weekly".
* interval - (optional, default 10) a multiplier for frequency (e.g. "weekly" with interval of 2 is fortnight.)
* byday - (optional) a string or array of strings that define days of the week when the action is to be executed.
				Valid values are "su","mo","tu","we","th","fr","sa"
* byhour - (optional) an int or array of ints that define the hours of the day when the action is to be executed.
* byminute - (optional) an int or array of ints that define the minutes of the hours when the action is to be executed.

Here is an example of a recurring action.
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

## Execution Options

The following options are used to control what to execute:

* actionType - (required) this can be "url" or "method".
* url - if actionType is "url", this is the URL to hit. The output of the request is ignored, it is visited for its side effects.
* method - if actionType is "method", this specifies a static method in the SilverStripe app to execute, of the
  for "class::method".
* parameters - if actionType is "method", this is an associative array that is passed as a parameter to the method. This
  must be serialisable in json (objects will have types lost).

## Replacing Scheduled Actions

You can replace all scheduled actions that have a given identifier with a new set of actions in one call:

	Chronos::replace(array(
		new ChronosScheduledAction(
			array(
				"timeSpecification" => "1-jan-2013 9:00",
				"actionType" => "method",
				"method" => 'MyClass::my_method'
			)
		)
	), "my-fabulous-schedule");

An optional third parameter can be passed which allows you to use a new identifier to store the new actions.

## Removing Scheduled Actions

Actions can only be removed from the schedule by supplying the identifier that groups them:

	Chronos::remove("my-fabulous-schedule");

Note that single execution actions are automatically removed from the schedule by the daemon once they are executed.

## Using an Alternative Temp Location

The current schedule is stored in the file system as a set of files (containing a json representation of each action),
so the daemon can have optimal performance and not require a database connection. By default, these files are stored
in a directory called 'chronos' that is a subdirectory of the temp folder used by SilverStripe.

You can change this location by calling Chronos::set_config_dir($path). The same path must be provided to the daemon
on startup, and the directory must be writable by the Apache user.

## Daemon Options

The daemon looks for command line options of the form name=value. It's options include:

* temp - (no default, required) full path to the directory where schedule is stored.
* resolution - (default 1) Resolution of execution. Determines the number of seconds that the daemon sleeps between
			when it checks if there is work to do. The smaller the number, the more accurately the execution, but
			possibly with a small increase in server load. If this is small, tolerance should be several times higher.
			For larger values of resolution, the ratio can be reduced but tolerance should always be higher.
* config_refresh - (default 30) the number of seconds between reloading the schedule from the file system.
* tolerance - (default 10) Specifies the number of seconds of tolerance. A scheduled action will execute if
			its date/time is the current time, or up to 'tolerance' seconds late.
* time_limit - (default 0) the number of seconds for the daemon to execute. 0 indicates no limit. This is mostly
			used by unit tests.

## Timing Considerations

* The module has no way to ensure that a recurring action has finished before initiating it again. It is the
  developer's responsibility to handle this appropriately.

## Security Considerations

The daemon invokes static methods by running sake to execute Chronos/execmethod, and passes the parameters of the
method to it. **You need to ensure that Chronos/execmethod is not accessible via the front end.**

# Internals

Scheduled actions are added or removed from the current schedule via Chronos::add() and ::remove().

ScheduledActions are persisted to the file system.

Scheduled actions are converted from their master database form to a file-based
form that can be executed without a database connection by the execution script.

The files are stored in the temp directory, and are constructed by either a /dev/build (which
rebuilds all files based on DataObjects), or by manipulation of the scheduled actions at the API
level, which rebuilds only the appropriate subset of the files.

Scheduled actions are executed by a custom, non-SilverStripe script. This script is scheduled to
execute every minute, and designed to be extremely light-weight.

# Known Limitations

* Chronos/execmethod is not blocked to web access. It should probably ensure that it only runs from the command
  line, and check that this works when the daemon calls it.
* byday, byhour and byminute are not yet implemented in the daemon.
* ::remove() is currently not implemented.