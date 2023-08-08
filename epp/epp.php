<?php

//require 'vendor/autoload.php';
global $c;
$c = require 'config.php';

use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Table;

$table = new Table(1024);
$table->column('clid', Table::TYPE_STRING, 64);
$table->column('logged_in', Table::TYPE_INT, 1);
$table->create();

$dsn = "mysql:host={$c['mysql_host']};dbname={$c['mysql_database']};port={$c['mysql_port']}";
$db = new PDO($dsn, $c['mysql_username'], $c['mysql_password']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$server = new Server($c['epp_host'], $c['epp_port']);
$server->set([
    'enable_coroutine' => true,
    'worker_num' => swoole_cpu_num() * 4,
    'pid_file' => $c['epp_pid'],
    'tcp_user_timeout' => 10,
    'open_ssl' => true,
    'ssl_cert_file' => $c['ssl_cert'],
    'ssl_key_file' => $c['ssl_key'],
    'ssl_verify_peer' => false,
    'ssl_allow_self_signed' => false,
    'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
]);

$server->handle(function (Connection $conn) use ($table, $db) {
    echo "Client connected.\n";
    sendGreeting($conn);
  
    while (true) {
        $data = $conn->recv();
        $connId = spl_object_id($conn);

        if ($data === false || strlen($data) < 4) {
            sendEppError($conn, 2100, 'Data reception error');
            break;
        }

        $length = unpack('N', substr($data, 0, 4))[1];
        $xmlData = substr($data, 4, $length - 4);

        $xml = simplexml_load_string($xmlData, 'SimpleXMLElement', LIBXML_DTDLOAD | LIBXML_NOENT);
        $xml->registerXPathNamespace('e', 'urn:ietf:params:xml:ns:epp-1.0');
        $xml->registerXPathNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->registerXPathNamespace('domain', 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->registerXPathNamespace('contact', 'urn:ietf:params:xml:ns:contact-1.0');
        $xml->registerXPathNamespace('host', 'urn:ietf:params:xml:ns:host-1.0');

        if ($xml === false) {
            sendEppError($conn, 2001, 'Invalid XML');
            break;
        }
    
        if ($xml->getName() != 'epp') {
            continue;  // Skip this iteration if not an EPP command
        }

        switch (true) {
            case isset($xml->command->login):
            {
                $clID = (string) $xml->command->login->clID;
                $pw = (string) $xml->command->login->pw;

                if (checkLogin($db, $clID, $pw)) {
                    $table->set($connId, ['clid' => $clID, 'logged_in' => 1]);
                    $eppLoginResponse = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                    <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                      <response>
                        <result code="1000">
                          <msg>Login successful</msg>
                        </result>
                        <trID>
                          <clTRID>ABC-12345</clTRID>
                          <svTRID>SRV-54321</svTRID>
                        </trID>
                      </response>
                    </epp>';

                    $length = strlen($eppLoginResponse) + 4; // Total length including the 4-byte header
                    $lengthData = pack('N', $length); // Pack the length into 4 bytes

                    $conn->send($lengthData . $eppLoginResponse);
                } else {
                    sendEppError($conn, 2200, 'Authentication error');
                }
                break;
            }
      
            case isset($xml->command->logout):
            {
                $table->del($connId);
                $eppLogoutResponse = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
                <epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
                  <response>
                    <result code="1500">
                      <msg>Logout successful</msg>
                    </result>
                    <trID>
                      <clTRID>ABC-12345</clTRID>
                      <svTRID>SRV-54321</svTRID>
                    </trID>
                  </response>
                </epp>';

                $length = strlen($eppLogoutResponse) + 4; // Total length including the 4-byte header
                $lengthData = pack('N', $length); // Pack the length into 4 bytes

                $conn->send($lengthData . $eppLogoutResponse);
                $conn->close();
                break;
            }
      
            case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:contact-1.0')->check):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactCheck($conn, $db, $xml);
                break;
            }
      
            case isset($xml->command->create) && isset($xml->command->create->children('urn:ietf:params:xml:ns:contact-1.0')->create):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactCreate($conn, $db, $xml);
                break;
            }
      
            case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:contact-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processContactInfo($conn, $db, $xml);
                break;
            }
        
            case isset($xml->command->check) && isset($xml->command->check->children('urn:ietf:params:xml:ns:domain-1.0')->check):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processDomainCheck($conn, $db, $xml);
                break;
            }

            case isset($xml->command->info) && isset($xml->command->info->children('urn:ietf:params:xml:ns:domain-1.0')->info):
            {
                $data = $table->get($connId);
                if (!$data || $data['logged_in'] !== 1) {
                    sendEppError($conn, 2202, 'Authorization error');
                    $conn->close();
                }
                processDomainInfo($conn, $db, $xml);
                break;
            }
      
            default:
            {
                sendEppError($conn, 2102, 'Unrecognized command');
                break;
            }
        }
    }

    sendEppError($conn, 2100, 'Unknown command');
    echo "Client disconnected.\n";
});

echo "Namingo EPP server started.\n";
Swoole\Coroutine::create(function () use ($server) {
    $server->start();
});

function processContactCheck($conn, $db, $xml) {
    $contactIDs = $xml->command->{'check'}->{'contact:check'}->{'contact:id'};

    $results = [];
    foreach ($contactIDs as $contactID) {
        $contactID = (string)$contactID;

        // Validation for contact ID
        if (!ctype_alnum($contactID) || strlen($contactID) > 255) {
            sendEppError($conn, 2005, 'Invalid contact ID');
            return;
        }

        $stmt = $db->prepare("SELECT 1 FROM contacts WHERE id = :id");
        $stmt->execute(['id' => $contactID]);

        $results[$contactID] = $stmt->fetch() ? '0' : '1'; // 0 if exists, 1 if not
    }

    $checkResults = '';
    foreach ($results as $id => $available) {
        $checkResults .= "<contact:cd><contact:id avail=\"$available\">$id</contact:id></contact:cd>";
    }

    $response = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="1000">
      <msg>Contact check completed</msg>
    </result>
    <resData>
      <contact:chkData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
        $checkResults
      </contact:chkData>
    </resData>
  </response>
</epp>
XML;

    $length = strlen($response) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes

    $conn->send($lengthData . $response);
}

function processContactInfo($conn, $db, $xml) {
    $contactID = (string) $xml->command->{'info'}->{'contact:info'}->{'contact:id'};

    // Validation for contact ID
    if (!ctype_alnum($contactID) || strlen($contactID) > 255) {
        sendEppError($conn, 2005, 'Invalid contact ID');
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = :id");
        $stmt->execute(['id' => $contactID]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            sendEppError($conn, 2303, 'Object does not exist');
            return;
        }

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="1000">
      <msg>Contact information retrieved successfully</msg>
    </result>
    <!-- Add contact details here -->
  </response>
</epp>
XML;

        $length = strlen($response) + 4; // Total length including the 4-byte header
        $lengthData = pack('N', $length); // Pack the length into 4 bytes

        $conn->send($lengthData . $response);

    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function processContactCreate($conn, $db, $xml) {
    if (!isset($xml->command->create->{'contact:create'})) {
        sendEppError($conn, 2005, 'Syntax error');
        return;
    }

    $contactCreate = $xml->command->create->{'contact:create'};
    $contactID = (string) $contactCreate->{'contact:id'};
    $postalInfo = $contactCreate->{'contact:postalInfo'};
    $email = (string) $contactCreate->{'contact:email'};
    $voice = (string) $contactCreate->{'contact:voice'};
    $fax = (string) $contactCreate->{'contact:fax'};
    $password = (string) $contactCreate->{'contact:authInfo'}->{'contact:pw'};

    $name = (string) $postalInfo->{'contact:name'};
    $org = (string) $postalInfo->{'contact:org'};
    $addr = $postalInfo->{'contact:addr'};
    $street = (string) $addr->{'contact:street'};
    $city = (string) $addr->{'contact:city'};
    $sp = (string) $addr->{'contact:sp'};
    $pc = (string) $addr->{'contact:pc'};
    $cc = (string) $addr->{'contact:cc'};

    try {
        $stmt = $db->prepare("INSERT INTO contacts (id, name, org, street, city, state_province, postal_code, country_code, email, voice, fax, password) VALUES (:id, :name, :org, :street, :city, :sp, :pc, :cc, :email, :voice, :fax, :password)");
        $stmt->execute([
            'id' => $contactID,
            'name' => $name,
            'org' => $org,
            'street' => $street,
            'city' => $city,
            'sp' => $sp,
            'pc' => $pc,
            'cc' => $cc,
            'email' => $email,
            'voice' => $voice,
            'fax' => $fax,
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ]);

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="1000">
      <msg>Contact created successfully</msg>
    </result>
  </response>
</epp>
XML;

        $length = strlen($response) + 4; // Total length including the 4-byte header
        $lengthData = pack('N', $length); // Pack the length into 4 bytes

        $conn->send($lengthData . $response);
    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function processDomainCheck($conn, $db, $xml) {
    $domains = $xml->command->{'check'}->{'domain:check'}->children('domain', true);
    $response = '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>Command completed successfully</msg></result><resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">';

    foreach ($domains as $domain) {
        $domainName = (string) $domain;
        $availability = $db->query("SELECT availability FROM domains WHERE domain_name = '$domainName'")->fetchColumn();
        $availString = $availability ? 'available' : 'unavailable';
        $response .= "<domain:cd><domain:name avail=\"$availability\">$domainName</domain:name></domain:cd>";
    }

    $response .= '</domain:chkData></resData></response></epp>';
    $length = strlen($response) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes

    $conn->send($lengthData . $response);
}

function processDomainInfo($conn, $db, $xml) {
    $domainName = (string) $xml->command->{'info'}->{'domain:info'}->{'domain:name'};

    // Validation for domain name
    if (!filter_var($domainName, FILTER_VALIDATE_DOMAIN)) {
        sendEppError($conn, 2005, 'Invalid domain name');
        return;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM domains WHERE name = :name");
        $stmt->execute(['name' => $domainName]);

        $domain = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$domain) {
            sendEppError($conn, 2303, 'Object does not exist');
            return;
        }

        $response = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="1000">
      <msg>Domain information retrieved successfully</msg>
    </result>
    <!-- Add domain details here -->
  </response>
</epp>
XML;

        // You can customize the response to include the specific details you want
        $conn->send($response);

    } catch (PDOException $e) {
        sendEppError($conn, 2400, 'Database error');
    }
}

function checkLogin($db, $clID, $pw) {
    $stmt = $db->prepare("SELECT pw FROM registrar WHERE clid = :username");
    $stmt->execute(['username' => $clID]);
    $hashedPassword = $stmt->fetchColumn();

    return password_verify($pw, $hashedPassword);
}

function sendGreeting($conn) {
  global $c;
    $currentDate = gmdate('Y-m-d\TH:i:s\Z');
    $greetingXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
    <greeting>
        <svID>{$c['epp_greeting']}</svID>
        <svDate>$currentDate</svDate>
        <svcMenu>
            <version>1.0</version>
            <lang>en</lang>
            <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
            <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
            <!-- Add other namespaces as supported -->
        </svcMenu>
        <!-- Optional: extensions you support -->
        <dcp>
            <access><all/></access>
            <statement>
                <purpose><admin/><prov/></purpose>
                <recipient><ours/><public/><same/></recipient>
                <retention><stated/></retention>
            </statement>
        </dcp>
    </greeting>
</epp>
XML;
    $length = strlen($greetingXml) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes
    $conn->send($lengthData . $greetingXml);
}

function sendEppError($conn, $code, $msg) {
    $errorResponse = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <response>
    <result code="$code">
      <msg>$msg</msg>
    </result>
  </response>
</epp>
XML;
    $length = strlen($errorResponse) + 4; // Total length including the 4-byte header
    $lengthData = pack('N', $length); // Pack the length into 4 bytes
    $conn->send($lengthData . $errorResponse);
}