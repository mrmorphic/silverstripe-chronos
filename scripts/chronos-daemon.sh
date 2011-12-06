#!/bin/bash
# Usage:
#    chronos_daemon <paremeters>
#
# Parameters:
#    temp=<path>		Mandatory parameter that points to the temporary directory when the module writes the schedule
#						files. Usually under the chronos subdirectory of the SilverStripe site's temp directory.
#

cd "$( dirname "${BASH_SOURCE[0]}" )"
php _daemon.php "$@"