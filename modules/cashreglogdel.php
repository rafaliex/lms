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

$id = intval($_GET['id']);
list ($regid, $userid) = array_values($DB->GetRow('SELECT regid, userid FROM cashreglog WHERE id = ?', array($id)));

if (!$regid) {
    $SESSION->redirect('?m=cashreglist');
}

if ($DB->GetOne(
    'SELECT rights FROM cashrights WHERE userid=? AND regid=?',
    array(
        Auth::GetCurrentUser(),
        $regid
    )
) < 256) {
    $SMARTY->display('noaccess.html');
    $SESSION->close();
    die;
}

if ($SYSLOG) {
    $args = array(
        SYSLOG::RES_CASHREGHIST => $id,
        SYSLOG::RES_CASHREG => $regid,
        SYSLOG::RES_USER => $userid,
    );
    $SYSLOG->AddMessage(SYSLOG::RES_CASHREGHIST, SYSLOG::OPER_DELETE, $args);
}
$DB->Execute('DELETE FROM cashreglog WHERE id = ?', array($id));

$SESSION->redirect_to_history_entry();
