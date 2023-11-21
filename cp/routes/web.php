<?php
use App\Controllers\Auth\AuthController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\HomeController;
use App\Controllers\DomainsController;
use App\Controllers\ContactsController;
use App\Controllers\HostsController;
use App\Controllers\LogsController;
use App\Controllers\RegistrarsController;
use App\Controllers\UsersController;
use App\Controllers\FinancialsController;
use App\Controllers\ReportsController;
use App\Controllers\ProfileController;
use App\Controllers\SystemController;
use App\Controllers\SupportController;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use Slim\Exception\HttpNotFoundException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;

$app->get('/', HomeController::class .':index')->setName('index');

$app->group('', function ($route) {
    $route->get('/register', AuthController::class . ':createRegister')->setName('register');
    $route->post('/register', AuthController::class . ':register');
    $route->get('/login', AuthController::class . ':createLogin')->setName('login');
    $route->post('/login', AuthController::class . ':login');

    $route->get('/verify-email', AuthController::class.':verifyEmail')->setName('verify.email');
    $route->get('/verify-email-resend',AuthController::class.':verifyEmailResend')->setName('verify.email.resend');

    $route->get('/forgot-password', PasswordController::class . ':createForgotPassword')->setName('forgot.password');
    $route->post('/forgot-password', PasswordController::class . ':forgotPassword');
    $route->get('/reset-password', PasswordController::class.':resetPassword')->setName('reset.password');
    $route->get('/update-password', PasswordController::class.':createUpdatePassword')->setName('update.password');
    $route->post('/update-password', PasswordController::class.':updatePassword');
})->add(new GuestMiddleware($container));

$app->group('', function ($route) {
    $route->get('/dashboard', HomeController::class .':dashboard')->setName('home');

    $route->get('/domains', DomainsController::class .':view')->setName('domains');
    $route->map(['GET', 'POST'], '/domain/check', DomainsController::class . ':check')->setName('domaincheck');
    $route->map(['GET', 'POST'], '/domain/create', DomainsController::class . ':create')->setName('domaincreate');
    $route->get('/domain/view/{domain}', DomainsController::class . ':viewDomain')->setName('viewDomain');
    $route->map(['GET', 'POST'], '/transfers', DomainsController::class . ':transfers')->setName('transfers');

    $route->get('/contacts', ContactsController::class .':view')->setName('contacts');
    $route->map(['GET', 'POST'], '/contact/create', ContactsController::class . ':create')->setName('contactcreate');
    $route->get('/contact/view/{contact}', ContactsController::class . ':viewContact')->setName('viewContact');
    
    $route->get('/hosts', HostsController::class .':view')->setName('hosts');
    $route->map(['GET', 'POST'], '/host/create', HostsController::class . ':create')->setName('hostcreate');
    $route->get('/host/view/{host}', HostsController::class . ':viewHost')->setName('viewHost');

    $route->get('/registrars', RegistrarsController::class .':view')->setName('registrars');
    $route->map(['GET', 'POST'], '/registrar/create', RegistrarsController::class . ':create')->setName('registrarcreate');
    $route->get('/registrar/view/{registrar}', RegistrarsController::class . ':viewRegistrar')->setName('viewRegistrar');
    
    $route->get('/users', UsersController::class .':view')->setName('users');
    
    $route->get('/epphistory', LogsController::class .':view')->setName('epphistory');
    $route->get('/poll', LogsController::class .':poll')->setName('poll');
    $route->get('/log', LogsController::class .':log')->setName('log');
    $route->get('/reports', ReportsController::class .':view')->setName('reports');
    
    $route->get('/invoices', FinancialsController::class .':invoices')->setName('invoices');
    $route->map(['GET', 'POST'], '/deposit', FinancialsController::class .':deposit')->setName('deposit');
    $route->map(['GET', 'POST'], '/create-payment', FinancialsController::class .':createPayment')->setName('createPayment');
    $route->map(['GET', 'POST'], '/payment-success', FinancialsController::class .':success')->setName('success');
    $route->map(['GET', 'POST'], '/payment-cancel', FinancialsController::class .':cancel')->setName('cancel');
    $route->get('/transactions', FinancialsController::class .':transactions')->setName('transactions');
    $route->get('/overview', FinancialsController::class .':overview')->setName('overview');
    
    $route->get('/registry', SystemController::class .':registry')->setName('registry');

    $route->get('/support', SupportController::class .':view')->setName('ticketview');
    $route->map(['GET', 'POST'], '/support/new', SupportController::class .':newticket')->setName('newticket');
    $route->get('/support/docs', SupportController::class .':docs')->setName('docs');
    $route->get('/support/media', SupportController::class .':mediakit')->setName('mediakit');

    $route->get('/profile', ProfileController::class .':profile')->setName('profile');
    $route->post('/profile/2fa', ProfileController::class .':activate2fa')->setName('activate2fa');
    $route->get('/webauthn/register/challenge', ProfileController::class . ':getRegistrationChallenge')->setName('webauthn.register.challenge');
    $route->post('/webauthn/register/verify', ProfileController::class . ':verifyRegistration')->setName('webauthn.register.verify');

    $route->get('/mode', HomeController::class .':mode')->setName('mode');
    $route->get('/lang', HomeController::class .':lang')->setName('lang');
    $route->get('/avatar', HomeController::class .':avatar')->setName('avatar');
    $route->get('/logout', AuthController::class . ':logout')->setName('logout');
    $route->post('/change-password', PasswordController::class . ':changePassword')->setName('change.password');
})->add(new AuthMiddleware($container));

$app->any('/api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    $config = new Config([
        'username' => $db['mysql']['username'],
        'password' => $db['mysql']['password'],
        'database' => $db['mysql']['database'],
        'basePath' => '/api',
        'middlewares' => 'customization,dbAuth,authorization,sanitation,multiTenancy',
        'authorization.tableHandler' => function ($operation, $tableName) {
        $restrictedTables = ['contact_authInfo', 'contact_postalInfo', 'domain_authInfo', 'secdns'];
            return !in_array($tableName, $restrictedTables);
        },
        'authorization.columnHandler' => function ($operation, $tableName, $columnName) {
            if ($tableName == 'registrar' && $columnName == 'pw') {
                return false;
            }
            if ($tableName == 'users' && $columnName == 'password') {
                return false;
            }
            return true;
        },
        'sanitation.handler' => function ($operation, $tableName, $column, $value) {
            return is_string($value) ? strip_tags($value) : $value;
        },
        'customization.beforeHandler' => function ($operation, $tableName, $request, $environment) {
            if (!isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            $_SESSION['user'] = $_SESSION['auth_username'];
        },
        'dbAuth.usersTable' => 'users',
        'dbAuth.usernameColumn' => 'email',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.returnedColumns' => 'email,roles_mask',
        'dbAuth.registerUser' => false,
        'multiTenancy.handler' => function ($operation, $tableName) {   
            if (isset($_SESSION['auth_roles']) && $_SESSION['auth_roles'] === 0) {
                return [];
            }
            $registrarId = $_SESSION['auth_registrar_id'];

            $columnMap = [
                'contact' => 'clid',
                'domain' => 'clid',
                'host' => 'clid',
                'poll' => 'registrar_id',
                'registrar' => 'id',
                'payment_history' => 'registrar_id',
                'statement' => 'registrar_id',
                'support_tickets' => 'user_id',  // Note: this still uses user_id
            ];

            if (array_key_exists($tableName, $columnMap)) {
                // Use registrarId for tables where 'registrar_id' is the filter
                // For 'support_tickets', continue to use userId
                return [$columnMap[$tableName] => ($tableName === 'support_tickets' ? $_SESSION['auth_user_id'] : $registrarId)];
            }

            return ['1' => '0'];
        },
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->any('/log-api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    $config = new Config([
        'username' => $db['mysql']['username'],
        'password' => $db['mysql']['password'],
        'database' => 'registryTransaction',
        'basePath' => '/log-api',
        'middlewares' => 'customization,dbAuth,multiTenancy',
        'customization.beforeHandler' => function ($operation, $tableName, $request, $environment) {
            if (!isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            $_SESSION['user'] = $_SESSION['auth_username'];
        },
        'dbAuth.usersTable' => 'users',
        'dbAuth.usernameColumn' => 'email',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.returnedColumns' => 'email,roles_mask',
        'dbAuth.registerUser' => false,
        'multiTenancy.handler' => function ($operation, $tableName) {    
            if (isset($_SESSION['auth_roles']) && $_SESSION['auth_roles'] === 0) {
                return [];
            }
            $registrarId = $_SESSION['auth_registrar_id'];
            $columnMap = [
                'transaction_identifier' => 'registrar_id',
            ];

            if (array_key_exists($tableName, $columnMap)) {
                return [$columnMap[$tableName] => $registrarId];
            }

            return ['1' => '0'];
        },
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->add(function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Server\RequestHandlerInterface $handler) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException $e) {
        $responseFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $response = $responseFactory->createResponse();
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
});

$app->addErrorMiddleware(true, true, true);