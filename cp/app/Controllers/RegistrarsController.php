<?php

namespace App\Controllers;

use App\Models\RegistryTransaction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;

class RegistrarsController extends Controller
{
    public function view(Request $request, Response $response)
    {
        return view($response,'admin/registrars/index.twig');
    }
}