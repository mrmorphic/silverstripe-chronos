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

Still under active development.

## Overview


# Installation

* Install the chronos module into the root of your project and /dev/build.
* Configure chronos/scripts/chronos_daemon.sh to run at system startup. This should run as the same user as the web
  server (typically the apache or www-data user).

# Usage

To add a single scheduled action (one-off execution), you can do:

		Chronos::add(array(
			new ChronosScheduledAction(
				array(
					"timeSpecification" => "1-jan-2010 9:00",
					"actionType" => "method",
					"method" => 'AClass::a_method'
					"parameters" => array("id" => $id)
				)
			)
		));

To add a recurring scheduled action, you can do:

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
			)
		));

Each scheduled action can have the following properties:

* timeSpecificatioon - this can be a single date/time parseable by strtotime, or can be a ChronosTimeRecurring object (see below)
* actionType - this can be "url" or "method".
* url - if actionType is "url", this is the URL to hit. The output of the request is ignored, it is visited for its side effects.
* method - if actionType is "method", this specifies a static method in the SilverStripe app to execute, of the
  for "class::method".
* parameters - if actionType is "url", this is an associative array that is passed as a parameter to the method. This
  must be serialisable in json (objects will have types lost).

# Internals

Scheduled actions are added or removed from the current schedule via the Chronos class.

ScheduledActions are persisted to the file system.

Scheduled actions are converted from their master database form to a file-based
form that can be executed without a database connection by the execution script.

The files are stored in the temp directory, and are constructed by either a /dev/build (which
rebuilds all files based on DataObjects), or by manipulation of the scheduled actions at the API
level, which rebuilds only the appropriate subset of the files.

Scheduled actions are executed by a custom, non-SilverStripe script. This script is very lightweight
and runs continuously, sleeping when not processing the schedule. When it detects an action to execute,
it executes it in a background sub-process.

# Known Limitations

* byminute, byhour and byday options on recurring scheduled events are accepted by the module API, but
  ignored by the daemon.
