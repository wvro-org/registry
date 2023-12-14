<?php
/**
 * Helper functions
 * @author    Hezekiah O. <support@hezecom.com>
 */

use Pinga\Auth\Auth;
use Pdp\Domain;
use Pdp\TopLevelDomains;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use MatthiasMullie\Scrapbook\Adapters\Flysystem as ScrapbookFlysystem;
use MatthiasMullie\Scrapbook\Psr6\Pool;

/**
 * @return mixed|string|string[]
 */
function routePath() {
    if (isset($_SERVER['REQUEST_URI'])) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $uri = (string) parse_url('http://a' . $_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (stripos($uri, $_SERVER['SCRIPT_NAME']) === 0) {
            return $_SERVER['SCRIPT_NAME'];
        }
        if ($scriptDir !== '/' && stripos($uri, $scriptDir) === 0) {
            return $scriptDir;
        }
    }
    return '';
}

/**
 * @param $key
 * @param null $default
 * @return mixed|null
 */
function config($key, $default=null){
    return \App\Lib\Config::get($key, $default);
}
/**
 * @param $var
 * @return mixed
 */
function envi($var, $default=null)
{
    if(isset($_ENV[$var])){
        return $_ENV[$var];
    }
    return $default;
}

/**
 * Start session
 */
function startSession(){
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * @param $var
 * @return mixed
 */
function session($var){
    if (isset($_SESSION[$var])) {
        return $_SESSION[$var];
    }
}

/**
 * Global PDO connection
 * @return \DI\|mixed|PDO
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function pdo(){
    global $container;
    return $container->get('pdo');

}
/**
 * @return Auth
 */
function auth(){
    $db = pdo();
    $auth = new Auth($db);
    return $auth;
}

/**
 * @param $name
 * @param array $params1
 * @param array $params2
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function route($name, $params1 =[], $params2=[]){
    global $container;
    return $container->get('router')->urlFor($name,$params1,$params2);

}

/**
 * @param string $dir
 * @return string
 */
function baseUrl(){
    $root = "";
    $root .= !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $root .= '://' . $_SERVER['HTTP_HOST'];
    return $root;
}

/**
 * @param string|null $name
 * @return string
 */
function url($url=null, $params1 =[], $params2=[]){
    if($url){
        return baseUrl().route($url,$params1,$params2);
    }
    return baseUrl();
}

/**
 * @param $resp
 * @param $page
 * @param array $arr
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function view($resp, $page, $arr=[]){
    global $container;
    return $container->get('view')->render($resp, $page, $arr);
}

/**
 * @param $type
 * @param $message
 * @return mixed
 * @throws \DI\DependencyException
 * @throws \DI\NotFoundException
 */
function flash($type, $message){
    global $container;
    return $container->get('flash')->addMessage($type, $message);
}

/**
 * @return \App\Lib\Redirect
 */
function redirect()
{
    return new \App\Lib\Redirect();
}

/**
 * @param $location
 * @return string
 */
function assets($location){
    return url().dirname($_SERVER["REQUEST_URI"]).'/'.$location;
}

/**
 * @param $data
 * @return mixed
 */
function toArray($data){
    return json_decode(json_encode($data), true);
}

function validate_identifier($identifier) {
    if (!$identifier) {
        return 'Please provide a contact ID';
    }

    $length = strlen($identifier);

    if ($length < 3) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    if ($length > 16) {
        return 'Identifier type minLength value=3, maxLength value=16';
    }

    $pattern1 = '/^[A-Z]+\-[0-9]+$/';
    $pattern2 = '/^[A-Za-z][A-Z0-9a-z]*$/';

    if (!preg_match($pattern1, $identifier) && !preg_match($pattern2, $identifier)) {
        return 'The ID of the contact must contain letters (A-Z) (ASCII), hyphen (-), and digits (0-9).';
    }
}

function validate_label($label, $db) {
    if (!$label) {
        return 'You must enter a domain name';
    }
    if (strlen($label) > 63) {
        return 'Total lenght of your domain must be less then 63 characters';
    }
    if (strlen($label) < 2) {
        return 'Total lenght of your domain must be greater then 2 characters';
    }
    if (strpos($label, 'xn--') === false && preg_match("/(^-|^\.|-\.|\.-|--|\.\.|-$|\.$)/", $label)) {
        return 'Invalid domain name format, cannot begin or end with a hyphen (-)';
    }
    
    // Extract TLD from the domain and prepend a dot
    $parts = extractDomainAndTLD($label);
    $tld = "." . $parts['tld'];

    // Check if the TLD exists in the domain_tld table
    $tldExists = $db->select('SELECT COUNT(*) FROM domain_tld WHERE tld = ?', [$tld]);

    if ($tldExists[0]["COUNT(*)"] == 0) {
        return 'Zone is not supported';
    }

    // Fetch the IDN regex for the given TLD
    $idnRegex = $db->selectRow('SELECT idn_table FROM domain_tld WHERE tld = ?', [$tld]);

    if (!$idnRegex) {
        return 'Failed to fetch domain IDN table';
    }

    // Check for invalid characters using fetched regex
    if (!preg_match($idnRegex['idn_table'], $label)) {
        return 'Invalid domain name format, please review registry policy about accepted labels';
    }
}

function normalize_v4_address($v4) {
    // Remove leading zeros from the first octet
    $v4 = preg_replace('/^0+(\d)/', '$1', $v4);
    
    // Remove leading zeros from successive octets
    $v4 = preg_replace('/\.0+(\d)/', '.$1', $v4);

    return $v4;
}

function normalize_v6_address($v6) {
    // Upper case any alphabetics
    $v6 = strtoupper($v6);
    
    // Remove leading zeros from the first word
    $v6 = preg_replace('/^0+([\dA-F])/', '$1', $v6);
    
    // Remove leading zeros from successive words
    $v6 = preg_replace('/:0+([\dA-F])/', ':$1', $v6);
    
    // Introduce a :: if there isn't one already
    if (strpos($v6, '::') === false) {
        $v6 = preg_replace('/:0:0:/', '::', $v6);
    }

    // Remove initial zero word before a ::
    $v6 = preg_replace('/^0+::/', '::', $v6);
    
    // Remove other zero words before a ::
    $v6 = preg_replace('/(:0)+::/', '::', $v6);

    // Remove zero words following a ::
    $v6 = preg_replace('/:(:0)+/', ':', $v6);

    return $v6;
}

function extractDomainAndTLD($urlString) {
    $cachePath = __DIR__ . '/../cache'; // Cache directory
    $adapter = new LocalFilesystemAdapter($cachePath, null, LOCK_EX);
    $filesystem = new Filesystem($adapter);
    $cache = new Pool(new ScrapbookFlysystem($filesystem));
    $cacheKey = 'tlds_alpha_by_domain';
    $cachedFile = $cache->getItem($cacheKey);
    $fileContent = $cachedFile->get();

    // Define a list of fake TLDs used in your QA environment
    $fakeTlds = ['test', 'xx'];

    // Parse the URL to get the host
    $parts = parse_url($urlString);
    $host = $parts['host'] ?? $urlString;

    // Check if the TLD is a known fake TLD
    foreach ($fakeTlds as $fakeTld) {
        if (str_ends_with($host, ".$fakeTld")) {
            // Handle the fake TLD case
            $hostParts = explode('.', $host);
            $tld = array_pop($hostParts);
            $sld = array_pop($hostParts);
            return ['domain' => $sld, 'tld' => $tld];
        }
    }

    // Use the PHP Domain Parser library for real TLDs
    $tlds = TopLevelDomains::fromString($fileContent);
    $domain = Domain::fromIDNA2008($host);
    $result = $tlds->resolve($domain);
    $sld = $result->secondLevelDomain()->toString();
    $tld = $result->suffix()->toString();

    return ['domain' => $sld, 'tld' => $tld];
}