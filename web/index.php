<?php
require __DIR__ . '/../vendor/autoload.php';

use Pimple\Container;
use Karen\Controller;
use Karen\Templatable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\RequestInterface as Request;
use Aura\Router\RouterContainer;
use Relay\RelayBuilder;

container: {
	// You have to define request, response, controller.
	// you can change them to your favorite PSR-7 objects.
    $c = new Container();
    $c['request'] = Zend\Diactoros\ServerRequestFactory::fromGlobals();
    $c['response'] = new Zend\Diactoros\Response();
    $c['template'] = function($c) {
        $loader = new \Twig_Loader_Filesystem( __DIR__ . '/../templates');
        return new \Twig_Environment($loader, array(
            'cache' => '/tmp/',
        ));
    };
    $c['controller'] = function($c) {
        $controller = new class($c['request'], $c['response']) extends Controller{
                use Templatable;
            };
        $controller->setTemplate($c['template']);

        return $controller;
    };
}

middleware: {
    // write your middleware
    $queue['echoStatus'] = function (Request $request, Response $response, callable $next) {
        $response = $next($request, $response);

        $status = $request->getQueryParams()['status']?? 200;
        $response = $response->withStatus((int)$status);

        return $response;
    };
    // apply middleware
    $relayBuilder = new RelayBuilder();
    $relay = $relayBuilder->newInstance($queue);
    $c['response'] = $relay($c['request'], $c['response']);
}

routes: {
    // set router and controller

    $routerContainer = new RouterContainer();
    $map = $routerContainer->getMap();

    // hello name controller sample.
    $map->get('hello', '/hello/{name}', function($args, $controller) {
        $name = $args['name']?? 'karen';
        return $controller->render('Hello, ' . $name);
    })->tokens(['name' => '.*']);

    // with twig
    $map->get('render_with_twig', '/template/{name}', function($args, $controller) {
        return $controller->renderWithT('demo.html', ['name' => $args['name']]);
    });
}

response: {
    $matcher = $routerContainer->getMatcher();
    $route = $matcher->match($c['request']);
    if ($route) {
        // parse args
        $args = [];
        foreach ((array)$route->attributes as $key => $val) {
            $args[$key] = $val;
        }
        // run controller
        $callable = $route->handler;
        $response = $c['controller']($callable, $args);
    } else {
        $response =$c['response']->withStatus(404);
        $response->getBody()->write('not found');
    }
}

run: {
    Controller::sendResponse($response);
}
