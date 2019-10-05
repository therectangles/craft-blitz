<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\deployers;

use craft\base\SavableComponentInterface;
use putyourlightson\blitz\models\SiteUriModel;

interface DeployerInterface extends SavableComponentInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Deploys the cache given an array of site URIs.
     *
     * @param SiteUriModel[] $siteUris
     */
    public function deployUris(array $siteUris);

    /**
     * Deploys the entire cache.
     */
    public function deployAll();
}
