<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Framework;

use Contao\Config;
use Contao\CoreBundle\Exception\IncompleteInstallationException;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Fixtures\Adapter\LegacyClass;
use Contao\CoreBundle\Fixtures\Adapter\LegacySingletonClass;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Session\Attribute\ArrayAttributeBag;
use Contao\CoreBundle\Session\LazySessionAccess;
use Contao\CoreBundle\Session\MockNativeSessionStorage;
use Contao\CoreBundle\Tests\TestCase;
use Contao\RequestToken;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class ContaoFrameworkTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkWithAFrontEndRequest(): void
    {
        $beBag = new ArrayAttributeBag();
        $beBag->setName('contao_backend');

        $feBag = new ArrayAttributeBag();
        $feBag->setName('contao_frontend');

        $session = new Session(new MockNativeSessionStorage());
        $session->registerBag($beBag);
        $session->registerBag($feBag);
        $session->start();

        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_scope', 'frontend');
        $request->setSession($session);

        $framework = $this->mockFramework($this->mockRouter('/index.html'), $request);
        $framework->setContainer($this->mockContainer());
        $framework->initialize();

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertTrue(\defined('TL_REFERER_ID'));
        $this->assertTrue(\defined('TL_SCRIPT'));
        $this->assertFalse(\defined('BE_USER_LOGGED_IN'));
        $this->assertFalse(\defined('FE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('TL_PATH'));
        $this->assertSame('FE', TL_MODE);
        $this->assertSame($this->getTempDir(), TL_ROOT);
        $this->assertSame('', TL_REFERER_ID);
        $this->assertSame('index.html', TL_SCRIPT);
        $this->assertSame('', TL_PATH);
        $this->assertSame('en', $GLOBALS['TL_LANGUAGE']);
        $this->assertInstanceOf(ArrayAttributeBag::class, $_SESSION['BE_DATA']);
        $this->assertInstanceOf(ArrayAttributeBag::class, $_SESSION['FE_DATA']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkWithABackEndRequest(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_scope', 'backend');
        $request->attributes->set('_contao_referer_id', 'foobar');
        $request->setLocale('de');

        $framework = $this->mockFramework($this->mockRouter('/contao/login'), $request);
        $framework->setContainer($this->mockContainer());
        $framework->initialize();

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertTrue(\defined('TL_REFERER_ID'));
        $this->assertTrue(\defined('TL_SCRIPT'));
        $this->assertTrue(\defined('BE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('FE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('TL_PATH'));
        $this->assertSame('BE', TL_MODE);
        $this->assertSame($this->getTempDir(), TL_ROOT);
        $this->assertSame('foobar', TL_REFERER_ID);
        $this->assertSame('contao/login', TL_SCRIPT);
        $this->assertSame('', TL_PATH);
        $this->assertSame('de', $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkWithoutARequest(): void
    {
        $framework = $this->mockFramework($this->mockRouter('/contao/login'));
        $framework->setContainer($this->mockContainer());

        /** @var Config|MockObject $config */
        $config = $framework->getAdapter(Config::class);
        $config
            ->expects($this->once())
            ->method('preload')
        ;

        $framework->initialize();

        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertFalse(\defined('TL_MODE'));
        $this->assertFalse(\defined('TL_REFERER_ID'));
        $this->assertFalse(\defined('TL_SCRIPT'));
        $this->assertFalse(\defined('BE_USER_LOGGED_IN'));
        $this->assertFalse(\defined('FE_USER_LOGGED_IN'));
        $this->assertFalse(\defined('TL_PATH'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkWithoutARoute(): void
    {
        $request = new Request();
        $request->setLocale('de');

        $routingLoader = $this->createMock(LoaderInterface::class);
        $routingLoader
            ->method('load')
            ->willReturn(new RouteCollection())
        ;

        $container = $this->mockContainer();
        $container->set('routing.loader', $routingLoader);

        $framework = $this->mockFramework(new Router($container, []), $request);
        $framework->setContainer($container);
        $framework->initialize();

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertTrue(\defined('TL_REFERER_ID'));
        $this->assertTrue(\defined('TL_SCRIPT'));
        $this->assertTrue(\defined('BE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('FE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('TL_PATH'));
        $this->assertNull(TL_MODE);
        $this->assertSame($this->getTempDir(), TL_ROOT);
        $this->assertSame('', TL_REFERER_ID);
        $this->assertNull(TL_SCRIPT);
        $this->assertSame('', TL_PATH);
        $this->assertSame('de', $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkWithoutAScope(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_contao_referer_id', 'foobar');

        $framework = $this->mockFramework($this->mockRouter('/contao/login'), $request);
        $framework->setContainer($this->mockContainer());
        $framework->initialize();

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertTrue(\defined('TL_REFERER_ID'));
        $this->assertTrue(\defined('TL_SCRIPT'));
        $this->assertTrue(\defined('BE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('FE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('TL_PATH'));
        $this->assertNull(TL_MODE);
        $this->assertSame($this->getTempDir(), TL_ROOT);
        $this->assertSame('foobar', TL_REFERER_ID);
        $this->assertSame('contao/login', TL_SCRIPT);
        $this->assertSame('', TL_PATH);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInitializesTheFrameworkAfterRequestIsSet(): void
    {
        $request = new Request();
        $request->setLocale('de');

        $routingLoader = $this->createMock(LoaderInterface::class);
        $routingLoader
            ->method('load')
            ->willReturn(new RouteCollection())
        ;

        $container = $this->mockContainer();
        $container->set('routing.loader', $routingLoader);

        $framework = $this->mockFramework(new Router($container, []));
        $framework->setContainer($container);
        $framework->initialize();

        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertFalse(\defined('TL_MODE'));
        $this->assertFalse(\defined('TL_REFERER_ID'));
        $this->assertFalse(\defined('TL_SCRIPT'));
        $this->assertFalse(\defined('BE_USER_LOGGED_IN'));
        $this->assertFalse(\defined('FE_USER_LOGGED_IN'));
        $this->assertFalse(\defined('TL_PATH'));
        $this->assertSame($this->getTempDir(), TL_ROOT);

        $framework->setRequest($request);

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertTrue(\defined('TL_ROOT'));
        $this->assertTrue(\defined('TL_REFERER_ID'));
        $this->assertTrue(\defined('TL_SCRIPT'));
        $this->assertTrue(\defined('BE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('FE_USER_LOGGED_IN'));
        $this->assertTrue(\defined('TL_PATH'));
        $this->assertNull(TL_MODE);
        $this->assertSame($this->getTempDir(), TL_ROOT);
        $this->assertSame('', TL_REFERER_ID);
        $this->assertNull(TL_SCRIPT);
        $this->assertSame('', TL_PATH);
        $this->assertSame('de', $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDoesNotInitializeTheFrameworkTwice(): void
    {
        $scopeMatcher = $this->createMock(ScopeMatcher::class);
        $scopeMatcher
            ->expects($this->once())
            ->method('isBackendRequest')
            ->willReturn(false)
        ;

        $scopeMatcher
            ->expects($this->exactly(2))
            ->method('isFrontendRequest')
            ->willReturn(false)
        ;

        $framework = $this->mockFramework(null, null, $scopeMatcher);
        $framework->setContainer($this->mockContainer());
        $framework->setRequest(new Request());
        $framework->initialize();

        $this->assertTrue(\defined('TL_MODE'));
        $this->assertNull(TL_MODE);

        $framework->initialize();

        $this->addToAssertionCount(1);  // does not throw an exception
    }

    public function testOverridesTheErrorLevel(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_contao_referer_id', 'foobar');

        $framework = $this->mockFramework($this->mockRouter('/contao/login'), $request);
        $framework->setContainer($this->mockContainer());

        $errorReporting = error_reporting();
        error_reporting(E_ALL ^ E_USER_NOTICE);

        $this->assertNotSame(
            $errorReporting,
            error_reporting(),
            'Test is invalid, error level has not changed.'
        );

        $framework->initialize();

        $this->assertSame($errorReporting, error_reporting());

        error_reporting($errorReporting);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testValidatesTheRequestToken(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_token_check', true);
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foobar');

        $framework = $this->mockFramework($this->mockRouter('/contao/login'), $request);
        $framework->setContainer($this->mockContainer());
        $framework->initialize();

        $this->addToAssertionCount(1);  // does not throw an exception
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailsIfTheRequestTokenIsInvalid(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_token_check', true);
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'invalid');

        $framework = new ContaoFramework(
            $this->mockRouter('/contao/login'),
            $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);

        $adapters = [
            Config::class => $this->mockConfigAdapter(),
            RequestToken::class => $this->mockRequestTokenAdapter(false),
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        $this->expectException(InvalidRequestTokenException::class);

        $framework->initialize();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDoesNotValidateTheRequestTokenUponAjaxRequests(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_token_check', true);
        $request->setMethod('POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $framework = new ContaoFramework(
            $this->mockRouter('/contao/login'),
            $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);

        $adapters = [
            Config::class => $this->mockConfigAdapter(),
            RequestToken::class => $this->mockRequestTokenAdapter(false),
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        $framework->initialize();

        $this->addToAssertionCount(1);  // does not throw an exception
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDoesNotValidateTheRequestTokenIfTheRequestAttributeIsFalse(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_token_check', false);
        $request->setMethod('POST');
        $request->request->set('REQUEST_TOKEN', 'foobar');

        $framework = new ContaoFramework(
            $this->mockRouter('/contao/login'),
            $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);

        $adapter = $this->mockAdapter(['get', 'validate']);
        $adapter
            ->method('get')
            ->willReturn('foobar')
        ;

        $adapter
            ->expects($this->never())
            ->method('validate')
        ;

        $adapters = [
            Config::class => $this->mockConfigAdapter(),
            RequestToken::class => $adapter,
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        $framework->initialize();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailsIfTheInstallationIsIncomplete(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');

        $framework = new ContaoFramework(
            $this->mockRouter('/contao/login'),
            $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);

        $adapters = [
            Config::class => $this->mockConfigAdapter(false),
            RequestToken::class => $this->mockRequestTokenAdapter(),
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        $this->expectException(IncompleteInstallationException::class);

        $framework->initialize();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @dataProvider getInstallRoutes
     */
    public function testAllowsTheInstallationToBeIncompleteInTheInstallTool(string $route): void
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        $framework = new ContaoFramework(
            $this->mockRouter('/contao/login'),
            $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);

        $adapters = [
            Config::class => $this->mockConfigAdapter(false),
            RequestToken::class => $this->mockRequestTokenAdapter(),
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        $framework->initialize();

        $this->addToAssertionCount(1);  // does not throw an exception
    }

    /**
     * @return array<string,string[]>
     */
    public function getInstallRoutes(): array
    {
        return [
            'contao_install' => ['contao_install'],
            'contao_install_redirect' => ['contao_install_redirect'],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailsIfTheContainerIsNotSet(): void
    {
        $framework = $this->mockFramework($this->mockRouter('/contao/login'));

        $this->expectException('LogicException');

        $framework->initialize();
    }

    /**
     * @group legacy
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     *
     * @expectedDeprecation Using $_SESSION has been deprecated %s.
     */
    public function testRegistersTheLazySessionAccessObject(): void
    {
        $beBag = new ArrayAttributeBag();
        $beBag->setName('contao_backend');

        $feBag = new ArrayAttributeBag();
        $feBag->setName('contao_frontend');

        $session = new Session(new MockNativeSessionStorage());
        $session->registerBag($beBag);
        $session->registerBag($feBag);

        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_scope', 'frontend');
        $request->setSession($session);

        $framework = $this->mockFramework($this->mockRouter('/index.html'));
        $framework->setContainer($this->mockContainer());
        $framework->setRequest($request);
        $framework->initialize();

        $this->assertInstanceOf(LazySessionAccess::class, $_SESSION);
        $this->assertInstanceOf(ArrayAttributeBag::class, $_SESSION['BE_DATA']);
        $this->assertInstanceOf(ArrayAttributeBag::class, $_SESSION['FE_DATA']);
    }

    public function testCreatesAnObjectInstance(): void
    {
        /** @var ContaoFramework $framework */
        $framework = $this->mockFramework();

        $class = LegacyClass::class;
        $instance = $framework->createInstance($class, [1, 2]);

        $this->assertInstanceOf($class, $instance);
        $this->assertSame([1, 2], $instance->constructorArgs);
    }

    public function testCreateASingeltonObjectInstance(): void
    {
        /** @var ContaoFramework $framework */
        $framework = $this->mockFramework();

        $class = LegacySingletonClass::class;
        $instance = $framework->createInstance($class, [1, 2]);

        $this->assertInstanceOf($class, $instance);
        $this->assertSame([1, 2], $instance->constructorArgs);
    }

    public function testCreatesAdaptersForLegacyClasses(): void
    {
        /** @var ContaoFramework $framework */
        $framework = $this->mockFramework();
        $adapter = $framework->getAdapter(LegacyClass::class);

        $ref = new \ReflectionClass($adapter);
        $prop = $ref->getProperty('class');
        $prop->setAccessible(true);

        $this->assertSame(LegacyClass::class, $prop->getValue($adapter));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRegistersTheHookServices(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'dummy');
        $request->attributes->set('_scope', 'backend');
        $request->attributes->set('_contao_referer_id', 'foobar');
        $request->setLocale('de');

        $container = $this->mockContainer();
        $container->set('test.listener', new \stdClass());
        $container->set('test.listener2', new \stdClass());

        $GLOBALS['TL_HOOKS'] = [
            'getPageLayout' => [
                ['test.listener.c', 'onGetPageLayout'],
            ],
            'generatePage' => [
                ['test.listener.c', 'onGeneratePage'],
            ],
            'parseTemplate' => [
                ['test.listener.c', 'onParseTemplate'],
            ],
            'isVisibleElement' => [
                ['test.listener.c', 'onIsVisibleElement'],
            ],
        ];

        $listeners = [
            'getPageLayout' => [
                10 => [
                    ['test.listener.a', 'onGetPageLayout'],
                ],
                0 => [
                    ['test.listener.b', 'onGetPageLayout'],
                ],
            ],
            'generatePage' => [
                0 => [
                    ['test.listener.b', 'onGeneratePage'],
                ],
                -10 => [
                    ['test.listener.a', 'onGeneratePage'],
                ],
            ],
            'parseTemplate' => [
                10 => [
                    ['test.listener.a', 'onParseTemplate'],
                ],
            ],
            'isVisibleElement' => [
                -10 => [
                    ['test.listener.a', 'onIsVisibleElement'],
                ],
            ],
        ];

        $framework = $this->mockFramework($this->mockRouter('/index.html'));
        $framework->setContainer($container);
        $framework->setRequest($request);
        $framework->setHookListeners($listeners);

        $reflection = new \ReflectionObject($framework);
        $reflectionMethod = $reflection->getMethod('registerHookListeners');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($framework);

        $this->assertArrayHasKey('TL_HOOKS', $GLOBALS);
        $this->assertArrayHasKey('getPageLayout', $GLOBALS['TL_HOOKS']);
        $this->assertArrayHasKey('generatePage', $GLOBALS['TL_HOOKS']);
        $this->assertArrayHasKey('parseTemplate', $GLOBALS['TL_HOOKS']);
        $this->assertArrayHasKey('isVisibleElement', $GLOBALS['TL_HOOKS']);

        // Test hooks with high priority are added before low and legacy hooks
        // Test legacy hooks are added before hooks with priority 0
        $this->assertSame(
            [
                ['test.listener.a', 'onGetPageLayout'],
                ['test.listener.c', 'onGetPageLayout'],
                ['test.listener.b', 'onGetPageLayout'],
            ],
            $GLOBALS['TL_HOOKS']['getPageLayout']
        );

        // Test hooks with negative priority are added at the end
        $this->assertSame(
            [
                ['test.listener.c', 'onGeneratePage'],
                ['test.listener.b', 'onGeneratePage'],
                ['test.listener.a', 'onGeneratePage'],
            ],
            $GLOBALS['TL_HOOKS']['generatePage']
        );

        // Test legacy hooks are kept when adding only hook listeners with high priority.
        $this->assertSame(
            [
                ['test.listener.a', 'onParseTemplate'],
                ['test.listener.c', 'onParseTemplate'],
            ],
            $GLOBALS['TL_HOOKS']['parseTemplate']
        );

        // Test legacy hooks are kept when adding only hook listeners with low priority.
        $this->assertSame(
            [
                ['test.listener.c', 'onIsVisibleElement'],
                ['test.listener.a', 'onIsVisibleElement'],
            ],
            $GLOBALS['TL_HOOKS']['isVisibleElement']
        );
    }

    /**
     * @return RouterInterface|MockObject
     */
    private function mockRouter(string $url): RouterInterface
    {
        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('generate')
            ->willReturn($url)
        ;

        return $router;
    }

    private function mockFramework(RouterInterface $router = null, Request $request = null, ScopeMatcher $scopeMatcher = null): ContaoFramework
    {
        $framework = new ContaoFramework(
            $router ?? $this->mockRouter('/'),
            $scopeMatcher ?? $this->mockScopeMatcher(),
            $this->getTempDir(),
            error_reporting()
        );

        if (null !== $request) {
            $framework->setRequest($request);
        }

        $adapters = [
            Config::class => $this->mockConfigAdapter(),
            RequestToken::class => $this->mockRequestTokenAdapter(),
        ];

        $ref = new \ReflectionObject($framework);
        $adapterCache = $ref->getProperty('adapterCache');
        $adapterCache->setAccessible(true);
        $adapterCache->setValue($framework, $adapters);

        return $framework;
    }

    private function mockConfigAdapter(bool $complete = true): Adapter
    {
        $config = $this->mockAdapter(['preload', 'isComplete', 'getInstance', 'get']);
        $config
            ->method('isComplete')
            ->willReturn($complete)
        ;

        $config
            ->method('getInstance')
            ->willReturn($config)
        ;

        $config
            ->method('get')
            ->with('timeZone')
            ->willReturn('Europe/Berlin')
        ;

        return $config;
    }

    private function mockRequestTokenAdapter(bool $valid = true): Adapter
    {
        $adapter = $this->mockAdapter(['get', 'validate']);
        $adapter
            ->method('get')
            ->willReturn('foobar')
        ;

        $adapter
            ->method('validate')
            ->willReturn($valid)
        ;

        return $adapter;
    }
}
