<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\warmers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\UrlRule;
use Generator;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\CacheWarmerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\models\SiteUriModel;
use yii\base\Exception;

/**
 * @property mixed $settingsHtml
 */
class LocalWarmer extends BaseCacheWarmer
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Local Warmer');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function warmUris(array $siteUris, int $delay = null, callable $setProgressHandler = null)
    {
        if (!$this->beforeWarmCache($siteUris)) {
            return;
        }

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->warmUrisWithProgressForConsoleRequest($siteUris, $setProgressHandler);
        }
        else {
            CacheWarmerHelper::addWarmerJob($siteUris, 'warmUrisWithProgress', $delay);
        }

        $this->afterWarmCache($siteUris);
    }

    /**
     * Warms site URIs with progress.
     *
     * @param array $siteUris
     * @param callable|null $setProgressHandler
     */
    public function warmUrisWithProgress(array $siteUris, callable $setProgressHandler = null)
    {
        $count = 0;
        $total = count($siteUris);
        $label = 'Warming {count} of {total} pages.';

        foreach ($siteUris as $siteUri) {
            $count++;

            if (is_callable($setProgressHandler)) {
                $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                call_user_func($setProgressHandler, $count, $total, $progressLabel);
            }

            // Convert to a SiteUriModel if it is an array
            if (is_array($siteUri)) {
                $siteUri = new SiteUriModel($siteUri);
            }

            $this->warmUri($siteUri);
        }
    }

    /**
     * Warms site URIs with progress for a console request.
     *
     * @param SiteUriModel[] $siteUris
     * @param callable|null $setProgressHandler
     */
    public function warmUrisWithProgressForConsoleRequest(array $siteUris, callable $setProgressHandler = null)
    {
        /**
         * Empty params array is required because of Craft bug
         * @see https://github.com/craftcms/cms/pull/5282
         */
        $route = ['blitz/local-warmer/warm-site-uri', []];

        $token = Craft::$app->getTokens()->createToken($route);

        $count = 0;
        $total = count($siteUris);
        $label = 'Warming {count} of {total} pages.';

        $client = Craft::createGuzzleClient();

        // Create a pool of requests for sending multiple concurrent requests
        $pool = new Pool($client, $this->_getRequests($siteUris, $token), [
            'fulfilled' => function() use (&$count, $total, $label, $setProgressHandler) {
                $count++;

                if (is_callable($setProgressHandler)) {
                    $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                    call_user_func($setProgressHandler, $count, $total, $progressLabel);
                }
            },
            'rejected' => function(GuzzleException $reason) use (&$count, $total, $label, $setProgressHandler) {
                $count++;

                if (is_callable($setProgressHandler)) {
                    $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                    call_user_func($setProgressHandler, $count, $total, $progressLabel);
                }

                Blitz::$plugin->debug($reason->getMessage());
            },
        ]);

        // Initiate the transfers and wait for the pool of requests to complete
        $pool->promise()->wait();
    }

    /**
     * Warms a site URI.
     *
     * @param SiteUriModel $siteUri
     * @param array $componentConfigs
     */
    public function warmUri(SiteUriModel $siteUri)
    {
        $url = $siteUri->getUrl();

        // Parse the URI rather than getting it from `$siteUri` to ensure we have the full request URI (important!)
        $uri = trim(parse_url($url, PHP_URL_PATH), '/');

        /**
         * Mock the web server request
         * @see \craft\test\Craft::recreateClient
         */
        $_SERVER = array_merge($_SERVER, [
            'HTTP_HOST' => parse_url($url, PHP_URL_HOST),
            'SERVER_NAME' => parse_url($url, PHP_URL_HOST),
            'HTTPS' => parse_url($url, PHP_URL_SCHEME) === 'https',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/'.$uri,
            'QUERY_STRING' => 'p='.$uri,
        ]);
        $_GET = ['p' => $uri];

        /**
         * Recreate component configs
         * @see vendor/craftcms/cms/src/config/app.web.php
         */
        Craft::$app->set('request', App::webRequestConfig());
        Craft::$app->set('response', App::webResponseConfig());
        Craft::$app->set('urlManager', [
            'class' => UrlManager::class,
            'enablePrettyUrl' => true,
            'ruleConfig' => ['class' => UrlRule::class],
        ]);

        /**
         * Override the host info as it can be set unreliably
         * @see \yii\web\Request::getHostInfo
         */
        Craft::$app->getRequest()->setHostInfo(
            parse_url($url, PHP_URL_SCHEME).'://'
            .parse_url($url, PHP_URL_HOST)
        );

        // Set the template mode to front-end site
        Craft::$app->getView()->setTemplateMode('site');

        // Tell Blitz to process if a cacheable request and not to output the result
        Blitz::$plugin->processCacheableRequest(false);

        // Handle the request with before/after events
        Craft::$app->trigger(Craft::$app::EVENT_BEFORE_REQUEST);
        Craft::$app->handleRequest(Craft::$app->getRequest());
        Craft::$app->trigger(Craft::$app::EVENT_AFTER_REQUEST);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a generator to return the URL requests in a memory efficient manner
     * https://medium.com/tech-tajawal/use-memory-gently-with-yield-in-php-7e62e2480b8d
     *
     * @param array $siteUris
     * @param string $token
     *
     * @return Generator
     */
    private function _getRequests(array $siteUris, string $token): Generator
    {
        /** @var SiteUriModel $siteUri */
        foreach ($siteUris as $siteUri) {
            $url = UrlHelper::siteUrl('actions/blitz/local-warmer/warm-site-uri', [
                'siteId' => $siteUri->siteId,
                'uri' => $siteUri->uri,
                'token' => $token,
            ]);

            yield new Request('GET', $url);
        }
    }
}
