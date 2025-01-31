<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2022 LMS Developers
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

include(ConfigHelper::getConfig('phpui.export_config', 'exportcfg.php'));

function form_num($num)
{
    return str_replace(',', '.', sprintf('%.2f', f_round($num)));
}

if (isset($_GET['type']) && $_GET['type'] == 'cash') {
    if ($_POST['from']) {
        list($year, $month, $day) = explode('/', $_POST['from']);
        $from = mktime(0, 0, 0, $month, $day, $year);
    } else {
        $from = mktime(0, 0, 0, date('m'), date('d'), date('Y'));
    }

    if ($_POST['to']) {
        list($year, $month, $day) = explode('/', $_POST['to']);
        $to = mktime(23, 59, 59, $month, $day, $year);
    }
        $to = mktime(23, 59, 59, date('m'), date('d'), date('Y'));

    $registry = intval($_POST['registry']);
    $user = intval($_POST['user']);
    $where = '';

    if ($registry) {
        $where .= ' AND regid = '.intval($registry);
    }
    if ($from) {
        $where .= ' AND cdate >= '.$from;
    }
    if ($to) {
        $where .= ' AND cdate <= '.$to;
    }
    if ($user) {
        $where .= ' AND userid = '.intval($user);
    }

    header('Content-Type: application/octetstream');
    header('Content-Disposition: attachment; filename='.$cash_filename);
    header('Pragma: public');

    if ($list = $DB->GetAll(
        'SELECT d.id AS id, value, number, cdate, d.customerid,
		d.name AS customer, address, zip, city, ten, ssn, userid,
		numberplans.template, extnumber, receiptcontents.description,
		cashregs.name AS cashreg, b.balance
		FROM documents d
        JOIN customerbalances b ON b.customerid = d.customerid
		LEFT JOIN receiptcontents ON (d.id = docid)
		LEFT JOIN numberplans ON (numberplanid = numberplans.id)
		LEFT JOIN cashregs ON (cashregs.id = regid)
		WHERE d.type = ?'
        .$where.'
			AND NOT EXISTS (
		    		SELECT 1 FROM vcustomerassignments a
				JOIN excludedgroups e ON (a.customergroupid = e.customergroupid)
				WHERE e.userid = lms_current_user() AND a.customerid = d.customerid) 
		ORDER BY docid, itemid',
        array(DOC_RECEIPT)
    )) {
        $record = '';
        $i = 0;

        if (is_array($cash_record)) {
            foreach ($cash_record as $r) {
                $record .= $r;
            }
        }

        foreach ($list as $idx => $row) {
            $line = $record ? $record : $cash_record;
            $i++;

            $clariondate = intval($row['cdate']/86400)+61731;
            $date = date($date_format, $row['cdate']);
            $number = docnumber(array(
                'number' => $row['number'],
                'template' => $row['template'],
                'cdate' => $row['cdate'],
                'ext_num' => $row['extnumber'],
                'customerid' => $row['customerid'],
            ));

            $line = str_replace('%CLARION_DATE', $clariondate, $line);
            $line = str_replace('%NUMBER', $number, $line);
            $line = str_replace('%DATE', $date, $line);
            $line = str_replace('%CID4', sprintf('%04d', $row['customerid']), $line);
            $line = str_replace('%CID', $row['customerid'], $line);
            $line = str_replace('%UID4', sprintf('%04d', $row['userid']), $line);
            $line = str_replace('%UID', $row['userid'], $line);
            $line = str_replace('%CUSTOMER', $row['customer'] ? $row['customer'] : $default_customer, $line);
            $line = str_replace('%ADDRESS', $row['address'], $line);
            $line = str_replace('%ZIP', $row['zip'], $line);
            $line = str_replace('%CITY', $row['city'], $line);
            $line = str_replace('%TEN', $row['ten'], $line);
            $line = str_replace('%SSN', $row['ssn'], $line);
            $line = str_replace('%CASHREG', $row['cashreg'], $line);
            $line = str_replace('%DESC', $row['description'], $line);
            $line = str_replace('%VALUE', $row['value'], $line);
            $line = str_replace('%ABSVALUE', str_replace('-', '', $row['value']), $line);
            $line = str_replace('%BALANCE', form_num($row['balance']), $line);
            $line = str_replace('%N', $row['number'], $line);
            $line = str_replace('%I', $i, $line);

            if (strpos($line, '%PREFIX')!==false || strpos($line, '%SUFFIX')!==false) {
                $tmp = explode('%N', $row['template']);
                if ($tmp[0]) {
                    $line = str_replace('%PREFIX', docnumber(array(
                            'number' => $row['number'],
                            'template' => $tmp[0],
                            'cdate' => $row['cdate'],
                            'ext_num' => $row['extnumber'],
                            'customerid' => $row['customerid'],
                        )), $line);
                } else {
                    $line = str_replace('%PREFIX', '', $line);
                }
                if ($tmp[1]) {
                    $line = str_replace('%SUFFIX', docnumber(array(
                            'number' => $row['number'],
                            'template' => $tmp[1],
                            'cdate' => $row['cdate'],
                            'ext_num' => $row['extnumber'],
                            'customerid' => $row['customerid'],
                        )), $line);
                } else {
                    $line = str_replace('%SUFFIX', '', $line);
                }
            }

            if (strpos($line, '%TYPE')!==false) {
                if ($row['value']<0) {
                    $type = $cash_out_type;
                } else {
                    $type = $cash_in_type;
                }

                // fragment dla systemu Enova: rozpoznawanie
                // wyciągów bankowych na podstawie przedrostka
                // planu numeracyjnego
                if (strpos($number, 'PB')===0) {
                    $type += 2;
                }

                $line = str_replace('%TYPE', $type, $line);
            }

            if (strtoupper($encoding)!='UTF-8') {
                if (strtoupper($encoding)=='MAZOVIA') {
                    $line = mazovia_to_utf8($line);
                } else {
                    $line = iconv('UTF-8', $encoding.'//TRANSLIT', $line);
                }
            }

            print $line.$endln;
        }
    }

    die;
} elseif (isset($_GET['type']) && $_GET['type'] == 'invoices') {
    $from = $_POST['from'];
    $to = $_POST['to'];

    $customergroups = isset($_POST['customergroups']) && is_array($_POST['customergroups']) ? Utils::filterIntegers($_POST['customergroups']) : array();

    // date format 'yyyy/mm/dd'
    if ($from) {
        list($year, $month, $day) = explode('/', $from);
        $unixfrom = mktime(0, 0, 0, $month, $day, $year);
    } else {
        $from = date('Y/m/d', time());
        $unixfrom = mktime(0, 0, 0); //today
    }
    if ($to) {
        list($year, $month, $day) = explode('/', $to);
        $unixto = mktime(23, 59, 59, $month, $day, $year);
    } else {
        $to = date('Y/m/d', time());
        $unixto = mktime(23, 59, 59); //today
    }

    $divisionid = intval($_POST['division']);

    $listdata = array();
    $invoicelist = array();

    // we can't simply get documents with SUM(value*count)
    // because we need here incoices-like round-off

    // get documents items numeric values for calculations
    $items = $DB->GetAll(
        'SELECT docid, itemid, taxid,
            grossprice, grossvalue, netprice, netvalue, taxvalue, count,
            diff_grossprice, diff_grossvalue, diff_netprice, diff_netvalue, diff_taxvalue, diff_count,
            description, prodid, content, d.customerid
        FROM documents d
        LEFT JOIN vinvoicecontents ON docid = d.id
        WHERE (type = ? OR type = ?) AND (cdate BETWEEN ? AND ?)
            ' . ($divisionid ? ' AND d.divisionid = ' . $divisionid : '')
            . (empty($customergroups) ? '' : ' AND EXISTS (
                SELECT 1 FROM customerassignments ca WHERE ca.customerid = d.customerid AND ca.customergroupid IN (' . implode(',', $customergroups) . ')
            )')
            . ' AND NOT EXISTS (
                    SELECT 1 FROM vcustomerassignments a
                JOIN excludedgroups e ON (a.customergroupid = e.customergroupid)
                WHERE e.userid = lms_current_user() AND a.customerid = d.customerid)
        ORDER BY cdate, docid',
        array(DOC_INVOICE, DOC_CNOTE, $unixfrom, $unixto)
    );

    // get documents data
    $docs = $DB->GetAllByKey(
        'SELECT d.id AS id, number, cdate, d.customerid, userid, name, address, zip, city, ten, ssn,
            numberplans.template, reference, extnumber, paytime, closed, b.balance
        FROM documents d
        JOIN customerbalances b ON b.customerid = d.customerid
        LEFT JOIN numberplans ON d.numberplanid = numberplans.id
        WHERE (d.type = ? OR d.type = ?) AND (d.cdate BETWEEN ? AND ?)
            ' . ($divisionid ? ' AND d.divisionid = ' . $divisionid : ''),
        'id',
        array(DOC_INVOICE, DOC_CNOTE, $unixfrom, $unixto)
    );

    // wysyłamy ...
    header('Content-Type: application/octetstream');
    header('Content-Disposition: attachment; filename='.$inv_filename);
    header('Pragma: public');

    if ($items) {
        // get taxes for calculations
        $taxes = $LMS->GetTaxes();
        $i = 0;

        $record = '';

        if (is_array($inv_record)) {
            foreach ($inv_record as $r) {
                $record .= $r;
            }
        }

        foreach ($items as $idx => $row) {
            $docid = $row['docid'];
            $taxid = $row['taxid'];
            $doc = $docs[$docid];

            if (!isset($rectax[$taxid])) {
                $rectax[$taxid] = array(
                    'tax' => 0,
                    'val' => 0,
                    'sum' => 0,
                );
            }

            if (!isset($rec['brutto'])) {
                $rec['brutto'] = 0;
            }

            if ($doc['reference']) {
                $rectax[$taxid]['tax'] -= $row['taxvalue'] - $row['diff_taxvalue'];
                $rectax[$taxid]['val'] -= $row['netvalue'] - $row['diff_netvalue'];
                $rectax[$taxid]['sum'] -= $row['grossvalue'] - $row['diff_grossvalue'];
                $rec['brutto'] -= $row['grossvalue'] - $row['diff_grossvalue'];
            }

            $sum = $row['grossvalue'];
            $val = $row['netvalue'];
            $tax = $row['taxvalue'];

            $rectax[$taxid]['tax'] += $tax;
            $rectax[$taxid]['val'] += $val;
            $rectax[$taxid]['sum'] += $sum;
            $rec['brutto'] += $sum;

            if (!isset($items[$idx + 1]['docid']) || $row['docid'] != $items[$idx + 1]['docid']) {
                $line = $record ? $record : $inv_record;
                $i++;

                $clariondate = intval($doc['cdate'] / 86400) + 61731;
                $date = date($date_format, $doc['cdate']);
                $number = docnumber(array(
                    'number' => $doc['number'],
                    'template' => $doc['template'],
                    'cdate' => $doc['cdate'],
                    'ext_num' => $doc['extnumber'],
                    'customerid' => $doc['customerid'],
                ));

                $line = str_replace('%CLARION_DATE', $clariondate, $line);
                $line = str_replace('%NUMBER', $number, $line);
                $line = str_replace('%DATE', $date, $line);
                $line = str_replace('%DEADLINE', date($date_format, $doc['cdate']+$doc['paytime']*86400), $line);
                $line = str_replace('%CID4', sprintf('%04d', $doc['customerid']), $line);
                $line = str_replace('%CID', $doc['customerid'], $line);
                $line = str_replace('%UID4', sprintf('%04d', $doc['userid']), $line);
                $line = str_replace('%UID', $doc['userid'], $line);
                $line = str_replace('%CUSTOMER', $doc['name'], $line);
                $line = str_replace('%ADDRESS', $doc['address'], $line);
                $line = str_replace('%ZIP', $doc['zip'], $line);
                $line = str_replace('%CITY', $doc['city'], $line);
                $line = str_replace('%TEN', $doc['ten'], $line);
                $line = str_replace('%SSN', $doc['ssn'], $line);
//              $line = str_replace('%DESC', $row['description'], $line);
                $line = str_replace('%VALUE', form_num($rec['brutto']), $line);
                $line = str_replace('%ABSVALUE', str_replace('-', '', form_num($rec['brutto'])), $line);
                $line = str_replace('%BALANCE', form_num($doc['balance']), $line);

                $v = 0;
                $netto_v = 0;
                $tax_v = 0;

                foreach ($rectax as $id => $tax) {
                    $v++;
                    $line = str_replace('%VATP'.$v, form_num($taxes[$id]['value']), $line);
                    $line = str_replace('%TAXED'.$v, form_num($taxes[$id]['taxed']), $line);
                    $line = str_replace('%VAT'.$v, form_num($tax['tax']), $line);
                    $line = str_replace('%NETTO'.$v, form_num($tax['val']), $line);
                    $line = str_replace('%VALUE'.$v, form_num($tax['sum']), $line);

                    $netto_v += $tax['val'];
                    $tax_v += $tax['tax'];
                }

                for ($x=$v+1; $x<=8; $x++) {
                    $line = str_replace('%VATP'.$x, '0.00', $line);
                    $line = str_replace('%VAT'.$x, '0.00', $line);
                    $line = str_replace('%NETTO'.$x, '0.00', $line);
                    $line = str_replace('%TAXED'.$x, '0.00', $line);
                    $line = str_replace('%VALUE'.$x, '0.00', $line);
                }

                $line = str_replace('%VAT', form_num($tax_v), $line);
                $line = str_replace('%NETTO', form_num($netto_v), $line);

                if (strpos($line, '%PREFIX')!==false || strpos($line, '%SUFFIX')!==false) {
                    $tmp = explode('%N', $doc['template']);
                    if ($tmp[0]) {
                        $line = str_replace('%PREFIX', docnumber(array(
                                'number' => $doc['number'],
                                'template' => $tmp[0],
                                'cdate' => $doc['cdate'],
                                'ext_num' => $doc['extnumber'],
                                'customerid' => $doc['customerid'],
                            )), $line);
                    } else {
                        $line = str_replace('%PREFIX', '', $line);
                    }
                    if ($tmp[1]) {
                        $line = str_replace('%SUFFIX', docnumber(array(
                                'number' => $doc['number'],
                                'template' => $tmp[1],
                                'cdate' => $doc['cdate'],
                                'ext_num' => $doc['extnumber'],
                                'customerid' => $doc['customerid'],
                            )), $line);
                    } else {
                        $line = str_replace('%SUFFIX', '', $line);
                    }
                }

                if (strpos($line, '%TYPE')!==false) {
                    if ($doc['reference']) {
                        $type = $cnote_type;
                    } else {
                        $type = $invoice_type;
                    }

                    $line = str_replace('%TYPE', $type, $line);
                }

                if (strtoupper($encoding)!='UTF-8') {
                    if (strtoupper($encoding)=='MAZOVIA') {
                        $line = mazovia_to_utf8($line);
                    } else {
                        $line = iconv('UTF-8', $encoding.'//TRANSLIT', $line);
                    }
                }

                $line = str_replace('%N', $doc['number'], $line);
                $line = str_replace('%I', $i, $line);

                print $line.$endln;

                unset($rec);
                unset($rectax);
            }
        }
    }

    die;
}

$layout['pagetitle'] = trans('Export');

$SMARTY->assign('users', $LMS->GetUserNames());
$SMARTY->assign('cashreglist', $DB->GetAllByKey('SELECT id, name FROM cashregs ORDER BY name', 'id'));
$SMARTY->assign('divisions', $LMS->GetDivisions());
$SMARTY->assign('customergroups', $LMS->CustomergroupGetAll());
$SMARTY->display('export.html');
