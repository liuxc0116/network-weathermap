<?php
// Pluggable datasource for PHP Weathermap 0.9
// - return a live SNMP value

// doesn't work well with large values like interface counters (I think this is a rounding problem)
// - also it doesn't calculate rates. Just fetches a value.

// useful for absolute GAUGE-style values like DHCP Lease Counts, Wireless AP Associations, Firewall Sessions
// which you want to use to colour a NODE

// You could also fetch interface states from IF-MIB with it.

// TARGET snmp3:PROFILE1:hostname:1.3.6.1.4.1.3711.1.1:1.3.6.1.4.1.3711.1.2
// (that is, TARGET snmp3:profilename:host:in_oid:out_oid

// http://feathub.com/howardjones/network-weathermap/+1

class WeatherMapDataSource_snmpv3 extends WeatherMapDataSource
{
    protected $down_cache;

    function Init(&$map)
    {
        // We can keep a list of unresponsive nodes, so we can give up earlier
        $this->down_cache = array();

        if (function_exists('snmp3_get')) {
            return TRUE;
        }
        wm_debug("SNMP3 DS: snmp3_get() not found. Do you have the PHP SNMP module?\n");

        return FALSE;
    }


    function Recognise($targetstring)
    {
        if (preg_match("/^snmp3:([^:]+):([^:]+):([^:]+):([^:]+)$/", $targetstring, $matches)) {
            return TRUE;
        }
        return FALSE;

    }

    function ReadData($targetstring, &$map, &$item)
    {
        $data[IN] = NULL;
        $data[OUT] = NULL;
        $data_time = 0;

        $timeout = 1000000;
        $retries = 2;
        $abort_count = 0;

        $get_results = NULL;
        $out_result = NULL;

        $timeout = intval($map->get_hint("snmp_timeout", $timeout));
        $abort_count = intval($map->get_hint("snmp_abort_count", $abort_count));
        $retries = intval($map->get_hint("snmp_retries", $retries));

        wm_debug("Timeout changed to " . $timeout . " microseconds.\n");
        wm_debug("Will abort after $abort_count failures for a given host.\n");
        wm_debug("Number of retries changed to " . $retries . ".\n");

        if (preg_match('/^snmp3:([^:]+):([^:]+):([^:]+):([^:]+)$/', $targetstring, $matches)) {
            $profile_name = $matches[1];
            $host = $matches[2];
            $oids[IN] = $matches[3];
            $oids[OUT] = $matches[4];

            if (
                ($abort_count == 0)
                || (
                    ($abort_count > 0)
                    && (!isset($this->down_cache[$host]) || intval($this->down_cache[$host]) < $abort_count)
                )
            ) {
                if (function_exists("snmp_get_quick_print")) {
                    $was = snmp_get_quick_print();
                    snmp_set_quick_print(1);
                }
                if (function_exists("snmp_get_valueretrieval")) {
                    $was2 = snmp_get_valueretrieval();
                }

                if (function_exists('snmp_set_oid_output_format')) {
                    snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
                }

                if (function_exists('snmp_set_valueretrieval')) {
                    snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
                }


                # snmpv3_PROFILE1_import 33
                #
                # OR
                #
                # snmpv3_PROFILE1_username
                # snmpv3_PROFILE1_seclevel
                # snmpv3_PROFILE1_authproto
                # snmpv3_PROFILE1_authpass
                # snmpv3_PROFILE1_privproto
                # snmpv3_PROFILE1_privpass

                $import = $map->get_hint("snmpv3_" . $profile_name . "_import");

                $parts = array(
                    "username" => "",
                    "seclevel" => "noAuthNoPriv",
                    "authpass" => "",
                    "privpass" => "",
                    "authproto" => "",
                    "privproto" => ""
                );

                $params = array();

                // If they are explicitly defined...
                if (is_null($import)) {
                    foreach ($parts as $keyname => $default) {
                        $params[$keyname] = $map->get_hint("snmpv3_" . $profile_name . "_" . $keyname, $default);
                    }
                } else {
                    // if they are to be copied from a Cacti profile...

                    if (function_exists("db_fetch_row")) {
                        foreach ($parts as $keyname => $default) {
                            $params[$keyname] = $default;
                        }
                        // this is something that should be cached or done in prefetch
                        $result = db_fetch_assoc(sprintf("select * from host where id=%d LIMIT 1", intval($import)));

                        if (! $result) {
                            wm_warn("snmpv3_" . $profile_name . "_import failed to read data from Cacti host profile");
                        } else {

                            $mapping = array(
                                "username" => "snmp_username",
                                "authpass" => "snmp_password",
                                "privpass" => "snmp_priv_passphrase",
                                "authproto" => "snmp_auth_protocol",
                                "privproto" => "snmp_priv_protocol"
                            );
                            foreach ($mapping as $param => $fieldname) {
                                $params[$param] = $result[$fieldname];
                            }
                            if ($params['privproto'] == "[None]" || $params['privpass'] == '') {
                                $params['seclevel'] = "authNoPriv";
                                $params['privproto'] = "";
                            } else {
                                $params['seclevel'] = "authPriv";
                            }
                        }

                    } else {
                        wm_warn("snmpv3_" . $profile_name . "_import is set but not running in Cacti");
                    }
                }

                ob_start();
                var_dump($params);
                $result = ob_get_clean();
                wm_debug($result);

                $channels = array(
                    'in' => IN,
                    'out' => OUT
                );
                $results = array();

                foreach ($channels as $name => $id) {
                    if ($oids[$id] != '-') {
                        $oid = $oids[$id];
                        wm_debug("Going to get $oid\n");
                        $results[$id] = snmp3_get($host, $params['username'], $params['seclevel'], $params['authproto'], $params['authpass'], $params['privproto'], $params['privpass'], $oid, $timeout, $retries);
                        if ($results[$id] !== FALSE) {
                            $data[$id] = floatval($get_results);
                            $item->add_hint("snmp_" . $name . "_raw", $results[$id]);
                        } else {
                            $this->down_cache{$host}++;
                        }
                    }
                }


                wm_debug("SNMP3 ReadData: Got %s and %s\n", $results[IN], $results[OUT]);

                $data_time = time();

                if (function_exists("snmp_set_quick_print")) {
                    snmp_set_quick_print($was);
                }

            } else {
                wm_warn("SNMP for $host has reached $abort_count failures. Skipping. [WMSNMP01]");
            }
        }

        wm_debug("SNMP3 ReadData: Returning (" . ($data[IN] === NULL ? 'NULL' : $data[IN]) . "," . ($data[OUT] === NULL ? 'NULL' : $data[OUT]) . ",$data_time)\n");

        return array($data[IN], $data[OUT], $data_time);
    }
}

// vim:ts=4:sw=4:
