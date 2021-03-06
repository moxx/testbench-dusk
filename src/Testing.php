<?php

namespace Orchestra\Testbench\Dusk;

use Closure;
use Exception;
use Throwable;
use ReflectionFunction;
use Laravel\Dusk\Browser;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Collection;
use Laravel\Dusk\Chrome\SupportsChrome;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;

abstract class Testing extends TestCase
{
    use Concerns\CanServeSite,
        SupportsChrome;

    /**
     * All of the active browser instances.
     *
     * @var array
     */
    protected static $browsers = [];
    /**
     * The callbacks that should be run on class tear down.
     *
     * @var array
     */
    protected static $afterClassCallbacks = [];

    /**
     * Register the base URL with Dusk.
     *
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();

        Browser::$baseUrl = $this->baseUrl();

        $this->prepareDirectories();

        Browser::$userResolver = function () {
            return $this->user();
        };
    }

    /**
     * Tear down the Dusk test case class.
     *
     * @afterClass
     *
     * @return void
     */
    public static function tearDownDuskClass()
    {
        static::closeAll();

        foreach (static::$afterClassCallbacks as $callback) {
            $callback();
        }
    }

    /**
     * Register an "after class" tear down callback.
     *
     * @param  \Closure $callback
     *
     * @return void
     */
    public static function afterClass(Closure $callback)
    {
        static::$afterClassCallbacks[] = $callback;
    }

    /**
     * Create a new browser instance.
     *
     * @param  \Closure $callback
     *
     * @throws \Exception
     * @throws \Throwable
     *
     * @return void
     */
    public function browse(Closure $callback)
    {
        $browsers = $this->createBrowsersFor($callback);

        try {
            $callback(...$browsers->all());
        } catch (Exception $e) {
            $this->captureFailuresFor($browsers);
            throw $e;
        } catch (Throwable $e) {
            $this->captureFailuresFor($browsers);
            throw $e;
        } finally {
            $this->storeConsoleLogsFor($browsers);
            static::$browsers = $this->closeAllButPrimary($browsers);
        }
    }

    /**
     * Create the browser instances needed for the given callback.
     *
     * @param  \Closure $callback
     *
     * @return array
     */
    protected function createBrowsersFor(Closure $callback)
    {
        if (count(static::$browsers) === 0) {
            static::$browsers = collect([$this->newBrowser($this->createWebDriver())]);
        }

        $additional = $this->browsersNeededFor($callback) - 1;

        for ($i = 0; $i < $additional; $i++) {
            static::$browsers->push($this->newBrowser($this->createWebDriver()));
        }

        return static::$browsers;
    }

    /**
     * Create a new Browser instance.
     *
     * @param  \Facebook\WebDriver\Remote\RemoteWebDriver $driver
     *
     * @return \Laravel\Dusk\Browser
     */
    protected function newBrowser($driver): Browser
    {
        return new Browser($driver);
    }

    /**
     * Get the number of browsers needed for a given callback.
     *
     * @param  \Closure $callback
     *
     * @return int
     */
    protected function browsersNeededFor(Closure $callback): int
    {
        return (new ReflectionFunction($callback))->getNumberOfParameters();
    }

    /**
     * Capture failure screenshots for each browser.
     *
     * @param  \Illuminate\Support\Collection $browsers
     *
     * @return void
     */
    protected function captureFailuresFor($browsers): void
    {
        $browsers->each(function ($browser, $key) {
            $browser->screenshot('failure-'.$this->getName().'-'.$key);
        });
    }

    /**
     * Store the console output for the given browsers.
     *
     * @param  \Illuminate\Support\Collection $browsers
     *
     * @return void
     */
    protected function storeConsoleLogsFor($browsers): void
    {
        $browsers->each(function ($browser, $key) {
            $browser->storeConsoleLog($this->getName().'-'.$key);
        });
    }

    /**
     * Close all of the browsers except the primary (first) one.
     *
     * @param  \Illuminate\Support\Collection $browsers
     *
     * @return \Illuminate\Support\Collection
     */
    protected function closeAllButPrimary($browsers)
    {
        $browsers->slice(1)->each->quit();

        return $browsers->take(1);
    }

    /**
     * Close all of the active browsers.
     *
     * @return void
     */
    public static function closeAll(): void
    {
        Collection::make(static::$browsers)->each->quit();

        static::$browsers = collect();
    }

    /**
     * Create the remote web driver instance.
     *
     * @return \Facebook\WebDriver\Remote\RemoteWebDriver
     */
    protected function createWebDriver()
    {
        return retry(5, function () {
            return $this->driver();
        }, 50);
    }

    /**
     * Create the RemoteWebDriver instance.
     *
     * @return \Facebook\WebDriver\Remote\RemoteWebDriver
     */
    protected function driver(): RemoteWebDriver
    {
        return RemoteWebDriver::create(
            'http://localhost:9515', DesiredCapabilities::chrome()
        );
    }

    /**
     * Determine the application's base URL.
     *
     * @var string
     */
    protected function baseUrl(): string
    {
        return config('app.url');
    }

    /**
     * Get a callback that returns the default user to authenticate.
     *
     * @throws \Exception
     *
     * @return \Closure
     */
    protected function user()
    {
        throw new Exception('User resolver has not been set.');
    }

    /**
     * Ensure the directories we need for dusk exist, and set them for the Browser to use.
     *
     * @return void
     */
    protected function prepareDirectories(): void
    {
        $tests = $this->resolveBrowserTestsPath();

        foreach (['/screenshots', '/console'] as $dir) {
            if (! is_dir($tests.$dir)) {
                mkdir($tests.$dir, 0777, true);
            }
        }

        Browser::$storeScreenshotsAt = $tests.'/screenshots';
        Browser::$storeConsoleLogAt = $tests.'/console';
    }

    /**
     * Figure out where the test directory is, if we're an included package, or the root one.
     *
     * @param string $path
     *
     * @return string
     */
    protected function resolveBrowserTestsPath($path = __DIR__)
    {
        $path = dirname($path);

        // If we're in 'vendor', we need to drop back to project root
        if (basename(dirname(dirname($path))) == 'vendor') {
            $path = dirname(dirname(dirname($path)));
        }

        return $path.'/tests/Browser';
    }
}
