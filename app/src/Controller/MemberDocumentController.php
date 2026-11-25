<?php

namespace App\Web\Controller;

use App\Web\Service\MemberDocuments;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Serves a member's own CV or cover letter through the signed, expiring link
 * from MemberDocuments::link(). Route: member-document/$Type.
 */
class MemberDocumentController extends Controller
{
    private static $allowed_actions = ['index'];

    private static $url_handlers = ['' => 'index'];

    public function index(HTTPRequest $request)
    {
        $file = MemberDocuments::fileForLink(
            $request->param('Type'),
            $request->getVar('u'),
            $request->getVar('e'),
            $request->getVar('s')
        );

        if (!$file) {
            return $this->httpError(404, 'This link has expired. Please open your profile again.');
        }

        $name = str_replace('"', '', $file->Title ?: $file->Name);
        $isPdf = strtolower($file->getExtension()) === 'pdf';

        return HTTPResponse::create($file->getString())
            ->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream')
            ->addHeader('Content-Disposition', ($isPdf ? 'inline' : 'attachment') . '; filename="' . $name . '"')
            ->addHeader('Cache-Control', 'private, no-store')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('X-Robots-Tag', 'noindex');
    }
}
