<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2023 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '0');

if (!class_exists('ZipArchive')) {
    die('Error: ZipArchive class not found! Install php-zip module.');
}

/*!
 * \brief Parse network speed
 */
function parseNetworkSpeed($s)
{
    $s = round($s / 1000, 2);

    if ($s <= 1) {
        return 1;
    }
    if ($s <= 2) {
        return 2;
    }
    if ($s <= 4) {
        return 4;
    }
    if ($s <= 6) {
        return 6;
    }
    if ($s <= 8) {
        return 8;
    }
    if ($s <= 10) {
        return 10;
    }
    if ($s <= 20) {
        return 20;
    }
    if ($s <= 30) {
        return 30;
    }
    if ($s <= 40) {
        return 40;
    }
    if ($s <= 60) {
        return 60;
    }
    if ($s <= 80) {
        return 80;
    }
    if ($s <= 100) {
        return 100;
    }
    if ($s <= 120) {
        return 120;
    }
    if ($s <= 150) {
        return 150;
    }
    if ($s <= 250) {
        return 250;
    }
    if ($s <= 500) {
        return 500;
    }
    if ($s <= 1000) {
        return 1000;
    }
    if ($s <= 2500) {
        return 2500;
    }
    if ($s <= 10000) {
        return 10000;
    }
    if ($s <= 40000) {
        return 40000;
    }

    return 100000;
}

define('EOL', "\r\n");
define('ZIP_CODE', '15-950');

$customers = array();

$customer_netdevices = isset($_POST['customernetdevices']);

$po_buffer = $w_buffer = $pe_buffer = $uwa_buffer = $lb_buffer = $lk_buffer = '';

function to_csv($data)
{
    foreach ($data as $key => $val) {
        $data[$key] = '"' . str_replace('"', '""', $val) . '"';
    }
    return implode(',', array_values($data));
}

function to_wgs84($coord, $ifLongitude = true)
{
    return str_replace(',', '.', sprintf("%.04f", $coord));
}

$borough_types = array(
    1 => 'gm. miejska',
    2 => 'gm. wiejska',
    3 => 'gm. miejsko-wiejska',
    4 => 'gm. miejsko-wiejska',
//  4 => 'miasto w gminie miejsko-wiejskiej',
    5 => 'gm. miejsko-wiejska',
//  5 => 'obszar wiejski gminy miejsko-wiejskiej',
    8 => 'dzielnica gminy Warszawa-Centrum',
    9 => 'dzielnica',
);

$linktypes = array(
    array('linia' => "kablowa", 'trakt' => "podziemny", 'technologia' => "kablowe parowe miedziane", 'typ' => "UTP",
        'technologia_dostepu' => "100 Mb/s Fast Ethernet", 'liczba_jednostek' => "1",
        'jednostka' => "linie w kablu"),
    array('linia' => "bezprzewodowa", 'trakt' => "NIE DOTYCZY", 'technologia' => "radiowe", 'typ' => "WiFi",
        'technologia_dostepu' => "WiFi - 2,4 GHz", 'liczba_jednostek' => "1",
        'jednostka' => "kanały"),
    array('linia' => "kablowa", 'trakt' => "podziemny w kanalizacji", 'technologia' => "światłowodowe", 'typ' => "G.652",
        'technologia_dostepu' => "100 Mb/s Fast Ethernet", 'liczba_jednostek' => "2",
        'jednostka' => "włókna")
);

$projects = $LMS->GetProjects();
if (!empty($invprojects)) {
    foreach ($projects as $idx => $project) {
        if (!in_array($idx, $invprojects)) {
            unset($projects[$idx]);
        }
    }
}

$allradiosectors = $DB->GetAllByKey("SELECT * FROM netradiosectors ORDER BY id", "id");

$teryt_cities = $DB->GetAllByKey(
    "SELECT lc.id AS cityid,
        ls.name AS area_woj,
        ld.name AS area_pow,
        lb.name AS area_gmi,
        (" . $DB->Concat('ls.ident', 'ld.ident', 'lb.ident', 'lb.type') . ") AS area_terc,
        lc.name AS area_city,
        lc.ident AS area_simc,
        p.zip AS location_zip,
        (CASE WHEN EXISTS (SELECT 1 FROM location_streets lst WHERE lst.cityid = lc.id) THEN 1 ELSE 0 END) AS with_streets
    FROM location_cities lc
    LEFT JOIN location_boroughs lb ON lb.id = lc.boroughid
    LEFT JOIN location_districts ld ON ld.id = lb.districtid
    LEFT JOIN location_states ls ON ls.id = ld.stateid
    LEFT JOIN (
        SELECT pna.cityid, pna.streetid, " . $DB->GroupConcat('pna.zip', ',', true) . " AS zip FROM pna
        WHERE pna.streetid IS NULL
        GROUP BY pna.cityid, pna.streetid
        HAVING " . $DB->GroupConcat('pna.zip', ',', true) . " NOT ?LIKE? '%,%'
    ) p ON p.cityid = lc.id
    JOIN (
        SELECT DISTINCT city_id FROM addresses
    ) a ON a.city_id = lc.id",
    'cityid'
);

$teryt_streets = $DB->GetAllByKey(
    "SELECT lst.cityid, lst.id AS streetid,
        tu.cecha AS address_cecha,
        (CASE WHEN lst.name2 IS NOT NULL THEN " . $DB->Concat('lst.name2', "' '", 'lst.name') . " ELSE lst.name END) AS address_ulica,
        tu.sym_ul AS address_symul,
        p.zip AS location_zip
    FROM location_streets lst
    LEFT JOIN teryt_ulic tu ON tu.id = lst.id
    LEFT JOIN (
        SELECT pna.cityid, pna.streetid, " . $DB->GroupConcat('pna.zip', ',', true) . " AS zip FROM pna
        GROUP BY pna.cityid, pna.streetid HAVING " . $DB->GroupConcat('pna.zip', ',', true) . " NOT ?LIKE? '%,%'
    ) p ON p.cityid = lst.cityid AND p.streetid = lst.id
    JOIN (
        SELECT DISTINCT street_id FROM addresses
    ) a ON a.street_id = lst.id",
    'streetid'
);

$real_netnodes = $DB->GetAllByKey(
    "SELECT nn.id, nn.name, nn.invprojectid, nn.type, nn.status, nn.ownership, nn.coowner,
        nn.longitude, nn.latitude,
        a.city_id as location_city, a.street_id as location_street, a.house as location_house, a.flat as location_flat,
        a.city as location_city_name, a.street as location_street_name,
        (CASE WHEN (a.flat IS NULL OR a.flat = '') THEN a.house ELSE " . $DB->Concat('a.house', "'/'", 'a.flat') . " END) AS address_budynek,
        a.zip AS location_zip
    FROM netnodes nn
    LEFT JOIN addresses a ON nn.address_id = a.id
    ORDER BY nn.id",
    'id'
);

// prepare info about network devices from lms database
$netdevices = $DB->GetAllByKey(
    "SELECT nd.id, nd.ownerid, ports,
        nd.longitude, nd.latitude, nd.status, nd.netnodeid,
        (CASE WHEN nd.invprojectid = 1 THEN nn.invprojectid ELSE nd.invprojectid END) AS invprojectid,
        a.city_id AS location_city,
        a.street_id AS location_street, a.house AS location_house, a.flat AS location_flat,
        a.city AS location_city_name, a.street AS location_street_name,
        (CASE WHEN (a.flat IS NULL OR a.flat = '') THEN a.house ELSE " . $DB->Concat('a.house', "'/'", 'a.flat') . " END) AS address_budynek,
        a.zip AS location_zip,
        COALESCE(t.passive, 1) AS passive
    FROM netdevices nd
    LEFT JOIN netnodes nn ON nn.id = nd.netnodeid
    LEFT JOIN addresses a ON nd.address_id = a.id
    LEFT JOIN netdevicemodels m ON m.id = nd.netdevicemodelid
    LEFT JOIN netdevicetypes t ON t.id = m.type
    WHERE " . ($customer_netdevices ? 'nd.ownerid IS NULL AND' : '') . " EXISTS (
        SELECT id FROM netlinks nl WHERE nl.src = nd.id OR nl.dst = nd.id
    )
    ORDER BY nd.id",
    'id'
);

if ($customer_netdevices) {
    function find_nodes_for_netdev($customerid, $netdevid, &$customer_nodes, &$customer_netlinks, &$netdevices)
    {
        if (isset($netdevices['processed'])) {
            return array();
        }

        $netdevices['processed'] = true;

        if (isset($customer_nodes[$customerid . '_' . $netdevid])) {
            $nodeids = explode(',', $customer_nodes[$customerid . '_' . $netdevid]['nodeids']);
        } else {
            $nodeids = array();
        }

        if (!empty($customer_netlinks)) {
            foreach ($customer_netlinks as &$customer_netlink) {
                if ($customer_netlink['src'] == $netdevid) {
                    $next_netdevid = $customer_netlink['dst'];
                } else if ($customer_netlink['dst'] == $netdevid) {
                    $next_netdevid = $customer_netlink['src'];
                } else {
                    continue;
                }
                $nodeids = array_merge($nodeids, find_nodes_for_netdev(
                    $customerid,
                    $next_netdevid,
                    $customer_nodes,
                    $customer_netlinks,
                    $netdevices
                ));
            }
            unset($customer_netlink);
        }

        return $nodeids;
    }

    // search for links between operator network devices and customer network devices
    $uni_links = $DB->GetAllByKey(
        "SELECT nl.id AS netlinkid, nl.type AS type, nl.technology AS technology,
            nl.speed AS speed, rs.frequency, rs.id AS radiosectorid,
            c.id AS customerid, c.type AS customertype,
            (CASE WHEN ndsrc.ownerid IS NULL THEN nl.src ELSE nl.dst END) AS operator_netdevid,
            (CASE WHEN ndsrc.ownerid IS NULL THEN ndsrc.status ELSE nddst.status END) AS operator_netdevstatus,
            (CASE WHEN ndsrc.ownerid IS NULL THEN nddst.invprojectid ELSE ndsrc.invprojectid END) AS invprojectid,
            (CASE WHEN ndsrc.ownerid IS NULL THEN nl.dst ELSE nl.src END) AS netdevid,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.city_id ELSE asrc.city_id END) AS location_city,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.city ELSE asrc.city END) AS location_city_name,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.street_id ELSE asrc.street_id END) AS location_street,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.street ELSE asrc.street END) AS location_street_name,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.house ELSE asrc.house END) AS location_house,
            (CASE WHEN ndsrc.ownerid IS NULL THEN adst.zip ELSE asrc.zip END) AS location_zip
        FROM netlinks nl
        JOIN netdevices ndsrc ON ndsrc.id = nl.src
        LEFT JOIN addresses asrc ON asrc.id = ndsrc.address_id
        JOIN netdevices nddst ON nddst.id = nl.dst
        LEFT JOIN addresses adst ON adst.id = nddst.address_id
        JOIN customers c ON (ndsrc.ownerid IS NULL AND c.id = nddst.ownerid)
            OR (nddst.ownerid IS NULL AND c.id = ndsrc.ownerid)
        LEFT JOIN netradiosectors rs ON (ndsrc.ownerid IS NULL AND rs.id = nl.srcradiosector)
            OR (nddst.ownerid IS NULL AND rs.id = nl.dstradiosector)
        WHERE (ndsrc.ownerid IS NULL AND nddst.ownerid IS NOT NULL)
            OR (nddst.ownerid IS NULL AND ndsrc.ownerid IS NOT NULL)
        ORDER BY nl.id",
        'netlinkid'
    );
    if (!empty($uni_links)) {
        $customer_netlinks = $DB->GetAllByKey(
            "SELECT " . $DB->Concat('nl.src', "'_'", 'nl.dst') . " AS netlink,
                nl.src,
                nl.dst
            FROM netlinks nl
            JOIN netdevices ndsrc ON ndsrc.id = nl.src
            JOIN netdevices nddst ON nddst.id = nl.dst
            WHERE ndsrc.ownerid IS NOT NULL AND nddst.ownerid IS NOT NULL
                AND ndsrc.ownerid = nddst.ownerid",
            'netlink'
        );

        $customer_nodes = $DB->GetAllByKey(
            "SELECT " . $DB->GroupConcat('n.id') . " AS nodeids,
                " . $DB->Concat('CASE WHEN n.ownerid IS NULL THEN nd.ownerid ELSE n.ownerid END', "'_'", 'n.netdev') . " AS customerid_netdev
            FROM nodes n
            LEFT JOIN netdevices nd ON nd.id = n.netdev AND n.ownerid IS NULL AND nd.ownerid IS NOT NULL
            WHERE n.ownerid IS NOT NULL OR nd.ownerid IS NOT NULL
                AND EXISTS (
                    SELECT na.id FROM nodeassignments na
                    JOIN assignments a ON a.id = na.assignmentid
                    WHERE na.nodeid = n.id AND a.suspended = 0
                        AND a.period IN (" . implode(',', array(YEARLY, HALFYEARLY, QUARTERLY, MONTHLY, DISPOSABLE)) . ")
                        AND a.datefrom < ?NOW? AND (a.dateto = 0 OR a.dateto > ?NOW?)
                )
                AND NOT EXISTS (
                    SELECT id FROM assignments aa
                    WHERE aa.customerid = (CASE WHEN n.ownerid IS NULL THEN nd.ownerid ELSE n.ownerid END)
                        AND aa.tariffid IS NULL AND aa.liabilityid IS NULL
                        AND aa.datefrom < ?NOW?
                        AND (aa.dateto > ?NOW? OR aa.dateto = 0)
                )
            GROUP BY customerid_netdev",
            'customerid_netdev'
        );

        // collect customer node/node-netdev identifiers connected to customer subnetwork
        $netdevs = array();

        foreach ($uni_links as $netlinkid => &$netlink) {
            $nodes = find_nodes_for_netdev(
                $netlink['customerid'],
                $netlink['netdevid'],
                $customer_nodes,
                $customer_netlinks,
                $netdevs
            );
            if (empty($nodes)) {
                unset($uni_links[$netlinkid]);
            } else {
                $netlink['nodes'] = $nodes;
            }
        }
        unset($netlink);

        unset($customer_netlinks);
        unset($customer_nodes);
    }
}

if (empty($real_netnodes)) {
    $real_netnodes = array();
} else {
    foreach ($real_netnodes as $k => $v) {
        $tmp = array(
            'city_name'      => $v['location_city_name'],
            'location_house' => $v['location_house'],
            'location_flat'  => $v['location_flat'],
            'street_name'    => $v['location_street_name'],
        );

        $location = location_str($tmp);

        if (!$location) {
            $location = '';
        }

        $real_netnodes[$k]['location'] = $location;
    }
}

//foreach ($real_netnodes as $idx => $netnode)
//  echo "network node $idx: " . print_r($netnode, true) . '<br>';

// get node gps coordinates which are used for network range gps calculation
$nodecoords = $DB->GetAllByKey(
    "SELECT id, longitude, latitude
    FROM nodes
    WHERE longitude IS NOT NULL
        AND latitude IS NOT NULL",
    'id'
);

// prepare info about network nodes
$netnodes   = array();
$netdevs    = array();
$foreigners = array();
$netnodeid = 1;

$root_netdevice_id = intval(ConfigHelper::getConfig('phpui.root_netdevice_id'));
$root_netnode_name = null;
$processed_child_netlinks = array();

if ($netdevices) {
    foreach ($netdevices as $netdevid => $netdevice) {
        $tmp = array(
            'city_name'      => $netdevice['location_city_name'],
            'location_house' => $netdevice['location_house'],
            'location_flat'  => $netdevice['location_flat'],
            'street_name'    => $netdevice['location_street_name'],
        );

        $location = location_str($tmp);

        if ($location) {
            $netdevices[$netdevid]['location'] = $location;
        } else if ($netdevice['ownerid']) {
            $netdevices[$netdevid]['location'] = $LMS->getAddressForCustomerStuff($netdevice['ownerid']);
        }

        $accessports = $DB->GetAll(
            "SELECT linktype AS type, linktechnology AS technology,
                linkspeed AS speed, rs.frequency, " . $DB->GroupConcat('rs.id') . " AS radiosectors,
                c.type AS customertype, COUNT(port) AS portcount
            FROM nodes n
            JOIN customers c ON c.id = n.ownerid
            LEFT JOIN netradiosectors rs ON rs.id = n.linkradiosector
            WHERE n.netdev = ? " . ($customer_netdevices ? 'AND n.ownerid IS NOT NULL' : '') . "
                AND EXISTS
                    (SELECT na.id FROM nodeassignments na
                        JOIN assignments a ON a.id = na.assignmentid
                        WHERE na.nodeid = n.id AND a.suspended = 0
                            AND a.period IN (" . implode(',', array(YEARLY, HALFYEARLY, QUARTERLY, MONTHLY, DISPOSABLE)) . ")
                            AND (a.datefrom = 0 OR a.datefrom < ?NOW?) AND (a.dateto = 0 OR a.dateto > ?NOW?))
                AND NOT EXISTS
                    (SELECT id FROM assignments aa
                        WHERE aa.customerid = c.id AND aa.tariffid IS NULL AND aa.liabilityid IS NULL
                            AND (aa.datefrom < ?NOW? OR aa.datefrom = 0)
                            AND (aa.dateto > ?NOW? OR aa.dateto = 0))
            GROUP BY linktype, linktechnology, linkspeed, rs.frequency, c.type
            ORDER BY c.type",
            array($netdevice['id'])
        );

        if ($customer_netdevices) {
            // append uni links to access ports
            $access_links = $DB->GetAll(
                "SELECT nl.id
                FROM netlinks nl
                JOIN netdevices ndsrc ON ndsrc.id = nl.src
                JOIN netdevices nddst ON nddst.id = nl.dst
                WHERE (nl.src = ? AND ndsrc.ownerid IS NULL AND nddst.ownerid IS NOT NULL)
                    OR (nl.dst = ? AND nddst.ownerid IS NULL AND ndsrc.ownerid IS NOT NULL)
                ",
                array(
                    $netdevice['id'],
                    $netdevice['id'],
                )
            );
            if (!empty($access_links)) {
                if (empty($accessports)) {
                    $accessports = array();
                }
                foreach ($access_links as &$access_link) {
                    if (isset($uni_links[$access_link['id']])) {
                        $uni_link = &$uni_links[$access_link['id']];
                        $processed_access_link = false;
                        foreach ($accessports as &$access_port) {
                            if ($access_port['type'] == $uni_link['type']
                                && $access_port['technology'] == $uni_link['technology']
                                && $access_port['speed'] == $uni_link['speed']
                                && $access_port['frequency'] == $uni_link['frequency']
                                && $access_port['customertype'] == $uni_link['customertype']) {
                                $processed_access_link = true;
                                $access_port['portcount']++;
                                if (!empty($uni_link['radiosectorid'])) {
                                    if (empty($access_ports['radiosectors'])) {
                                        $access_port['radiosectors'] = $uni_link['radiosectorid'];
                                    } else {
                                        $access_port['radiosectors'] .= ',' . $uni_link['radiosectorid'];
                                    }
                                }
                                if (isset($access_port['uni_links'])) {
                                    $access_port['uni_links'][] = $access_link['id'];
                                } else {
                                    $access_port['uni_links'] = array($access_link['id']);
                                }
                            }
                        }
                        unset($access_port);
                        if (!$processed_access_link) {
                            $accessports[] = array(
                                'type' => $uni_link['type'],
                                'technology' => $uni_link['technology'],
                                'speed' => $uni_link['speed'],
                                'frequency' => $uni_link['frequency'],
                                'radiosectors' => $uni_link['radiosectorid'],
                                'customertype' => $uni_link['customertype'],
                                'uni_links' => array($access_link['id']),
                            );
                        }
                    }
                }
                unset($access_link);
            }
        }

        $netdevices[$netdevid]['invproject'] = $netdevice['invproject'] =
            !isset($netdevice['invprojectid']) || !strlen($netdevice['invprojectid']) ? '' : $projects[$netdevice['invprojectid']]['name'];

        $projectname = $prj = '';
        if (array_key_exists($netdevice['netnodeid'], $real_netnodes)) {
            $netnodename = mb_strtoupper($real_netnodes[$netdevice['netnodeid']]['name']);
            if (isset($real_netnodes[$netdevice['netnodeid']]['invprojectid']) && strlen($real_netnodes[$netdevice['netnodeid']]['invprojectid'])) {
                $projectname = $prj = $projects[$real_netnodes[$netdevice['netnodeid']]['invprojectid']]['name'];
            }
        } else {
            $netnodename = mb_strtoupper(empty($netdevice['location_city'])
                ? (isset($netdevice['location']) ? $netdevice['location'] : '(pusty)')
                : implode(
                    '_',
                    array(
                        $netdevice['location_city'],
                        $netdevice['location_street'],
                        $netdevice['location_house'],
                        $netdevice['location_flat'],
                    )
                ));
            if (array_key_exists($netnodename, $netnodes)) {
                if (!in_array($netdevice['invproject'], $netnodes[$netnodename]['invproject'])) {
                    $netnodes[$netnodename]['invproject'][] = $netdevice['invproject'];
                }
            } else {
                $prj = $netdevice['invproject'];
                $projectname = array($prj);
            }
        }

        $netdevice['netnodename'] = $netdevices[$netdevid]['netnodename'] = $netnodename;

        if (!array_key_exists($netnodename, $netnodes)) {
            if ($customer_netdevices) {
                $netnodes[$netnodename]['uni_links'] = array();
            }

            $netnodes[$netnodename]['id'] = $netnodeid;
            $netnodes[$netnodename]['invproject'] = $projectname;

            if (array_key_exists($netdevice['netnodeid'], $real_netnodes)) {
                $netnode = $real_netnodes[$netdevice['netnodeid']];
                $netnodes[$netnodename]['location'] = $netnode['location'];
                $netnodes[$netnodename]['location_city'] = $netnode['location_city'];
                $netnodes[$netnodename]['location_city_name'] = $netnode['location_city_name'];
                $netnodes[$netnodename]['location_street'] = $netnode['location_street'];
                $netnodes[$netnodename]['location_street_name'] = $netnode['location_street_name'];
                $netnodes[$netnodename]['location_house'] = $netnode['location_house'];
                $netnodes[$netnodename]['status'] = intval($netnode['status']);
                $netnodes[$netnodename]['type'] = intval($netnode['type']);
                $netnodes[$netnodename]['ownership'] = intval($netnode['ownership']);
                $netnodes[$netnodename]['coowner'] = $netnode['coowner'];

                if (strlen($netnode['coowner'])) {
                    $coowner = $netnode['coowner'];

                    if (!array_key_exists($coowner, $foreigners)) {
                        $foreigners[$coowner] = $coowner;
                    }
                }

                if (isset($teryt_cities[$netnode['location_city']])) {
                    $teryt_city = $teryt_cities[$netnode['location_city']];

                    $netnodes[$netnodename]['area_woj'] = $teryt_city['area_woj'];
                    $netnodes[$netnodename]['area_pow'] = $teryt_city['area_pow'];
                    $netnodes[$netnodename]['area_gmi'] = $teryt_city['area_gmi'];
                    $netnodes[$netnodename]['area_terc'] = $teryt_city['area_terc'];
                    $netnodes[$netnodename]['area_rodz_gmi'] = $borough_types[intval(substr($teryt_city['area_terc'], 6, 1))];
                    $netnodes[$netnodename]['area_city'] = $teryt_city['area_city'];
                    $netnodes[$netnodename]['area_simc'] = $teryt_city['area_simc'];
                    $netnodes[$netnodename]['location_zip'] = empty($teryt_city['location_zip']) ? '' : $teryt_city['location_zip'];

                    if (!empty($netnode['location_street']) && isset($teryt_streets[$netnode['location_street']])) {
                        $teryt_street = $teryt_streets[$netnode['location_street']];

                        $netnodes[$netnodename]['address_cecha'] = $teryt_street['address_cecha'];
                        $netnodes[$netnodename]['address_ulica'] = $teryt_street['address_ulica'];
                        $netnodes[$netnodename]['address_symul'] = $teryt_street['address_symul'];
                        $netnodes[$netnodename]['location_zip'] = empty($teryt_street['location_zip'])
                        ? $netnodes[$netnodename]['location_zip'] : $teryt_street['location_zip'];
                    }

                    if (!empty($netnode['location_zip'])) {
                        $netnodes[$netnodename]['location_zip'] = $netnode['location_zip'];
                    }
                }

                $netnodes[$netnodename]['address_budynek'] = $netnode['address_budynek'];

                if (!empty($netnode['longitude']) && !empty($netnode['latitude'])) {
                    $netnodes[$netnodename]['longitude'] = $netnode['longitude'];
                    $netnodes[$netnodename]['latitude'] = $netnode['latitude'];
                }
            } else {
                $netnodes[$netnodename]['location'] = isset($netdevice['location']) ? $netdevice['location'] : '';
                $netnodes[$netnodename]['location_city'] = $netdevice['location_city'];
                $netnodes[$netnodename]['location_city_name'] = $netdevice['location_city_name'];
                $netnodes[$netnodename]['location_street'] = $netdevice['location_street'];
                $netnodes[$netnodename]['location_street_name'] = $netdevice['location_street_name'];
                $netnodes[$netnodename]['location_house'] = $netdevice['location_house'];
                $netnodes[$netnodename]['status'] = 0;
                $netnodes[$netnodename]['type'] = 8;
                $netnodes[$netnodename]['ownership'] = 0;
                $netnodes[$netnodename]['coowner'] = '';

                if (isset($teryt_cities[$netdevice['location_city']])) {
                    $teryt_city = $teryt_cities[$netdevice['location_city']];

                    $netnodes[$netnodename]['area_woj'] = $teryt_city['area_woj'];
                    $netnodes[$netnodename]['area_pow'] = $teryt_city['area_pow'];
                    $netnodes[$netnodename]['area_gmi'] = $teryt_city['area_gmi'];
                    $netnodes[$netnodename]['area_terc'] = $teryt_city['area_terc'];
                    $netnodes[$netnodename]['area_rodz_gmi'] = $borough_types[intval(substr($teryt_city['area_terc'], 6, 1))];
                    $netnodes[$netnodename]['area_city'] = $teryt_city['area_city'];
                    $netnodes[$netnodename]['area_simc'] = $teryt_city['area_simc'];
                    $netnodes[$netnodename]['location_zip'] = empty($teryt_city['location_zip']) ? '' : $teryt_city['location_zip'];

                    if (!empty($netdevice['location_street']) && isset($teryt_streets[$netdevice['location_street']])) {
                        $teryt_street = $teryt_streets[$netdevice['location_street']];

                        $netnodes[$netnodename]['address_cecha'] = $teryt_street['address_cecha'];
                        $netnodes[$netnodename]['address_ulica'] = $teryt_street['address_ulica'];
                        $netnodes[$netnodename]['address_symul'] = $teryt_street['address_symul'];
                        $netnodes[$netnodename]['location_zip'] = empty($teryt_street['location_zip'])
                        ? $netnodes[$netnodename]['location_zip'] : $teryt_street['location_zip'];
                    }

                    if (!empty($netdevice['location_zip'])) {
                        $netnodes[$netnodename]['location_zip'] = $netdevice['location_zip'];
                    }
                }

                $netnodes[$netnodename]['address_budynek'] = $netdevice['address_budynek'];
            }

            $netnodes[$netnodename]['netdevices'] = array();

            if (!isset($netnodes[$netnodename]['longitude']) && !isset($netnodes[$netnodename]['latitude'])) {
                $netnodes[$netnodename]['longitudes'] = array();
                $netnodes[$netnodename]['latitudes'] = array();
            }

            $netnodes[$netnodename]['mode'] = empty($netdevice['passive']) ? 2 : 1;

            $netnodes[$netnodename]['media'] = array();
            $netnodes[$netnodename]['technologies'] = array();
            $netnodes[$netnodename]['parent_netnodename'] = null;
            $netnodes[$netnodename]['child_netdevices'] = array();
            $netnodes[$netnodename]['child_netnodenames'] = array();

            $netnodeid++;
        } elseif (empty($netdevice['passive']) && $netnodes[$netnodename]['mode'] < 2) {
            $netnodes[$netnodename]['mode'] = 2;
        }

        $netdevices[$netdevid]['ownership'] = $netnodes[$netnodename]['ownership'];

        $projectname = $prj = $netdevice['invproject'];
        if (!strlen($projectname)) {
            $status = 0;
        } else {
            $status = $netdevice['status'];
        }

        if ($netdevid == $root_netdevice_id) {
            $root_netnode_name = $netnodename;
        }

        if (!empty($accessports)) {
            foreach ($accessports as $ports) {
                $linktype = $ports['type'];
                $linktechnology = $ports['technology'];
                $linkspeed = $ports['speed'];
                $linkfrequency = empty($ports['frequency']) ? '' : (float) $ports['frequency'];
                $customertype = $ports['customertype'];
                if (!$linktechnology) {
                    switch ($linktype) {
                        case 0:
                            $linktechnology = 8;
                            break;
                        case 1:
                            $linktechnology = 101;
                            break;
                        case 2:
                            $linktechnology = 205;
                            break;
                    }
                }
                if ($linktype == LINKTYPE_WIRELESS && empty($linkfrequency)) {
                    switch ($linktechnology) {
                        case 100:
                            $linkfrequency = 2.4;
                            break;
                        case 101:
                            $linkfrequency = 5.5;
                            break;
                        default:
                            $linkfrequency = '';
                    }
                }
                if (!empty($linkfrequency)) {
                    $linkfrequency = str_replace(',', '.', (float) $linkfrequency);
                }

                if (empty($netnodes[$netnodename]['linkmaxspeed'][$linktype][$linktechnology])
                    || $netnodes[$netnodename]['linkmaxspeed'][$linktype][$linktechnology] < $linkspeed) {
                    $netnodes[$netnodename]['linkmaxspeed'][$linktype][$linktechnology] = $linkspeed;
                }

                if ($customer_netdevices && isset($ports['uni_links'])) {
                    $netnodes[$netnodename]['uni_links'] = array_merge($netnodes[$netnodename]['uni_links'], $ports['uni_links']);
                }
            }
        }

        $netnodes[$netnodename]['netdevices'][] = $netdevice['id'];

        if (!isset($netnodes[$netnodename]['longitutde'])
            && !isset($netnodes[$netnodename]['latitude'])
            && !empty($netdevice['longitude'])
            && !empty($netdevice['latitude'])) {
            $netnodes[$netnodename]['longitudes'][] = $netdevice['longitude'];
            $netnodes[$netnodename]['latitudes'][] = $netdevice['latitude'];
        }

        $netdevs[$netdevid] = $netnodename;

        $child_netdevices = $DB->GetAll(
            "SELECT nl.id AS netlinkid, (CASE nl.src WHEN ? THEN nl.dst ELSE nl.src END) AS netdevid
            FROM netlinks nl
            JOIN netdevices ndsrc ON ndsrc.id = nl.src
            JOIN netdevices nddst ON nddst.id = nl.dst
            WHERE (nl.src = ?" . ($customer_netdevices ? 'AND nddst.ownerid IS NULL' : '') . ")
                OR (nl.dst = ?" . ($customer_netdevices ? 'AND ndsrc.ownerid IS NULL' : '') . ")",
            array($netdevid, $netdevid, $netdevid)
        );

        if (empty($child_netdevices)) {
            $child_netdevices = array();
        }

        foreach ($child_netdevices as $child_netdevice) {
            if (isset($processed_child_netlinks[$child_netdevice['netlinkid']])) {
                continue;
            }
            $processed_child_netlinks[$child_netdevice['netlinkid']] = true;
            $netnodes[$netnodename]['child_netdevices'][] = $child_netdevice['netdevid'];
        }
    }
}

foreach ($netnodes as $netnodename => &$netnode) {
    $netnode['child_netdevices'] = array_diff($netnode['child_netdevices'], $netnode['netdevices']);

    foreach ($netnode['child_netdevices'] as $netdevid) {
        $netnode['child_netnodenames'][] = $netdevs[$netdevid];
    }
    $netnode['child_netnodenames'] = array_unique($netnode['child_netnodenames']);
}
unset($netnode);

$foreignerid = 1;
$sforeigners = '';
foreach ($foreigners as $name => $foreigner) {
    $po_buffer = 'po01_id_podmiotu_obcego,po02_nip_pl,po03_nip_nie_pl' . EOL;
    $data = array(
        // alternatively $foreingerid can be used
        'po01_id_podmiotu_obcego' => 'PO-' . $foreigner,
        'po02_nip_pil' => '',
        'po03_nip_nie_pl' => '',
    );
    $po_buffer .= to_csv($data) . EOL;
    $foreignerid++;
}

$netintid = 1;
$netbuildingid = 1;
$netrangeid = 1;
$radiosectorid = 1;
$snetnodes = '';
$sforeignernetnodes = '';
$snetconnections = '';
$snetinterfaces = '';
$sradiosectors = '';
$snetranges = '';
$snetbuildings = '';
$teryt_netranges = array();

if ($netnodes) {
    foreach ($netnodes as $netnodename => &$netnode) {
        // if teryt location is not set then try to get location address from network node name
        if (!isset($netnode['area_woj'])) {
            $address = mb_split("[[:blank:]]+", $netnodename);
            $street = mb_ereg_replace("[[:blank:]][[:alnum:]]+$", "", $netnodename);
        }

        // count gps coordinates basing on average longitude and latitude of all network devices located in this network node
        if (isset($netnode['longitudes']) && count($netnode['longitudes'])) {
            $netnode['longitude'] = $netnode['latitude'] = 0.0;
            foreach ($netnode['longitudes'] as $longitude) {
                $netnode['longitude'] += floatval($longitude);
            }
            foreach ($netnode['latitudes'] as $latitude) {
                $netnode['latitude'] += floatval($latitude);
            }
            $netnode['longitude'] = to_wgs84($netnode['longitude'] / count($netnode['longitudes']));
            $netnode['latitude'] = to_wgs84($netnode['latitude'] / count($netnode['latitudes']));
        }

        if (empty($netnode['location_street_name'])) {
            // no street specified for address
            if (strlen(trim($netnode['address_budynek']))
                && (!isset($teryt_cities[$netnode['location_city']]) || empty($teryt_cities[$netnode['location_city']]['with_streets']))) {
                $netnode['address_ulica'] = "BRAK ULICY";
                $netnode['address_symul'] = "99999";
            } else {
                $netnode['address_ulica'] = '';
                $netnode['address_symul'] = '';
                $netnode['address_budynek'] = '';
            }
        } elseif (!isset($netnode['address_symul'])) {
            // specified street is from outside teryt
            $netnode['address_ulica'] = "ul. SPOZA ZAKRESU";
            $netnode['address_symul'] = "99998";
        }

        if (is_array($netnode['invproject'])) {
            $netnodes[$netnodename]['invproject'] = $netnode['invproject'] =
            count($netnode['invproject']) == 1 ? $netnode['invproject'][0] : '';
        }

        if ($netnode['ownership'] < 2) {
            $data = array(
                'ww_id' => $netnode['id'],
                'ww_ownership' => $NETELEMENTOWNERSHIPS[$netnode['ownership']],
                'ww_coowner' => $netnode['coowner'],
                'ww_coloc' => '',
                'ww_state' => isset($netnode['area_woj']) ? $netnode['area_woj']
                    : "LMS netdevinfo ID's:" . implode(' ', $netnode['netdevices']) . "," . implode(',', array_fill(0, 9, '')),
                'ww_district' => isset($netnode['area_pow']) ? $netnode['area_pow'] : '',
                'ww_borough' => isset($netnode['area_gmi']) ? $netnode['area_gmi'] : '',
                'ww_terc' => isset($netnode['area_terc']) ? $netnode['area_terc'] : '',
                'ww_city' => isset($netnode['area_city']) ? $netnode['area_city'] : $netnode['location_city_name'],
                'ww_simc' => isset($netnode['area_simc']) ? $netnode['area_simc'] : '',
                'ww_street' => isset($netnode['address_ulica']) ? ((!empty($netnode['address_cecha']) && $netnode['address_cecha'] != 'inne'
                    ? $netnode['address_cecha'] . ' ' : '') . $netnode['address_ulica']) : $netnode['location_street_name'],
                'ww_ulic' => isset($netnode['address_symul']) ? $netnode['address_symul'] : '',
                'ww_house' => str_replace(' ', '', $netnode['address_budynek']),
                'ww_zip' => $netnode['location_zip'],
                'ww_latitude' =>  isset($netnode['latitude']) ? $netnode['latitude'] : '',
                'ww_longitude' => isset($netnode['longitude']) ? $netnode['longitude'] : '',
                'ww_objtype' => $NETELEMENTTYPES[$netnode['type']],
                'ww_uip' => $netnode['uip'] ? 'Tak' : 'Nie',
                'ww_miar' => $netnode['miar'] ? 'Tak' : 'Nie',
                'ww_eu' => $netnode['invproject'] ? 'Tak' : 'Nie',
                'ww_invproject' => $netnode['invproject'],
                'ww_invstatus' => $netnode['invproject'] ? $NETELEMENTSTATUSES[$netnode['status']] : '',
            );

            $buffer .= 'WW,' . to_csv($data) . EOL;
        } else {
            $data = array(
                'wo_id' => $netnode['id'],
                'wo_agreement' => 'Umowa o dostęp do sieci telekomunikacyjnej',
                'wo_coowner' => $netnode['coowner'],
                'wo_state' => isset($netnode['area_woj']) ? $netnode['area_woj']
                    : "LMS netdevinfo ID's:" . implode(' ', $netnode['netdevices']) . "," . implode(',', array_fill(0, 9, '')),
                'wo_district' => isset($netnode['area_pow']) ? $netnode['area_pow'] : '',
                'wo_borough' => isset($netnode['area_gmi']) ? $netnode['area_gmi'] : '',
                'wo_terc' => isset($netnode['area_terc']) ? $netnode['area_terc'] : '',
                'wo_city' => isset($netnode['area_city']) ? $netnode['area_city'] : $netnode['location_city_name'],
                'wo_simc' => isset($netnode['area_simc']) ? $netnode['area_simc'] : '',
                'wo_street' => isset($netnode['address_ulica']) ? ((!empty($netnode['address_cecha']) && $netnode['address_cecha'] != 'inne'
                    ? $netnode['address_cecha'] . ' ' : '') . $netnode['address_ulica']) : $netnode['location_street_name'],
                'wo_ulic' => isset($netnode['address_symul']) ? $netnode['address_symul'] : '',
                'wo_house' => str_replace(' ', '', $netnode['address_budynek']),
                'wo_zip' => $netnode['location_zip'],
                'wo_latitude' =>  isset($netnode['latitude']) ? $netnode['latitude'] : '',
                'wo_longitude' => isset($netnode['longitude']) ? $netnode['longitude'] : '',
                'wo_objtype' => $NETELEMENTTYPES[$netnode['type']],
                'wo_invproject' => $netnode['invproject'],
            );

            $buffer .= 'WO,' . to_csv($data) . EOL;
        }

        if ($netnode['ownership'] == 2) {
            continue;
        }

        $netnode['ranges'] = array();

        // save info about network ranges
        $ranges = $DB->GetAll("SELECT n.linktype, n.linktechnology,
            a.city_id AS location_city, a.city AS location_city_name,
            a.street_id AS location_street, a.street AS location_street_name,
            a.house AS location_house, a.zip AS location_zip, 0 AS from_uni_link
        FROM nodes n
        LEFT JOIN addresses a ON n.address_id = a.id
        WHERE n.ownerid IS NOT NULL AND a.city_id IS NOT NULL AND n.netdev IN (" . implode(',', $netnode['netdevices']) . ")
        GROUP BY n.linktype, n.linktechnology, a.street, a.street_id, a.city_id, a.city, a.house, a.zip");

        if (empty($ranges)) {
            $ranges = array();
        }

        if ($customer_netdevices) {
            // collect ranges from customer uni links
            $uni_ranges = array();
            if (isset($netnode['uni_links']) && !empty($netnode['uni_links'])) {
                foreach ($netnode['uni_links'] as $uni_link_id) {
                    $uni_link = &$uni_links[$uni_link_id];
                    // $uni_link['nodes']
                    $uni_ranges[] = array(
                        'linktype' => $uni_link['type'],
                        'linktechnology' => $uni_link['technology'],
                        'location_city' => $uni_link['location_city'],
                        'location_city_name' => $uni_link['location_city_name'],
                        'location_street' => $uni_link['location_street'],
                        'location_street_name' => $uni_link['location_street_name'],
                        'location_house' => $uni_link['location_house'],
                        'location_zip' => $uni_link['location_zip'],
                        'from_uni_link' => $uni_link_id,
                    );
                }
            }
            $ranges = array_merge($ranges, $uni_ranges);
        }

        if (empty($ranges)) {
            continue;
        }

        // this variable will change its value to true if network node will have range with the same location (city, street, house)
        $range_netbuilding = false;

        $range_maxdownstream = 0;
        foreach ($ranges as $range) {
            $teryt = array();

            // get teryt info for group of computers connected to network node
            if (isset($teryt_cities[$range['location_city']])) {
                $teryt = $teryt_cities[$range['location_city']];

                $teryt['location_zip'] = empty($teryt['location_zip']) ? '' : $teryt['location_zip'];

                if (!empty($range['location_street']) && isset($teryt_streets[$range['location_street']])) {
                    $teryt_street = $teryt_streets[$range['location_street']];

                    $teryt['address_cecha'] = $teryt_street['address_cecha'];
                    $teryt['address_ulica'] = $teryt_street['address_ulica'];
                    $teryt['address_symul'] = $teryt_street['address_symul'];
                    $teryt['location_zip'] = empty($teryt_street['location_zip']) ? '' : $teryt_street['location_zip'];
                }
            }

            $teryt['address_budynek'] = $range['location_house'];
            $teryt['location_zip'] = empty($range['location_zip']) ? $teryt['location_zip'] : $range['location_zip'];

            if (empty($range['location_street_name'])) {
                if (!isset($teryt_cities[$range['location_city']]) || empty($teryt['with_streets'])) {
                    $teryt['address_ulica'] = "BRAK ULICY";
                    $teryt['address_symul'] = "99999";
                } else {
                    $teryt['address_ulica'] = '';
                    $teryt['address_symul'] = '';
                    $teryt['address_budynek'] = '';
                }
            } else {
                if (!isset($teryt['address_symul'])) {
                    if (isset($teryt_cities[$range['location_city']]) && !empty($teryt['with_streets'])) {
                        $teryt['address_ulica'] = "ul. SPOZA ZAKRESU";
                        $teryt['address_symul'] = "99998";
                    } else {
                        $teryt['address_ulica'] = "BRAK ULICY";
                        $teryt['address_symul'] = "99999";
                    }
                }
            }

            if ($range['linktechnology']) {
                if ($range['linktechnology'] < 50 || $range['linktechnology'] >= 100) {
                    $technology = $linktypes[$range['linktype']]['technologia'];
                } else {
                    $technology = 'kablowe współosiowe miedziane';
                }
                $linktechnology = $LINKTECHNOLOGIES[$range['linktype']][$range['linktechnology']];
            } else {
                $technology = $linktypes[$range['linktype']]['technologia'];
                $linktechnology = $linktypes[$range['linktype']]['technologia_dostepu'];
            }

            $nodes = array();
            $uni_nodes = array();
            if (!$customer_netdevices || empty($range['from_uni_link'])) {
                // get info about computers connected to network node
                $nodes = $DB->GetAll(
                    "SELECT na.nodeid, c.type, n.invprojectid, nd.id AS netdevid, nd.status,"
                    . $DB->GroupConcat("DISTINCT (CASE t.type WHEN " . SERVICE_INTERNET . " THEN 'INT'
                    WHEN " . SERVICE_PHONE . " THEN 'TEL'
                    WHEN " . SERVICE_TV . " THEN 'TV'
                    ELSE 'INT' END)") . " AS servicetypes, SUM(t.downceil) AS downstream, SUM(t.upceil) AS upstream
                FROM nodeassignments na
                JOIN nodes n             ON n.id = na.nodeid
                LEFT JOIN addresses addr ON addr.id = n.address_id
                JOIN assignments a       ON a.id = na.assignmentid
                JOIN tariffs t           ON t.id = a.tariffid
                JOIN customers c ON c.id = n.ownerid
                LEFT JOIN (SELECT aa.customerid AS cid, COUNT(id) AS total FROM assignments aa
                    WHERE aa.tariffid IS NULL AND aa.liabilityid IS NULL
                        AND aa.datefrom < ?NOW?
                        AND (aa.dateto > ?NOW? OR aa.dateto = 0) GROUP BY aa.customerid)
                    AS allsuspended ON allsuspended.cid = c.id
                JOIN netdevices nd ON nd.id = n.netdev
                WHERE n.ownerid IS NOT NULL AND n.netdev IS NOT NULL AND n.linktype = ? AND n.linktechnology = ? AND addr.city_id = ?
                    AND (addr.street_id = ? OR addr.street_id IS NULL) AND addr.house = ?
                    AND a.suspended = 0 AND a.period IN (" . implode(',', array(YEARLY, HALFYEARLY, QUARTERLY, MONTHLY, DISPOSABLE)) . ")
                    AND (a.datefrom = 0 OR a.datefrom < ?NOW?) AND (a.dateto = 0 OR a.dateto > ?NOW?)
                    AND allsuspended.total IS NULL
                GROUP BY na.nodeid, c.type, n.invprojectid, nd.id, nd.status",
                    array(
                        $range['linktype'],
                        $range['linktechnology'],
                        $range['location_city'],
                        $range['location_street'],
                        $range['location_house']
                    )
                );
                if (empty($nodes)) {
                    $nodes = array();
                }
            } elseif ($customer_netdevices) {
                // get info about computers or network devices connected to network node though customer network device
                $uni_link_id = $range['from_uni_link'];
                $uni_link = &$uni_links[$uni_link_id];
                $uni_nodes = $DB->GetAll(
                    "SELECT na.nodeid, c.type, "
                    . (empty($uni_link['invprojectid']) ? 'null' : $uni_link['invprojectid']) . " AS invprojectid, "
                    . $uni_link['operator_netdevid'] . " AS netdevid, "
                    . $uni_link['operator_netdevstatus'] . " AS status, "
                    . $DB->GroupConcat("DISTINCT (CASE t.type WHEN " . SERVICE_INTERNET . " THEN 'INT'
                    WHEN " . SERVICE_PHONE . " THEN 'TEL'
                    WHEN " . SERVICE_TV . " THEN 'TV'
                    ELSE 'INT' END)") . " AS servicetypes, SUM(t.downceil) AS downstream, SUM(t.upceil) AS upstream
                FROM nodeassignments na
                JOIN nodes n             ON n.id = na.nodeid
                JOIN assignments a       ON a.id = na.assignmentid
                JOIN tariffs t           ON t.id = a.tariffid
                JOIN customers c ON c.id = n.ownerid
                LEFT JOIN (SELECT aa.customerid AS cid, COUNT(id) AS total FROM assignments aa
                    WHERE aa.tariffid IS NULL AND aa.liabilityid IS NULL
                        AND aa.datefrom < ?NOW?
                        AND (aa.dateto > ?NOW? OR aa.dateto = 0) GROUP BY aa.customerid)
                    AS allsuspended ON allsuspended.cid = c.id
                JOIN netdevices nd ON nd.id = n.netdev
                WHERE n.id IN (" . implode(',', $uni_link['nodes']) . ")
                    AND a.suspended = 0 AND a.period IN (" . implode(',', array(YEARLY, HALFYEARLY, QUARTERLY, MONTHLY, DISPOSABLE)) . ")
                    AND a.datefrom < ?NOW? AND (a.dateto = 0 OR a.dateto > ?NOW?)
                    AND allsuspended.total IS NULL
                GROUP BY na.nodeid, c.type",
                    array()
                );
                if (empty($uni_nodes)) {
                    $uni_nodes = array();
                }
            }
            $nodes = array_merge($nodes, $uni_nodes);

            if (empty($nodes)) {
                continue;
            }

            $netnode['tech'][$range['linktype']] = true;

            // check if this is range with the same location as owning network node
            if ($range['location_city'] == $netnode['location_city']
                && $range['location_street'] == $netnode['location_street']
                && $range['location_house'] == $netnode['location_house']) {
                $range_netbuilding = true;
            }

            $netrange = array(
                'longitude' => '',
                'latitude' => '',
                'count' => 0,
            );

            $prjnodes = array();
            foreach ($nodes as $node) {
                $status = $node['status'];
                if (!strlen($node['invprojectid'])) {
                    $prj = '';
                } elseif ($node['invprojectid'] > 1) {
                    $prj = $projects[$node['invprojectid']]['name'];
                } else {
                    $prj = $netdevices[$node['netdevid']]['invproject'];
                }
                if (!isset($prjnodes[$prj][$status])) {
                    $prjnodes[$prj][$status] = array();
                }
                $prjnodes[$prj][$status][] = $node;

                if (isset($nodecoords[$node['nodeid']])) {
                    if (!strlen($netrange['longitude'])) {
                        $netrange['longitude'] = 0;
                    }
                    if (!strlen($netrange['latitude'])) {
                        $netrange['latitude'] = 0;
                    }
                    $netrange['longitude'] += $nodecoords[$node['nodeid']]['longitude'];
                    $netrange['latitude'] += $nodecoords[$node['nodeid']]['latitude'];
                    $netrange['count']++;
                }
            }
            // calculate network range gps coordinates as all nodes gps coordinates mean value
            if ($netrange['count']) {
                $netrange['longitude'] /= $netrange['count'];
                $netrange['latitude'] /= $netrange['count'];
            }

            foreach ($prjnodes as $prj => $statuses) {
                foreach ($statuses as $status => $nodes) {
                    // count all kinds of link speeds for computers connected to this network node
                    $personalnodes = array();
                    $commercialnodes = array();
                    $maxdownstream = 0;
                    foreach ($nodes as $node) {
                        if ($node['downstream'] <= 1000) {
                            $set = 1;
                        } elseif ($node['downstream'] < 2000) {
                            $set = 2;
                        } elseif ($node['downstream'] == 2000) {
                            $set = 3;
                        } elseif ($node['downstream'] <= 10000) {
                            $set = 4;
                        } elseif ($node['downstream'] <= 20000) {
                            $set = 5;
                        } elseif ($node['downstream'] < 30000) {
                            $set = 6;
                        } elseif ($node['downstream'] == 30000) {
                            $set = 7;
                        } elseif ($node['downstream'] < 100000) {
                            $set = 8;
                        } elseif ($node['downstream'] == 100000) {
                            $set = 9;
                        } else {
                            $set = 10;
                        }
                        if ($node['type'] == 0) {
                            if (!isset($personalnodes[$node['servicetypes']][$set])) {
                                $personalnodes[$node['servicetypes']][$set] = 0;
                            }
                            $personalnodes[$node['servicetypes']][$set]++;
                        } else {
                            if (!isset($commercialnodes[$node['servicetypes']][$set])) {
                                $commercialnodes[$node['servicetypes']][$set] = 0;
                            }
                            $commercialnodes[$node['servicetypes']][$set]++;
                        }
                        if ($node['downstream'] > $maxdownstream) {
                            $maxdownstream = $node['downstream'];
                        }
                    }
                    // save info about computers connected to this network node
                    foreach ($personalnodes as $servicetype => $servicenodes) {
                        $services = array();
                        foreach (array_fill(0, 11, '0') as $key => $value) {
                            $services[] = isset($servicenodes[$key]) ? $servicenodes[$key] : $value;
                        }
                        $personalnodes[$servicetype] = $services;
                    }
                    foreach ($commercialnodes as $servicetype => $servicenodes) {
                        $services = array();
                        foreach (array_fill(0, 11, '0') as $key => $value) {
                            $services[] = isset($servicenodes[$key]) ? $servicenodes[$key] : $value;
                        }
                        $commercialnodes[$servicetype] = $services;
                    }

                    // mark network range as handled - later used in potential range determination
                    $teryt_netranges[sprintf(
                        "%s_%07d_%05d_%s",
                        $teryt['area_terc'],
                        $teryt['area_simc'],
                        $teryt['address_symul'],
                        $teryt['address_budynek']
                    )] = true;

                    $data = array(
                        'zas_id' => $netbuildingid,
                        'zas_ownership' => 'Własna',
                        'zas_leasetype' => '',
                        'zas_foreignerid' => '',
                        'zas_nodeid' => $netnode['id'],
                        'zas_state' => isset($teryt['area_woj']) ? $teryt['area_woj'] : '',
                        'zas_district' => isset($teryt['area_pow']) ? $teryt['area_pow'] : '',
                        'zas_borough' => isset($teryt['area_gmi']) ? $teryt['area_gmi'] : '',
                        'zas_terc' => isset($teryt['area_terc']) ? $teryt['area_terc'] : '',
                        'zas_city' => isset($teryt['area_city']) ? $teryt['area_city'] : $range['location_city_name'],
                        'zas_simc' => isset($teryt['area_simc']) ? $teryt['area_simc'] : '',
                        'zas_street' => isset($teryt['address_ulica']) ? ((!empty($teryt['address_cecha']) && $teryt['address_cecha'] != 'inne'
                            ? $teryt['address_cecha'] . ' ' : '') . $teryt['address_ulica']) : $range['location_street_name'],
                        'zas_ulic' => $teryt['address_symul'],
                        'zas_house' => str_replace(' ', '', $teryt['address_budynek']),
                        'zas_zip' => $teryt['location_zip'],
                        'zas_latitude' => (!isset($netrange['latitude']) || !strlen($netrange['latitude']))
                            && (!isset($netnode['latitude']) || !strlen($netnode['latitude']))
                            ? '' : str_replace(',', '.', sprintf('%.6f', (!isset($netrange['latitude']) || !strlen($netrange['latitude'])) ? $netnode['latitude'] : $netrange['latitude'])),
                        'zas_longitude' => !strlen($netrange['longitude']) && !strlen($netnode['longitude'])
                            ? '' : str_replace(',', '.', sprintf('%.6f', !strlen($netrange['longitude']) ? $netnode['longitude'] : $netrange['longitude'])),
                        'zas_tech' => $technology,
                        'zas_ltech' => $linktechnology,
                    );

                    $allservices = array();

                    foreach (array_unique(array_merge(array_keys($personalnodes), array_keys($commercialnodes))) as $servicetype) {
                        $services = array_flip(explode(',', $servicetype));
                        $ukeservices = array();
                        foreach (array('TEL', 'INT', 'TV') as $service) {
                            if (isset($services[$service])) {
                                $ukeservices[] = $service;
                                $allservices[] = $service;
                            }
                        }
                        $us_data = array(
                            'us_id' => $netrangeid,
                            'us_netbuildingid' => $netbuildingid,
                            'us_phonepots' => array_search('TEL', $ukeservices) !== false
                                && $range['linktechnology'] == 12 ? 'Tak' : 'Nie',
                            'us_phonevoip' => array_search('TEL', $ukeservices) !== false && $range['linktechnology'] != 12
                                && ($range['linktechnology'] < 105 || $range['linktechnology'] >= 200) ? 'Tak' : 'Nie',
                            'us_phonemobile' => array_search('TEL', $ukeservices) !== false
                                && $range['linktechnology'] >= 105 && $range['linktechnology'] < 200 ? 'Tak' : 'Nie',
                            'us_internetstationary' => array_search('INT', $ukeservices) !== false
                                && ($range['linktechnology'] < 105 || $range['linktechnology'] >= 200) ? 'Tak' : 'Nie',
                            'us_internetmobile' => array_search('INT', $ukeservices) !== false
                                && $range['linktechnology'] >= 105 && $range['linktechnology'] < 200 ? 'Tak' : 'Nie',
                            'us_tv' => array_search('TV', $ukeservices) !== false ? 'Tak' : 'Nie',
                            'us_other' => '',
                        );

                        if (isset($personalnodes[$servicetype])) {
                            if (array_search('INT', $ukeservices) !== false) {
                                $personalservices = $personalnodes[$servicetype];
                            } else {
                                $count = 0;
                                foreach ($personalnodes[$servicetype] as $nodes) {
                                    $count += $nodes;
                                }
                                $personalservices = array_fill(0, 10, '0');
                                array_unshift($personalservices, $count);
                            }
                        } else {
                            $personalservices = array_fill(0, 11, '0');
                        }

                        if (isset($commercialnodes[$servicetype])) {
                            if (array_search('INT', $ukeservices) !== false) {
                                $commercialservices = $commercialnodes[$servicetype];
                            } else {
                                $count = 0;
                                foreach ($commercialnodes[$servicetype] as $nodes) {
                                    $count += $nodes;
                                }
                                $commercialservices = array_fill(0, 10, '0');
                                array_unshift($commercialservices, $count);
                            }
                        } else {
                            $commercialservices = array_fill(0, 11, '0');
                        }

                        foreach ($personalservices as $idx => $service) {
                            $us_data['us_personal' . $idx] = $service;
                        }
                        foreach ($commercialservices as $idx => $service) {
                            $us_data['us_commercial' . $idx] = $service;
                        }

                        $us_data = array_merge($us_data, array(
                            'us_invproject' => strlen($prj) ? $prj : '',
                            'us_invstatus' => strlen($prj) ? $NETELEMENTSTATUSES[$status] : '',
                        ));

                        $buffer .= 'U,' . to_csv($us_data) . EOL;
                        $netrangeid++;
                    }

                    $allservices = array_unique($allservices);
                    $maxdownstream = parseNetworkSpeed($maxdownstream);

                    if (array_search('TV', $allservices)) {
                        $netnode['tv_avible'] = true;
                    }

                    if (array_search('TEL', $allservices)) {
                        $netnode['tel_avible'] = true;
                    }

                    $data = array_merge($data, array(
                        'zas_phonepots' => array_search('TEL', $allservices) !== false && $range['linktechnology'] == 12 ? 'Tak' : 'Nie',
                        'zas_phonevoip' => array_search('TEL', $allservices) !== false && $range['linktechnology'] != 12 && ($range['linktechnology'] < 105 || $range['linktechnology'] >= 200) ? 'Tak' : 'Nie',
                        'zas_phonemobile' => array_search('TEL', $allservices) !== false && $range['linktechnology'] >= 105 && $range['linktechnology'] < 200 ? 'Tak' : 'Nie',
                        'zas_internetstationary' => array_search('INT', $allservices) !== false && ($range['linktechnology'] < 105 || $range['linktechnology'] >= 200) ? 'Tak' : 'Nie',
                        'zas_internetmobile' => array_search('INT', $allservices) !== false && $range['linktechnology'] >= 105 && $range['linktechnology'] < 200 ? 'Tak' : 'Nie',
                        'zas_tv' => array_search('TV', $allservices) !== false ? 'Tak' : 'Nie',
                        'zas_other' => '',
                        'zas_stationarymaxspeed' => array_search('INT', $allservices) !== false && ($range['linktechnology'] < 105 || $range['linktechnology'] >= 200) ? $maxdownstream : '0',
                        'zas_mobilemaxspeed' => array_search('INT', $allservices) !== false && $range['linktechnology'] >= 105 && $range['linktechnology'] < 200 ? $maxdownstream : '0',
                        'zas_invproject' => strlen($prj) ? $prj : '',
                        'zas_invstatus' => strlen($prj) ? $NETELEMENTSTATUSES[$status] : '',
                    ));

                    $netnode['ranges'][] = $data;
                    $netnode['technologies'][] = $range['linktechnology'];

                    $buffer .= 'ZS,' . to_csv($data) . EOL;
                    $netbuildingid++;
                }
            }
        }
    }
}
unset($netnode);

function analyze_network_subtree($netnode_name, $current_netnode_name, &$netnodes, &$netdevices)
{
    if (isset($netnodes[$netnode_name]['processed'])) {
        $netnode_stack = array();
        $back_trace = debug_backtrace();
        foreach ($back_trace as $idx => &$bt) {
            if ($bt['function'] != 'analyze_network_subtree') {
                continue;
            }
            $netnode_stack[] = array(
                'name' => $bt['args'][0],
                'location' => $netnodes[$bt['args'][0]]['location_city_name'] . (empty($netnodes[$bt['args'][0]]['location_street_name']) ? '' : ', ' . $netnodes[$bt['args'][0]]['location_street_name']) . ' ' . $netnodes[$bt['args'][0]]['location_house']
            );
        }
        unset($bt);
        echo trans('Detected network loop on network node <strong>\'$a\'</strong>!', $netnode_name) . '<br>';
        echo trans('Network nodes which belong to this loop:') . '<br><br>';
        foreach ($netnode_stack as $ns) {
            echo trans('<!uke-pit>name: <strong>$a</strong>', $ns['name']) . '<br>';
            echo '&nbsp;&nbsp;&nbsp;&nbsp;' . trans('<!uke-pit>location: $a', $ns['location']) . '<br>';
            echo '<br>';
        }
        die;

        return;
    }

    $netnodes[$netnode_name]['processed'] = true;

    if ($netnodes[$netnode_name]['mode'] == 2) {
        $current_netnode_name = $netnode_name;
    } else {
        $netnodes[$netnode_name]['parent_netnodename'] = $current_netnode_name;
    }

    foreach ($netnodes[$netnode_name]['child_netnodenames'] as $child_netnode_name) {
        analyze_network_subtree($child_netnode_name, $current_netnode_name, $netnodes, $netdevices);
    }
}

analyze_network_subtree($root_netnode_name, $root_netnode_name, $netnodes, $netdevices);

foreach ($netnodes as $netnodename => $netnode) {
    $netnode['technologies'] = array_unique($netnode['technologies']);

    echo $netnodename . ':<br>';
    echo '&nbsp;&nbsp;&nbsp;&nbsp;lokalizacja: ' . $netnode['location_city_name'] . (empty($netnode['location_street_name']) ? '' : ', ' . $netnode['location_street_name']) . ' ' . $netnode['location_house'] . '<br>';
    echo '&nbsp;&nbsp;&nbsp;&nbsp;typ: ' . ($netnode['mode'] == 1 ? 'punkt elastyczności' : 'węzeł') . '<br>';
    if ($netnode['mode'] == 1) {
        echo '&nbsp;&nbsp;&nbsp;&nbsp;zasilany z węzła: ' . (isset($netnode['parent_netnodename']) ? $netnode['parent_netnodename'] : '-') . '<br>';
    }
    echo '&nbsp;&nbsp;&nbsp;&nbsp;technologie dostępu: ' . (empty($netnode['technologies']) ? '(brak)' : implode(',', $netnode['technologies'])) . '<br>';
/*
    echo '&nbsp;&nbsp;&nbsp;&nbsp;zasięgi: ';
    if (empty($netnode['ranges'])) {
        echo '-';
    } else {
        echo nl2br(print_r($netnode['ranges'], true));
    }
    echo '<br>';
*/
    echo '<br>';
}
die;

unset($teryt_cities);
unset($teryt_streets);

//prepare info about network links (only between different network nodes)
$netconnectionid = 1;
$processed_netlinks = array();
$netlinks = array();
if ($netdevices) {
    foreach ($netdevices as $netdevice) {
        $ndnetlinks = $DB->GetAll(
            "SELECT src, dst, nl.type, speed, nl.technology,
            (CASE src WHEN ? THEN (CASE WHEN srcrs.license IS NULL THEN dstrs.license ELSE srcrs.license END)
                ELSE (CASE WHEN dstrs.license IS NULL THEN srcrs.license ELSE dstrs.license END) END) AS license,
            (CASE src WHEN ? THEN (CASE WHEN srcrs.frequency IS NULL THEN dstrs.frequency ELSE srcrs.frequency END)
                ELSE (CASE WHEN dstrs.frequency IS NULL THEN srcrs.frequency ELSE dstrs.frequency END) END) AS frequency
            FROM netlinks nl
            JOIN netdevices ndsrc ON ndsrc.id = nl.src
            JOIN netdevices nddst ON nddst.id = nl.dst
            LEFT JOIN netradiosectors srcrs ON srcrs.id = nl.srcradiosector
            LEFT JOIN netradiosectors dstrs ON dstrs.id = nl.dstradiosector
            WHERE (src = ?" . ($customer_netdevices ? 'AND nddst.ownerid IS NULL' : '') . ")
                OR (dst = ?" . ($customer_netdevices ? 'AND ndsrc.ownerid IS NULL' : '') . ")",
            array($netdevice['id'], $netdevice['id'], $netdevice['id'], $netdevice['id'])
        );
        if ($ndnetlinks) {
            foreach ($ndnetlinks as $netlink) {
                $netdevnetnode = $netdevs[$netdevice['id']];
                $srcnetnode = $netdevs[$netlink['src']];
                $dstnetnode = $netdevs[$netlink['dst']];
                $netnodeids = array($netnodes[$srcnetnode]['id'], $netnodes[$dstnetnode]['id']);

                sort($netnodeids);

                $netnodelinkid = implode('_', $netnodeids);

                if (!isset($processed_netlinks[$netnodelinkid])) {
                    $linkspeed = $netlink['speed'];
                    $speed = floor($linkspeed / 1000);
                    $netintid = '';

                    if ($netlink['src'] == $netdevice['id']) {
                        if ($netdevnetnode != $dstnetnode) {
                            if ($netdevices[$netlink['src']]['invproject'] == $netdevices[$netlink['dst']]['invproject']
                                || strlen($netdevices[$netlink['src']]['invproject']) || strlen($netdevices[$netlink['dst']]['invproject'])) {
                                $invproject = $netdevices[$netlink['src']]['invproject'];
                            } else {
                                $invproject = '';
                            }
                            if ($netdevices[$netlink['src']]['status'] == $netdevices[$netlink['dst']]['status']) {
                                $status = $netdevices[$netlink['src']]['status'];
                            } elseif ($netdevices[$netlink['src']]['status'] == 2 || $netdevices[$netlink['dst']]['status'] == 2) {
                                $status = 2;
                            } elseif ($netdevices[$netlink['src']]['status'] == 1 || $netdevices[$netlink['dst']]['status'] == 1) {
                                $status = 1;
                            }

                            $processed_netlinks[$netnodelinkid] = true;

                            $foreign = $netnodes[$netdevnetnode]['ownership'] == 2 && $netnodes[$dstnetnode]['ownership'] < 2
                                || $netnodes[$netdevnetnode]['ownership'] < 2 && $netnodes[$dstnetnode]['ownership'] == 2;

                            $netlinks[] = array(
                                'type' => $netlink['type'],
                                'speed' => $speed,
                                'technology' => $netlink['technology'],
                                'src' => $netdevnetnode,
                                'dst' => $dstnetnode,
                                'license' => $netlink['license'],
                                'frequency' => $netlink['frequency'],
                                'invproject' => $invproject,
                                'status' => $status,
                                'foreign' => $foreign,
                            );
                        }
                    } else if ($netdevnetnode != $srcnetnode) {
                        if ($netdevices[$netlink['src']]['invproject'] == $netdevices[$netlink['dst']]['invproject']
                        || strlen($netdevices[$netlink['src']]['invproject']) || strlen($netdevices[$netlink['dst']]['invproject'])) {
                            $invproject = $netdevices[$netlink['src']]['invproject'];
                        } else {
                            $invproject = '';
                        }
                        if ($netdevices[$netlink['src']]['status'] == $netdevices[$netlink['dst']]['status']) {
                            $status = $netdevices[$netlink['src']]['status'];
                        } elseif ($netdevices[$netlink['src']]['status'] == 2 || $netdevices[$netlink['dst']]['status'] == 2) {
                            $status = 2;
                        } elseif ($netdevices[$netlink['src']]['status'] == 1 || $netdevices[$netlink['dst']]['status'] == 1) {
                            $status = 1;
                        }

                        $processed_netlinks[$netnodelinkid] = true;

                        $foreign = $netnodes[$netdevnetnode]['ownership'] == 2 && $netnodes[$dstnetnode]['ownership'] < 2
                            || $netnodes[$netdevnetnode]['ownership'] < 2 && $netnodes[$dstnetnode]['ownership'] == 2;

                        $netlinks[] = array(
                            'type' => $netlink['type'],
                            'speed' => $speed,
                            'src' => $netdevnetnode,
                            'dst' => $srcnetnode,
                            'license' => $netlink['license'],
                            'frequency' => $netlink['frequency'],
                            'invproject' => $invproject,
                            'status' => $status,
                            'foreign' => $foreign,
                        );
                    }
                }
            }
        }
    }
}

/*
// save info about network lines
$netlineid = 1;
$snetcablelines = '';
$snetradiolines = '';
if ($netlinks) {
    foreach ($netlinks as $netlink) {
        if ($netnodes[$netlink['src']]['id'] != $netnodes[$netlink['dst']]['id']) {
            if ($netlink['type'] == 1) {
                $linktechnology = empty($netlink['technology']) ? $netlink['technology'] : 0;
                if (!$linktechnology) {
                    $linktechnology = 101;
                }
                switch ($linktechnology) {
                    case 100:
                    case 101:
                        $linktransmission = 'WiFi';
                        break;
                    case 102:
                    case 103:
                    case 104:
                        $linktransmission = $LINKTECHNOLOGIES[1][$linktechnology];
                        break;
                    default:
                        $linktransmission = '';
                }
                $linkfrequency = $netlink['frequency'];
                if (empty($linkfrequency)) {
                    $linkfrequency = '5.5';
                } else {
                    $linkfrequency = str_replace(',', '.', (float) $linkfrequency);
                }
                $data = array(
                    'rl_id' => $netlineid,
                    'rl_anodeid' => $netnodes[$netlink['src']]['id'],
                    'rl_bnodeid' => $netnodes[$netlink['dst']]['id'],
                    'rl_mediumtype' => empty($netlink['license'])
                    ? 'radiowe na częstotliwości ogólnodostępnej'
                    : 'radiowe na częstotliwości wymagającej uzyskanie pozwolenia radiowego',
                    'rl_licencenr' => empty($netlink['license']) ? '' : $netlink['license'],
                    'rl_bandwidth' => $linkfrequency,
                    'rl_transmission' => $linktransmission,
                    'rl_throughput' => $netlink['speed'],
                    'rl_leaseavail' => 'Nie',
                    'rl_invproject' => $netlink['invproject'],
                    'rl_invstatus' => strlen($netlink['invproject']) ? $NETELEMENTSTATUSES[$netlink['status']] : '',
                );
                $buffer .= 'LB,' . to_csv($data) . EOL;
            } else {
                $data = array(
                    'lp_id' => $netlineid,
                    'lp_owner' => 'Własna',
                    'lp_foreingerid' => '',
                    'lp_anodetype' => $NETELEMENTOWNERSHIPS[$netnodes[$netlink['src']]['ownership']],
                    'lp_anodeid' => $netnodes[$netlink['src']]['id'],
                    'lp_bnodetype' => $NETELEMENTOWNERSHIPS[$netnodes[$netlink['dst']]['ownership']],
                    'lp_bnodeid' => $netnodes[$netlink['dst']]['id'],
                    'lp_tech' => $linktypes[$netlink['type']]['technologia'],
                    'lp_fibertype' => $netlink['type'] == 2 ? $linktypes[$netlink['type']]['typ'] : '',
                    'lp_fibertotal' => $netlink['type'] == 2 ? $linktypes[$netlink['type']]['liczba_jednostek'] : '',
                    'lp_fiberused' => $netlink['type'] == 2 ? $linktypes[$netlink['type']]['liczba_jednostek'] : '',
                    'lp_eu' => strlen($netlink['invproject']) ? 'Tak' : 'Nie',
                    'lp_passiveavail' => 'Brak danych',
                    'lp_passivetype' => '',
                    'lp_fiberlease' => $netlink['type'] == 2 ? 'Nie' : '',
                    'lp_fiberleasecount' => '',
                    'lp_bandwidthlease' => 'Nie',
                    'lp_duct' => ($netnodes[$netlink['src']]['type'] == 14 && $netnodes[$netlink['dst']]['type'] == 14
                    ? 'napowietrzny' : $linktypes[$netlink['type']]['trakt']),
                    'lp_length' => $netlink['type'] == 2 ? '0.1' : '',
                    'lp_invproject' => $netlink['invproject'],
                    'lp_invstatus' => strlen($netlink['invproject']) ? $NETELEMENTSTATUSES[$netlink['status']] : '',
                );
                $buffer .= 'LK,' . to_csv($data) . EOL;
            }
            $netlineid++;
        }
    }
}

// save info about network links
$netlinkid = 1;
$snetlinks = '';
if ($netlinks) {
    foreach ($netlinks as $netlink) {
        if ($netnodes[$netlink['src']]['id'] != $netnodes[$netlink['dst']]['id']) {
            $data = array(
                'pol_id' => $netlinkid,
                'pol_owner' => 'Własna',
                'pol_foreignerid' => '',
                'pol_wa' => $netnodes[$netlink['src']]['id'],
                'pol_wb' => $netnodes[$netlink['dst']]['id'],
                'pol_blayer' => $netlink['foreign'] ? 'Tak' : 'Nie',
                'pol_dlayer' => $netlink['foreign'] ? 'Nie' : 'Tak',
                'pol_alayer' => 'Nie',
                'pol_internetusage' => 'Tak',
                'pol_voiceusage' => 'Nie',
                'pol_otherusage' => 'Nie',
                'pol_totalspeed' => $netlink['speed'],
                'pol_internetspeed' => $netlink['speed'],
                'pol_invproject' => $netlink['invproject'],
                'pol_invstatus' => strlen($netlink['invproject']) ? $NETELEMENTSTATUSES[$netlink['status']] : '',
            );
            $buffer .= 'P,' . to_csv($data) . EOL;
            $netlinkid++;
        }
    }
}
*/

$filename = tempnam(sys_get_temp_dir(), 'lms-pit') . '.zip';
$zipname = 'lms-pit.zip';

$zip = new ZipArchive();
if ($zip->open($filename, ZipArchive::CREATE)) {
    $zip->addFromString('podmioty_obce.csv', $po_buffer);
    $zip->addFromString('wezly.csv', $w_buffer);
    $zip->addFromString('punkty_elastycznosci.csv', $pe_buffer);
    $zip->addFromString('uslugi_w_adresach.csv', $uwa_buffer);
    $zip->addFromString('linie_bezprzewodowe.csv', $lb_buffer);
    $zip->addFromString('linie_kablowe.csv', $lk_buffer);

    $zip->close();
}

header('Content-type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipname . '"');
header('Pragma: public');

readfile($filename);
unlink($filename);
