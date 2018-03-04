<?php
/**
 * @author  PhileCMS
 * @link    https://philecms.com
 * @license http://opensource.org/licenses/MIT
 */

namespace Phile;

use Phile\Core\Config;
use Phile\Core\Container;
use Phile\Core\Event;
use Phile\Core\RequestHandler;
use Phile\Core\Response;
use Phile\Core\Router;
use Phile\Model\Page;
use Phile\Repository\Page as Repository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Phile Core class
 */
class Phile implements MiddlewareInterface
{
    /** @var Config Phile configuration */
    protected $config;

    /** @var Event event-bus */
    protected $eventBus;

    /** @var array callbacks run at bootstrap */
    protected $bootstrapConfigs = [];

    /** @var array callbacks run on middleware-setup */
    protected $middlewareConfigs = [];

    /**
     * Constructor sets-up base Phile environment
     *
     * @param Event $eventBus
     * @param Config $config
     */
    public function __construct(Event $eventBus, Config $config)
    {
        $this->eventBus = $eventBus;
        $this->config = $config;
    }

    /**
     * Adds bootstrap-setup
     */
    public function addBootstrap(callable $bootstrap): self
    {
        $this->bootstrapConfigs[] = $bootstrap;
        return $this;
    }

    /**
     * Adds middleware-setup
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middlewareConfigs[] = $middleware;
        return $this;
    }

    /**
     * Performs bootstrap
     */
    public function bootstrap(): self
    {
        foreach ($this->bootstrapConfigs as $config) {
            call_user_func_array($config, [$this->eventBus, $this->config]);
        }
        return $this;
    }

    /**
     * Run a request through Phile and create a response
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->bootstrap();
        $this->config->lock();

        $requestHandler = new RequestHandler(new Response);
        foreach ($this->middlewareConfigs as $config) {
            call_user_func_array($config, [$requestHandler, $this->eventBus, $this->config]);
        }

        return $requestHandler->handle($request);
    }

    /**
     * Implements PSR-15 middle-ware process-handler
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $router = new Router($request->getServerParams());
        Container::getInstance()->set('Phile_Router', $router);

        // BC: send response in after_init_core event
        $response = new Response;
        $response->setCharset($this->config->get('charset'));
        $this->eventBus->trigger('after_init_core', ['response' => &$response]);
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $page = $this->resolveCurrentPage($router);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $html = $this->renderHtml($page);
        if ($html instanceof ResponseInterface) {
            return $html;
        }

        $charset = $this->config->get('charset');
        $response = (new Response)->createHtmlResponse($html)
            ->withHeader('Content-Type', 'text/html; charset=' . $charset);

        if ($page->getPageId() == $this->config->get('not_found_page')) {
            $response = $response->withStatus(404);
        }

        return $response;
    }

    /**
     * Resolves request into the current page
     */
    protected function resolveCurrentPage(Router $router)
    {
        $pageId = $router->getCurrentUrl();
        $response = null;
        $this->eventBus->trigger(
            'request_uri',
            ['uri' => $pageId, 'response' => &$response]
        );
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $repository = new Repository();
        $page = $repository->findByPath($pageId);
        $found = $page instanceof Page;

        if ($found && $pageId !== $page->getPageId()) {
            $url = $router->urlForPage($page->getPageId());
            return (new Response)->createRedirectResponse($url, 301);
        }

        if (!$found) {
            $page = $repository->findByPath($this->config->get('not_found_page'));
            $this->eventBus->trigger('after_404');
        }

        $this->eventBus->trigger(
            'after_resolve_page',
            ['pageId' => $pageId, 'page' => &$page, 'response' => &$response]
        );
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        return $page;
    }

    /**
     * Renders page into output format (HTML)
     */
    protected function renderHtml(Page $page)
    {
        $this->eventBus->trigger('before_init_template');
        $engine = ServiceLocator::getService('Phile_Template');

        $coreVars = $this->config->getTemplateVars();
        $templateVars = Registry::get('templateVars') + $coreVars;
        Registry::set('templateVars', $templateVars);

        $response = null;
        $this->eventBus->trigger(
            'before_render_template',
            ['templateEngine' => &$engine, 'response' => &$response]
        );
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $engine->setCurrentPage($page);
        $html = $engine->render();

        $this->eventBus->trigger(
            'after_render_template',
            ['templateEngine' => &$engine, 'output' => &$html]
        );

        return $html;
    }
}
