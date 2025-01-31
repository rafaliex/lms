#!/usr/bin/env php
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

// REPLACE THIS WITH PATH TO YOUR CONFIG FILE

// PLEASE DO NOT MODIFY ANYTHING BELOW THIS LINE UNLESS YOU KNOW
// *EXACTLY* WHAT ARE YOU DOING!!!
// *******************************************************************

ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_DEPRECATED);

$http_mode = isset($_SERVER['HTTP_HOST']);

if ($http_mode) {
    ob_clean();
    $options = array();
} else {
    $parameters = array(
        'config-file:' => 'C:',
        'quiet' => 'q',
        'help' => 'h',
        'version' => 'v',
        'section:' => 's:',
        'message-file:' => 'm:',
        'force-http-mode' => 'f',
    );

    $long_to_shorts = array();
    foreach ($parameters as $long => $short) {
        $long = str_replace(':', '', $long);
        if (isset($short)) {
            $short = str_replace(':', '', $short);
        }
        $long_to_shorts[$long] = $short;
    }

    $options = getopt(
        implode(
            '',
            array_filter(
                array_values($parameters),
                function ($value) {
                    return isset($value);
                }
            )
        ),
        array_keys($parameters)
    );

    foreach (array_flip(array_filter($long_to_shorts, function ($value) {
        return isset($value);
    })) as $short => $long) {
        if (array_key_exists($short, $options)) {
            $options[$long] = $options[$short];
            unset($options[$short]);
        }
    }
}

if (isset($options['version'])) {
    print <<<EOF
lms-sms2rt.php
(C) 2001-2023 LMS Developers

EOF;
    exit(0);
}

if (isset($options['help'])) {
    print <<<EOF
lms-sms2rt.php
(C) 2001-2023 LMS Developers

-C, --config-file=/etc/lms/lms.ini      alternate config file (default: /etc/lms/lms.ini);
-m, --message-file=<message-file>       name of message file;
-h, --help                      print this help and exit;
-v, --version                   print version info and exit;
-q, --quiet                     suppress any output, except errors;
-s, --section=<section-name>    section name from lms configuration where settings
                                are stored
-f, --force-http-mode           force callback url mode even if script is not launched under
                                http server control

EOF;
    exit(0);
}

$quiet = isset($options['quiet']);
if (!$quiet && !$http_mode) {
    print <<<EOF
lms-sms2rt.php
(C) 2001-2023 LMS Developers

EOF;
}

$config_section = isset($options['section']) && preg_match('/^[a-z0-9-_]+$/i', $options['section']) ? $options['section'] : 'sms';

if (isset($options['config-file'])) {
    $CONFIG_FILE = $options['config-file'];
} elseif ($http_mode && is_readable('lms.ini')) {
    $CONFIG_FILE = 'lms.ini';
} elseif ($http_mode && is_readable(DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'lms' . DIRECTORY_SEPARATOR . 'lms-' . $_SERVER['HTTP_HOST'] . '.ini')) {
    $CONFIG_FILE = DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'lms' . DIRECTORY_SEPARATOR . 'lms-' . $_SERVER['HTTP_HOST'] . '.ini';
} else {
    $CONFIG_FILE = DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'lms' . DIRECTORY_SEPARATOR . 'lms.ini';
}

if (isset($options['force-http-mode'])) {
    $http_mode = true;
}


if (!$quiet && (!$http_mode || isset($options['force-http-mode']))) {
    echo 'Using file ' . $CONFIG_FILE . ' as config.' . PHP_EOL;
}

if (!is_readable($CONFIG_FILE)) {
    die('Unable to read configuration file [' . $CONFIG_FILE . ']!' . PHP_EOL);
}

define('CONFIG_FILE', $CONFIG_FILE);

$CONFIG = (array) parse_ini_file($CONFIG_FILE, true);

// Check for configuration vars and set default values
$CONFIG['directories']['sys_dir'] = (!isset($CONFIG['directories']['sys_dir']) ? getcwd() : $CONFIG['directories']['sys_dir']);
$CONFIG['directories']['lib_dir'] = (!isset($CONFIG['directories']['lib_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'lib' : $CONFIG['directories']['lib_dir']);
$CONFIG['directories']['storage_dir'] = (!isset($CONFIG['directories']['storage_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'storage' : $CONFIG['directories']['storage_dir']);
$CONFIG['directories']['plugin_dir'] = (!isset($CONFIG['directories']['plugin_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'plugins' : $CONFIG['directories']['plugin_dir']);
$CONFIG['directories']['plugins_dir'] = $CONFIG['directories']['plugin_dir'];

define('SYS_DIR', $CONFIG['directories']['sys_dir']);
define('LIB_DIR', $CONFIG['directories']['lib_dir']);
define('STORAGE_DIR', $CONFIG['directories']['storage_dir']);
define('PLUGIN_DIR', $CONFIG['directories']['plugin_dir']);
define('PLUGINS_DIR', $CONFIG['directories']['plugin_dir']);

// Load autoloader
$composer_autoload_path = SYS_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (file_exists($composer_autoload_path)) {
    require_once $composer_autoload_path;
} else {
    die("Composer autoload not found. Run 'composer install' command from LMS directory and try again. More information at https://getcomposer.org/" . PHP_EOL);
}

// Do some checks and load config defaults
require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'config.php');

// Init database

$DB = null;

try {
    $DB = LMSDB::getInstance();
} catch (Exception $ex) {
    trigger_error($ex->getMessage(), E_USER_WARNING);
    // can't work without database
    die("Fatal error: cannot connect to database!" . PHP_EOL);
}

// Include required files (including sequence is important)

require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'common.php');
require_once(LIB_DIR . DIRECTORY_SEPARATOR . 'language.php');
include_once(LIB_DIR . DIRECTORY_SEPARATOR . 'definitions.php');

$SYSLOG = SYSLOG::getInstance();

// Initialize Session, Auth and LMS classes

$AUTH = null;
$LMS = new LMS($DB, $AUTH, $SYSLOG);

$incoming_queue = ConfigHelper::getConfig($config_section . '.incoming_queue', 'SMS');
$default_mail_from = ConfigHelper::getConfig($config_section . '.default_mail_from', 'root@localhost');
$categories = ConfigHelper::getConfig($config_section . '.categories', 'default');
$categories = preg_split('/\s*,\s*/', trim($categories));
$lms_url = ConfigHelper::getConfig($config_section . '.lms_url', '', true);
$service = ConfigHelper::getConfig($config_section . '.service', '', true);
if (!empty($service)) {
    LMSConfig::getConfig()->getSection('sms')->addVariable(new ConfigVariable('service', $service));
}
$prefix = ConfigHelper::getConfig($config_section . '.prefix', '', true);
$newticket_notify = ConfigHelper::checkConfig(
    'rt.new_ticket_notify',
    ConfigHelper::checkConfig('phpui.newticket_notify', true)
);
$helpdesk_customerinfo = ConfigHelper::checkConfig(
    'rt.notification_customerinfo',
    ConfigHelper::checkConfig('phpui.helpdesk_customerinfo')
);
$helpdesk_sendername = ConfigHelper::getConfig('rt.sender_name', ConfigHelper::getConfig('phpui.helpdesk_sender_name'));
$customer_auto_reply_body = ConfigHelper::getConfig('sms.customer_auto_reply_body', '', true);

$detect_customer_location_address = ConfigHelper::checkConfig($config_section . '.detect_customer_location_address');

// Load plugin files and register hook callbacks
$plugin_manager = new LMSPluginManager();
$LMS->setPluginManager($plugin_manager);

$message_files = array();

if ($http_mode) {
    // call external incoming SMS handler(s)
    $errors = array();
    $content = null;

    foreach (explode(',', $service) as $single_service) {
        $data = $LMS->executeHook(
            'parse_incoming_sms',
            array(
                'service' => $single_service
            )
        );
        if (isset($data['error'])) {
            $errors[$single_service] = $data['error'];
            continue;
        }
        if (isset($data['content'])) {
            $content = $data['content'];
            break;
        }
    }

    if (!isset($content)) {
        foreach ($errors as $single_service => $error) {
            echo $single_service . ': ' . $error . '<br>';
        }
        die;
    }

    if (is_array($content)) {
        foreach ($content as $sms) {
            $message_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'LMS_INCOMING_MESSAGE-' . uniqid('', true);
            file_put_contents($message_file, $sms);
            $message_files[] = $message_file;
        }
    } else {
        $message_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'LMS_INCOMING_MESSAGE-' . uniqid('', true);
        file_put_contents($message_file, $content);
        $message_files[] = $message_file;
    }
} else {
    if (isset($options['message-file'])) {
        $message_files[] = $options['message-file'];
    } else {
        die('Required message file parameter!' . PHP_EOL);
    }
}

if (($queueid = $DB->GetOne(
    "SELECT id FROM rtqueues WHERE UPPER(name) = UPPER(?)",
    array($incoming_queue)
)) == null) {
    die('Undefined queue!' . PHP_EOL);
}

$plugins = $plugin_manager->getAllPluginInfo(LMSPluginManager::OLD_STYLE);
if (!empty($plugins)) {
    foreach ($plugins as $plugin_name => $plugin) {
        if ($plugin['enabled']) {
            require(LIB_DIR . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $plugin_name . '.php');
        }
    }
}

foreach ($message_files as $message_file) {
    if (($fh = fopen($message_file, 'r')) != null) {
        $sms = fread($fh, 4000);
        fclose($fh);

        $lines = explode("\n", $sms);

        $body = false;
        $message = "";
        $phone = null;
        $date = null;
        $ucs = false;
        reset($lines);
        while (($line = current($lines)) !== false) {
            if (preg_match('/^From: ([0-9]{3,15})$/', $line, $matches) && $phone == null) {
                $phone = $matches[1];
            }
            if (preg_match('/^Received: (.*)$/', $line, $matches) && !isset($date)) {
                $date = strtotime($matches[1]);
                if ($date === false) {
                    $date = null;
                }
            }
            if (preg_match('/^Alphabet:.*UCS2?$/', $line)) {
                $ucs = true;
            }
            if (empty($line) && !$body) {
                $body = true;
            } else if ($body) {
                if ($ucs) {
                    $line = preg_replace('/\x0$/', "\x0\n", $line);
                }
                $message .= $line;
            }
            next($lines);
        }
        if ($ucs) {
            $message = iconv('UNICODEBIG', 'UTF-8', $message);
        }

        if (!empty($phone)) {
            $phone = preg_replace('/^' . $prefix . '/', '', $phone);

            $customer = $DB->GetRow(
                "SELECT customerid AS cid, " . $DB->Concat('lastname', "' '", 'c.name') . " AS name
                FROM customercontacts cc
                LEFT JOIN customers c ON c.id = cc.customerid
                WHERE c.deleted = 0
                    AND (cc.type & ?) > 0
                    AND REPLACE(REPLACE(contact, ' ', ''), '-', '') ?LIKE? ?",
                array(
                    CONTACT_MOBILE | CONTACT_LANDLINE,
                    '%' . $phone,
                )
            );

            $formatted_phone = preg_replace('/^([0-9]{3})([0-9]{3})([0-9]{3})$/', '$1 $2 $3', $phone);
            if (!empty($customer_auto_reply_body)) {
                $LMS->SendSMS($phone, $customer_auto_reply_body, null, $LMS->getCustomerSMSOptions());
                sleep(1);
            }
        } else {
            $customer = null;
        }

//      if ($phone[0] != '+') {
//          $phone = '+' . $phone;
//      }

        $cats = array();
        foreach ($categories as $category) {
            if (($catid = $LMS->GetCategoryIdByName($category)) != null) {
                $cats[$catid] = $category;
            }
        }
        $requestor = !empty($customer['name']) ? $customer['name'] : (empty($phone) ? '' : $formatted_phone);

        if (empty($customer['cid']) || !$detect_customer_location_address) {
            $address_id = null;
        } else {
            $address_id = $LMS->detectCustomerLocationAddress($customer['cid']);
        }

        $tid = $LMS->TicketAdd(array(
            'queue' => $queueid,
            'createtime' => isset($date) ? $date : null,
            'requestor' => $requestor,
            'requestor_phone' => empty($phone) ? null : $phone,
            'subject' => trans('SMS from $a', (empty($phone) ? trans('unknown') : $formatted_phone)),
            'customerid' => !empty($customer['cid']) ? $customer['cid'] : 0,
            'address_id' => $address_id,
            'body' => $message,
            'phonefrom' => empty($phone) ? '' : $phone,
            'categories' => $cats,
            'source' => RT_SOURCE_SMS,
        ));

        if ($newticket_notify) {
            if (!empty($helpdesk_sender_name)) {
                $mailfname = $LMS->GetQueueName($queueid);
                $mailfname = '"'.$mailfname.'"';
            } else {
                $mailfname = '';
            }

            if ($qemail = $LMS->GetQueueEmail($queueid)) {
                $mailfrom = $qemail;
            } else {
                $mailfrom = $default_mail_from;
            }

            $headers['From'] = $mailfname.' <'.$mailfrom.'>';
            $headers['Reply-To'] = $headers['From'];

            $queuedata = $LMS->GetQueue($queueid);

            if (!empty($customer['cid'])) {
                $info = $LMS->GetCustomer($customer['cid'], true);

                $emails = array_map(
                    function ($contact) {
                        return $contact['fullname'];
                    },
                    array_filter(
                        $LMS->GetCustomerContacts($customer['cid'], CONTACT_EMAIL),
                        function ($contact) {
                            return $contact['type'] & CONTACT_HELPDESK_NOTIFICATIONS;
                        }
                    )
                );

                $all_phones = array_filter(
                    $LMS->GetCustomerContacts($customer['cid'], CONTACT_LANDLINE | CONTACT_MOBILE),
                    function ($contact) {
                        return $contact['type'] & CONTACT_HELPDESK_NOTIFICATIONS;
                    }
                );

                $phones = array_map(function ($contact) {
                    return $contact['fullname'];
                }, $all_phones);

                $mobile_phones = array_filter($all_phones, function ($contact) {
                    return ($contact['type'] & (CONTACT_MOBILE | CONTACT_DISABLED)) == CONTACT_MOBILE;
                });

                if ($helpdesk_customerinfo) {
                    $params = array(
                        'id' => $tid,
                        'customerid' => $customer['cid'],
                        'customer' => $info,
                        'emails' => $emails,
                        'phones' => $phones,
                        'categories' => $cats,
                    );
                    $mail_customerinfo = $LMS->ReplaceNotificationCustomerSymbols(
                        ConfigHelper::getConfig(
                            'rt.notification_mail_body_customerinfo_format',
                            ConfigHelper::getConfig('phpui.helpdesk_customerinfo_mail_body')
                        ),
                        $params
                    );
                    $sms_customerinfo = $LMS->ReplaceNotificationCustomerSymbols(
                        ConfigHelper::getConfig(
                            'rt.notification_sms_body_customerinfo_format',
                            ConfigHelper::getConfig('phpui.helpdesk_customerinfo_sms_body')
                        ),
                        $params
                    );
                }

                if (!empty($queuedata['newticketsubject']) && !empty($queuedata['newticketbody']) && !empty($emails)) {
                    $custmail_subject = $queuedata['newticketsubject'];
                    $custmail_subject = preg_replace_callback(
                        '/%(\\d*)tid/',
                        function ($m) use ($tid) {
                            return sprintf('%0' . $m[1] . 'd', $tid);
                        },
                        $custmail_subject
                    );
                    $custmail_subject = str_replace(
                        '%title',
                        trans('SMS from $a', (empty($phone) ? trans("unknown") : $formatted_phone)),
                        $custmail_subject
                    );
                    $custmail_body = $queuedata['newticketbody'];
                    $custmail_body = preg_replace_callback(
                        '/%(\\d*)tid/',
                        function ($m) use ($tid) {
                            return sprintf('%0' . $m[1] . 'd', $tid);
                        },
                        $custmail_body
                    );
                    $custmail_body = str_replace('%cid', $customer['cid'], $custmail_body);
                    $custmail_body = str_replace('%pin', $info['pin'], $custmail_body);
                    $custmail_body = str_replace('%customername', $info['customername'], $custmail_body);
                    $custmail_body = str_replace(
                        '%title',
                        trans('SMS from $a', (empty($phone) ? trans("unknown") : $formatted_phone)),
                        $custmail_body
                    );
                    $custmail_body = str_replace('%body', $message, $custmail_body);
                    $custmail_headers = array(
                        'From' => $headers['From'],
                        'Reply-To' => $headers['From'],
                        'Subject' => $custmail_subject,
                    );
                    foreach ($emails as $email) {
                        $custmail_headers['To'] = '<' . $email . '>';
                        $LMS->SendMail($email, $custmail_headers, $custmail_body, null, null, $LMS->GetRTSmtpOptions());
                    }
                }
                if (!empty($queuedata['newticketsmsbody']) && !empty($mobile_phones)) {
                    $custsms_body = $queuedata['newticketsmsbody'];
                    $custsms_body = preg_replace_callback(
                        '/%(\\d*)tid/',
                        function ($m) use ($tid) {
                            return sprintf('%0' . $m[1] . 'd', $tid);
                        },
                        $custsms_body
                    );
                    $custsms_body = str_replace('%cid', $customer['cid'], $custsms_body);
                    $custsms_body = str_replace('%pin', $info['pin'], $custsms_body);
                    $custsms_body = str_replace('%customername', $info['customername'], $custsms_body);
                    $custsms_body = str_replace(
                        '%title',
                        trans('SMS from $a', (empty($phone) ? trans("unknown") : $formatted_phone)),
                        $custsms_body
                    );
                    $custsms_body = str_replace('%body', $message, $custsms_body);

                    foreach ($mobile_phones as $phone) {
                        $LMS->SendSMS($phone['contact'], $custsms_body);
                    }
                }
            } elseif ($helpdesk_customerinfo) {
                $mail_customerinfo = "\n\n-- \n" . trans('Customer:') . ' ' . $requestor;
                $sms_customerinfo = "\n" . trans('Customer:') . ' ' . $requestor;
            }

            $params = array(
                'id' => $tid,
                'queue' => $queuedata['name'],
                'messageid' => isset($msgid) ? $msgid : null,
                'customerid' => empty($customer) ? null : $customer['cid'],
                'status' => $RT_STATES[RT_NEW],
                'categories' => $cats,
                'subject' => trans('SMS from $a', (empty($phone) ? trans("unknown") : $formatted_phone)),
                'body' => $message,
                'url' => $lms_url . '?m=rtticketview&id=',
            );
            $headers['Subject'] = $LMS->ReplaceNotificationSymbols(ConfigHelper::getConfig('rt.notification_mail_subject', ConfigHelper::getConfig('phpui.helpdesk_notification_mail_subject')), $params);
            $params['customerinfo'] = isset($mail_customerinfo) ? $mail_customerinfo : null;
            $message = $LMS->ReplaceNotificationSymbols(ConfigHelper::getConfig('rt.notification_mail_body', ConfigHelper::getConfig('phpui.helpdesk_notification_mail_body')), $params);
            $params['customerinfo'] = isset($sms_customerinfo) ? $sms_customerinfo : null;
            $sms_body = $LMS->ReplaceNotificationSymbols(ConfigHelper::getConfig('rt.notification_sms_body', ConfigHelper::getConfig('phpui.helpdesk_notification_sms_body')), $params);

            $LMS->NotifyUsers(array(
                'queue' => $queueid,
                'mail_headers' => $headers,
                'mail_body' => $message,
                'sms_body' => $sms_body,
            ));
        }
    } else {
        die('Message file \'' . $message_file . '\' doesn\'t exist!' . PHP_EOL);
    }
}

if ($http_mode && !empty($message_files)) {
    foreach ($message_files as $message_file) {
        @unlink($message_file);
    }
}
