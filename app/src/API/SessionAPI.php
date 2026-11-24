<?php

namespace App\Web\API;
use Leochenftw\Restful\RestfulController;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Core\Environment;
use App\Web\Traits\OAuthTrait;

class SessionAPI extends RestfulController
{
    use OAuthTrait;
    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'get' => true
    ];

    public function get($request)
    {
        $action = $request->param('action');

        if ($this->hasMethod($action)) {
            return $this->$action($request);
        }

        return $this->httpError(404);
    }

    public function getCSRF()
    {
        return SecurityToken::inst()->getSecurityID();
    }

    /**
     * Used to hand S3 keys to the browser for CV uploads. CVs now go through
     * the site (Member::uploadDocument), so no keys are given out.
     */
    public function getAAK(&$request)
    {
        return $this->httpError(410, 'Gone');
    }
}
