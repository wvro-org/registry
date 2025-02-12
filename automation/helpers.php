<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Ds\Map;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Money\Money;
use Money\Currency;
use Money\Converter;
use Money\Currencies\ISOCurrencies;
use Money\Exchange\FixedExchange;

/**
 * Sets up and returns a Logger instance.
 * 
 * @param string $logFilePath Full path to the log file.
 * @param string $channelName Name of the log channel (optional).
 * @return Logger
 */
function setupLogger($logFilePath, $channelName = 'app') {
    // Create a log channel
    $log = new Logger($channelName);

    // Set up the console handler
    $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $consoleFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u", // Date format
        true, // Allow inline line breaks
        true  // Ignore empty context and extra
    );
    $consoleHandler->setFormatter($consoleFormatter);
    $log->pushHandler($consoleHandler);

    // Set up the file handler
    $fileHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
    $fileFormatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        "Y-m-d H:i:s.u" // Date format
    );
    $fileHandler->setFormatter($fileFormatter);
    $log->pushHandler($fileHandler);

    return $log;
}

function fetchCount($pdo, $tableName) {
    // Calculate the end of the previous day
    $endOfPreviousDay = date('Y-m-d 23:59:59', strtotime('-1 day'));

    // Prepare the SQL query
    $query = "SELECT COUNT(id) AS count FROM {$tableName} WHERE crdate <= :endOfPreviousDay";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':endOfPreviousDay', $endOfPreviousDay);
    $stmt->execute();

    // Fetch and return the count
    $result = $stmt->fetch();
    return $result['count'];
}

// Function to check domain against Spamhaus SBL
function checkSpamhaus($domain) {
    // Append '.sbl.spamhaus.org' to the domain
    $queryDomain = $domain . '.sbl.spamhaus.org';

    // Check if the domain is listed in the SBL
    return checkdnsrr($queryDomain, "A");
}

function getUrlhausData($cache, $cacheKey, $urlhausUrl) {
    // Check if data is cached
    $cachedFile = $cache->getItem($cacheKey);

    if (!$cachedFile->isHit()) {
        // Data is not cached, download it
        $httpClient = new Client();
        $response = $httpClient->get($urlhausUrl);
        $fileContent = $response->getBody()->getContents();

        // Cache the file content
        $cachedFile->set($fileContent);
        $cachedFile->expiresAfter(86400 * 7); // Cache for 7 days
        $cache->save($cachedFile);

        return processUrlhausData($fileContent);
    } else {
        // Retrieve data from cache
        $fileContent = $cachedFile->get();
        return processUrlhausData($fileContent);
    }
}

function processUrlhausData($data) {
    $map = new \Ds\Map();

    foreach ($data as $entry) {
        foreach ($entry as $urlData) {
            $domain = parse_url($urlData['url'], PHP_URL_HOST); // Extract domain from URL
            $map->put($domain, $urlData); // Store data against domain
        }
    }

    return $map;
}

function checkUrlhaus($domain, Map $urlhausData) {
    return $urlhausData->get($domain, false);
}

function processAbuseDetection($pdo, $domain, $clid, $abuseType, $evidenceLink, $log) {
    $userStmt = $pdo->prepare('SELECT user_id FROM registrar_users WHERE registrar_id = ?');
    $userStmt->execute([$clid]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($userData) {
        // Prepare INSERT statement to add a ticket
        $insertStmt = $pdo->prepare('INSERT INTO support_tickets (id, user_id, category_id, subject, message, status, priority, reported_domain, nature_of_abuse, evidence, relevant_urls, date_of_incident, date_created, last_updated) VALUES (NULL, ?, 8, ?, ?, "Open", "High", ?, "Abuse", ?, ?, ?, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))');

        // Execute the prepared statement with appropriate values
        $insertStmt->execute([
            $userData['user_id'], // user_id
            "Abuse Report for $domain ($abuseType)", // subject
            "Abuse detected for domain $domain via $abuseType.", // message
            $domain, // reported_domain
            "Link to $abuseType", // evidence
            $evidenceLink, // relevant_urls
            date('Y-m-d H:i:s') // date_of_incident
        ]);

        $log->info("Abuse detected for domain $domain using $abuseType.");
    }
}

function getDomainPrice($pdo, $domain_name, $tld_id, $date_add = 12, $command = 'create', $registrar_id = null, $currency = 'USD') {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    $cacheKey = "domain_price_{$domain_name}_{$tld_id}_{$date_add}_{$command}_{$registrar_id}_{$currency}";

    // Try fetching from cache
    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        return json_decode($cached, true); // Redis stores as string, so decode
    }

    $exchangeRates = getExchangeRates();
    $baseCurrency = $exchangeRates['base_currency'] ?? 'USD';
    $exchangeRate = $exchangeRates['rates'][$currency] ?? 1.0;

    // Check for premium pricing
    $premiumPrice = $redis->get("premium_price_{$domain_name}_{$tld_id}") ?: fetchSingleValue(
        $pdo,
        'SELECT c.category_price 
         FROM premium_domain_pricing p
         JOIN premium_domain_categories c ON p.category_id = c.category_id
         WHERE p.domain_name = ? AND p.tld_id = ?',
        [$domain_name, $tld_id]
    );

    if (!is_null($premiumPrice) && $premiumPrice !== false) {
        $money = convertMoney(new Money((int) ($premiumPrice * 100), new Currency($baseCurrency)), $exchangeRate, $currency);
        $result = ['type' => 'premium', 'price' => formatMoney($money)];

        $redis->setex($cacheKey, 1800, json_encode($result));
        return $result;
    }

    // Check for active promotions
    $currentDate = date('Y-m-d');
    $promo = json_decode($redis->get("promo_{$tld_id}"), true) ?: fetchSingleRow(
        $pdo,
        "SELECT discount_percentage, discount_amount 
         FROM promotion_pricing 
         WHERE tld_id = ? 
         AND promo_type = 'full' 
         AND status = 'active' 
         AND start_date <= ? 
         AND end_date >= ?",
        [$tld_id, $currentDate, $currentDate]
    );

    if ($promo) {
        $redis->setex("promo_{$tld_id}", 3600, json_encode($promo));
    }

    // Get regular price from DB
    $priceColumn = "m" . (int) $date_add;
    $regularPrice = json_decode($redis->get("regular_price_{$tld_id}_{$command}_{$registrar_id}"), true) ?: fetchSingleValue(
        $pdo,
        "SELECT $priceColumn 
         FROM domain_price 
         WHERE tldid = ? AND command = ? 
         AND (registrar_id = ? OR registrar_id IS NULL) 
         ORDER BY registrar_id DESC LIMIT 1",
        [$tld_id, $command, $registrar_id]
    );

    if (!is_null($regularPrice) && $regularPrice !== false) {
        $redis->setex("regular_price_{$tld_id}_{$command}_{$registrar_id}", 1800, json_encode($regularPrice));

        $finalPrice = $regularPrice * 100; // Convert DB float to cents
        if ($promo) {
            if (!empty($promo['discount_percentage'])) {
                $discountAmount = (int) ($finalPrice * ($promo['discount_percentage'] / 100));
            } else {
                $discountAmount = (int) ($promo['discount_amount'] * 100);
            }
            $finalPrice = max(0, $finalPrice - $discountAmount);
            $type = 'promotion';
        } else {
            $type = 'regular';
        }

        $money = convertMoney(new Money($finalPrice, new Currency($baseCurrency)), $exchangeRate, $currency);
        $result = ['type' => $type, 'price' => formatMoney($money)];

        $redis->setex($cacheKey, 1800, json_encode($result));
        return $result;
    }

    return ['type' => 'not_found', 'price' => formatMoney(new Money(0, new Currency($currency)))];
}

/**
 * Load exchange rates from JSON file with APCu caching.
 */
function getExchangeRates() {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    $cacheKey = 'exchange_rates';

    $cached = $redis->get($cacheKey);
    if ($cached !== false) {
        return json_decode($cached, true);
    }

    $filePath = "/var/www/cp/resources/exchange_rates.json";
    $defaultRates = [
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0  // Ensure USD always exists
        ],
        'last_updated' => date('c') // ISO 8601 timestamp
    ];

    if (!file_exists($filePath) || !is_readable($filePath)) {
        $redis->setex($cacheKey, 3600, json_encode($defaultRates)); // Cache for 1 hour
        return $defaultRates;
    }

    $json = file_get_contents($filePath);
    $data = json_decode($json, true);

    if (!isset($data['base_currency'], $data['rates']) || !is_array($data['rates'])) {
        $redis->setex($cacheKey, 3600, json_encode($defaultRates)); // Cache for 1 hour
        return $defaultRates;
    }

    // Ensure base currency exists
    if (!isset($data['rates'][$data['base_currency']])) {
        $data['rates'][$data['base_currency']] = 1.0;
    }

    // Ensure every currency defaults to 1.0 if missing
    foreach ($data['rates'] as $currency => $rate) {
        if (!is_numeric($rate)) {
            $data['rates'][$currency] = 1.0;
        }
    }

    $redis->setex($cacheKey, 3600, json_encode($data)); // Cache for 1 hour

    return $data;
}

/**
 * Convert MoneyPHP object to the target currency.
 */
function convertMoney(Money $amount, float $exchangeRate, string $currency) {
    $currencies = new ISOCurrencies();
    $exchange = new FixedExchange([
        $amount->getCurrency()->getCode() => [
            $currency => (string) $exchangeRate  // Convert float to string
        ]
    ]);
    $converter = new Converter($currencies, $exchange);

    return $converter->convert($amount, new Currency($currency));
}

/**
 * Format Money object back to a string (e.g., "10.00").
 */
function formatMoney(Money $money) {
    return number_format($money->getAmount() / 100, 2, '.', '');
}

/**
 * Fetch a single value from the database using PDO.
 */
function fetchSingleValue($pdo, string $query, array $params) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * Fetch a single row from the database using PDO.
 */
function fetchSingleRow($pdo, string $query, array $params) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function generateAuthInfo(): string {
    $length = 16;
    $charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $retVal = "";
    $digitCount = 0;

    // Generate initial random string
    for ($i = 0; $i < $length; $i++) {
        $randomIndex = random_int(0, strlen($charset) - 1);
        $char = $charset[$randomIndex];
        $retVal .= $char;
        if ($char >= '0' && $char <= '9') {
            $digitCount++;
        }
    }

    // Ensure there are at least two digits in the string
    while ($digitCount < 2) {
        // Replace a non-digit character at a random position with a digit
        $replacePosition = random_int(0, $length - 1);
        if (!($retVal[$replacePosition] >= '0' && $retVal[$replacePosition] <= '9')) {
            $randomDigit = random_int(0, 9); // Generate a digit from 0 to 9
            $retVal = substr_replace($retVal, (string)$randomDigit, $replacePosition, 1);
            $digitCount++;
        }
    }

    return $retVal;
}

// Function to fetch and cache URLAbuse data
function getUrlAbuseData($cache, $cacheKey, $fileUrl) {
    // Check if data is cached
    $cachedFile = $cache->getItem($cacheKey);

    if (!$cachedFile->isHit()) {
        // Data is not cached, download it
        $httpClient = new Client();
        $response = $httpClient->get($fileUrl);
        $fileContent = $response->getBody()->getContents();

        // Cache the file content
        $cachedFile->set($fileContent);
        $cachedFile->expiresAfter(300); // Cache for 5 minutes
        $cache->save($cachedFile);

        return processUrlAbuseData($fileContent);
    } else {
        // Retrieve data from cache
        $fileContent = $cachedFile->get();
        return processUrlAbuseData($fileContent);
    }
}

// Function to process URLAbuse data
function processUrlAbuseData($fileContent) {
    $lines = explode("\n", $fileContent);
    $map = new \Ds\Map();

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse JSON data from each line
        $entry = json_decode($line, true);
        if ($entry && isset($entry['url'])) {
            $domain = parse_url($entry['url'], PHP_URL_HOST); // Extract domain from URL
            $map->put($domain, $entry); // Store data against domain
        }
    }

    return $map;
}

// Function to check if a domain is listed in URLAbuse
function checkUrlAbuse($domain, Map $urlAbuseData) {
    return $urlAbuseData->get($domain, false);
}

function generateSerial($soa_type = null) {
    // Default to Type 1 if $soa_type is not set, null, or invalid
    $soa_type = $soa_type ?? 1;

    switch ($soa_type) {
        case 2: // Date-based, updates every 15 minutes
            $hour = (int) date('H');
            $segment = (int)(date('i') / 15); // 0 through 3
            $offset = $hour * 4 + $segment;   // Converts hour + quarter into a unique number
            return date('Ymd') . str_pad($offset, 2, '0', STR_PAD_LEFT);

        case 3: // Cloudflare-like serial
            $referenceTimestamp = strtotime("2020-11-01 00:00:00"); // Reference point
            $timeDifference = time() - $referenceTimestamp; // Difference in seconds
            $serial = $timeDifference + 2350000000; // Offset to ensure longer serials
            return $serial;

        case 1: // Fixed-length, second-based serial (default)
        default:
            return time();
    }
}