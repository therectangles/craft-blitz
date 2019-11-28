<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\controllers;

use Craft;
use craft\web\Controller;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\warmers\LocalWarmer;
use putyourlightson\blitz\models\SiteUriModel;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class LocalWarmerController extends Controller
{
    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected $allowAnonymous = true;

    // Public Methods
    // =========================================================================

    /**
     * Warms a site URI.
     *
     * @return Response
     */
    public function actionWarmSiteUri(): Response
    {
        // Make sure a token was used to get here
        $this->requireToken();

        if (!(Blitz::$plugin->cacheWarmer instanceof LocalWarmer)) {
            throw new ForbiddenHttpException('Local warmer is not in use.');
        }

        $request = Craft::$app->getRequest();

        $siteId = $request->getRequiredParam('siteId');
        $uri = $request->getRequiredParam('uri');

        $siteUri = new SiteUriModel([
            'siteId' => $siteId,
            'uri' => $uri,
        ]);

        Blitz::$plugin->cacheWarmer->warmUri($siteUri);

        return $this->asRaw(true);
    }
}
