<?php

/*
 *  LMS version 1.11-git
 *
 *  Copyright (C) 2001-2022 LMS Developers
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

/**
 * LMSLocationManagerInterface
 *
 */
interface LMSLocationManagerInterface
{
    public function UpdateCountryState($zip, $stateid);

    public function GetCountryStates();

    public function getCountryStateIdByName($state_name);

    public function GetCountries();

    public function GetCountryName($id);

    public function DeleteAddress($address_id);

    public function InsertAddress($args);

    public function InsertCustomerAddress($customer_id, $args);

    public function UpdateAddress($args);

    public function SetAddress($args);

    public function UpdateCustomerAddress($customer_id, $args);

    public function ValidAddress($args);

    public function CopyAddress($address_id);

    public function GetAddress($address_id);

    public function GetCustomerAddress($customer_id, $type = BILLING_ADDRESS);

    public function TerytToLocation($terc, $simc, $ulic);

    public function GetZipCode(array $params);

    public function GetCitiesWithSections();

    public function getCountryCodeById($countryid);

    public function isTerritState($state);
}
