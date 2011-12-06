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


# Installation


# Usage


# Internals

Scheduled actions are added or removed from the current schedule via the Chronos class.

ScheduledActions are persisted to the file system.

Scheduled actions are converted from their master database form to a file-based
form that can be executed without a database connection by the execution script.

The files are stored in the temp directory, and are constructed by either a /dev/build (which
rebuilds all files based on DataObjects), or by manipulation of the scheduled actions at the API
level, which rebuilds only the appropriate subset of the files.

Scheduled actions are executed by a custom, non-SilverStripe script. This script is scheduled to
execute every minute, and designed to be extremely light-weight.

# Known Limitations
