<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the functions used by the plugin.
 *
 * @package   local_webhooks
 * @copyright 2017 "Valentin Popov" <info@valentineus.link>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined("MOODLE_INTERNAL") || die();

require_once(__DIR__ . "/locallib.php");

/**
 * Change the status of the service.
 *
 * @param  number  $serviceid
 * @return boolean
 */
function local_webhooks_change_status($serviceid) {
    global $DB;

    $result = false;
    if ($record = local_webhooks_get_record($serviceid)) {
        $record->enable = !boolval($record->enable);
        $result = local_webhooks_update_record($record);
    }

    return $result;
}

/**
 * Search for services that contain the specified event.
 *
 * @param  string  $eventname
 * @param  boolean $active
 * @return array
 */
function local_webhooks_search_services_by_event($eventname, $active = false) {
    $recordlist = local_webhooks_get_list_records();
    $active     = boolval($active);
    $result     = array();

    foreach ($recordlist as $record) {
        if (!empty($record->events[$eventname])) {
            if ($active && boolval($record->enable)) {
                $result[] = $record;
            }

            if (!$active) {
                $result[] = $record;
            }
        }
    }

    return $result;
}

/**
 * Get the record from the database.
 *
 * @param  number $serviceid
 * @return object
 */
function local_webhooks_get_record($serviceid) {
    global $DB;

    try {
        $servicerecord = $DB->get_record("local_webhooks_service", array("id" => $serviceid), "*", MUST_EXIST);
    } catch (dml_exception $e) {
        throw new moodle_exception('error', 'local_webhooks', '', null, $e->getMessage());
    }

    if (!empty($servicerecord->events)) {
        $servicerecord->events = local_webhooks_deserialization_data($servicerecord->events);
    }

    return $servicerecord;
}

/**
 * Get all records from the database.
 *
 * @param  number $limitfrom
 * @param  number $limitnum
 * @return array
 */
function local_webhooks_get_list_records($limitfrom = 0, $limitnum = 0) {
    global $DB;

    try {
        $listrecords = $DB->get_records("local_webhooks_service", null, "id", "*", $limitfrom, $limitnum);
    } catch (dml_exception $e) {
        throw new moodle_exception('error_getting_records', 'local_webhooks');
    }

    foreach ($listrecords as $servicerecord) {
        if (!empty($servicerecord->events)) {
            $servicerecord->events = local_webhooks_deserialization_data($servicerecord->events);
        }
    }

    return $listrecords;
}

/**
 * Get a list of all system events.
 *
 * @return array
 */
function local_webhooks_get_list_events() {
    return report_eventlist_list_generator::get_all_events_list(true);
}

/**
 * Create an entry in the database.
 *
 * @param  object  $record
 * @return boolean
 */
function local_webhooks_create_record($record) {
    global $DB;

    if (!empty($record->events)) {
        $record->events = local_webhooks_serialization_data($record->events);
    }

    try {
        $result = $DB->insert_record("local_webhooks_service", $record, true, false);
    } catch (dml_exception $e) {
        throw new moodle_exception('error', 'local_webhooks', '', null, $e->getMessage());
    }

    /* Clear the plugin cache */
    local_webhooks_cache_reset();

    /* Event notification */
    local_webhooks_events::service_added($result);

    return boolval($result);
}

/**
 * Update the record in the database.
 *
 * @param  object  $data
 * @return boolean
 */
function local_webhooks_update_record($record) {
    global $DB;

    if (empty($record->id)) {
        throw new moodle_exception("missingparam", "error", null, "id");
    }

    $record->events = !empty($record->events) ? local_webhooks_serialization_data($record->events) : null;
    try {
        $result = $DB->update_record("local_webhooks_service", $record, false);
    } catch (dml_exception $e) {
        throw new moodle_exception("dberror", "error", null, $e->getMessage());
    }

    /* Clear the plugin cache */
    local_webhooks_cache_reset();

    /* Event notification */
    local_webhooks_events::service_updated($record->id);

    return $result;
}

/**
 * Delete the record from the database.
 *
 * @param  number  $serviceid
 * @return boolean
 */
function local_webhooks_delete_record($serviceid) {
    global $DB;

    try {
        $result = $DB->delete_records("local_webhooks_service", array("id" => $serviceid));
    } catch (dml_exception $e) {
        throw new moodle_exception("Error deleting record", "local_webhooks", "", null, $e);
    }

    /* Clear the plugin cache */
    local_webhooks_cache_reset();

    /* Event notification */
    local_webhooks_events::service_deleted($serviceid);

    return $result;
}

/**
 * Delete all records from the database.
 *
 * @return boolean
 */
function local_webhooks_delete_all_records() {
    global $DB;

    try {
        $result = $DB->delete_records("local_webhooks_service", null);
    } catch (dml_exception $e) {
        throw new moodle_exception('error', 'local_webhooks', '', null, $e->getMessage());
    }

    /* Clear the plugin cache */
    local_webhooks_cache_reset();

    /* Event notification */
    local_webhooks_events::service_deletedall();

    return $result;
}

/**
 * Create a backup.
 *
 * @return string
 */
function local_webhooks_create_backup() {
    try {
        $listrecords = local_webhooks_get_list_records();
    } catch (moodle_exception $e) {
        throw $e;
    }
    $result      = local_webhooks_serialization_data($listrecords);

    /* Event notification */
    local_webhooks_events::backup_performed();

    return $result;
}

/**
 * Restore from a backup.
 *
 * @param string $data
 */
function local_webhooks_restore_backup($data, $deleterecords = false) {
    $listrecords = local_webhooks_deserialization_data($data);

    if ($deleterecords) {
        try {
            local_webhooks_delete_all_records();
        } catch (moodle_exception $e) {
            throw new moodle_exception('errordeletingrecords', 'local_webhooks');
        }
    }

        try {
            foreach ($listrecords as $servicerecord) {
                local_webhooks_create_record($servicerecord);
            }
        } catch (moodle_exception $e) {
            throw new moodle_exception('errorcreatingrecord', 'local_webhooks');
        }

    /* Event notification */
    local_webhooks_events::backup_restored();
}

/**
 * Send the event remotely to the service.
 *
 * @param  array  $event
 * @param  object $callback
 * @return array
 */
function local_webhooks_send_request($event, $callback) {
    global $CFG;

    $event["host"]  = parse_url($CFG->wwwroot)["host"];
    $event["token"] = $callback->token;
    $event["extra"] = $callback->other;

    $curl = new curl();
    $curl->setHeader(array("Content-Type: application/" . $callback->type));
    $curl->post($callback->url, json_encode($event));
    $response = $curl->getResponse();

    /* Event notification */
    local_webhooks_events::response_answer($callback->id, $response);

    return $response;
}