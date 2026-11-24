<?php

namespace App\Web\Task;

use App\Web\Service\MemberDocuments;
use Cita\eCommerce\Model\Customer;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Dev\BuildTask;

/**
 * Copies CVs and cover letters from the old S3 bucket into the site's own
 * protected storage (see MemberDocuments). Needs the old bucket's read
 * settings in .env: AWS_BUCKET_NAME, AWS_REGION, AWS_ACCESS_KEY_ID,
 * AWS_SECRET_ACCESS_KEY. Safe to run more than once.
 *
 * Run it as the web user, so the folders it creates stay writable by the site:
 *   docker compose ... exec --user www-data web vendor/bin/sake dev/tasks/migrate-member-documents dry=1
 *   docker compose ... exec --user www-data web vendor/bin/sake dev/tasks/migrate-member-documents
 */
class MigrateMemberDocuments extends BuildTask
{
    protected $title = 'Move member CVs and cover letters from S3 to the site';

    protected $description = 'Copies CV / CoverLetter files from the old S3 bucket into protected site storage.';

    private static $segment = 'migrate-member-documents';

    public function run($request)
    {
        // Folders made by root cannot be written by the web server, and then
        // every later CV upload for those members fails.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            echo "Not running as root. Use: docker compose ... exec --user www-data web vendor/bin/sake dev/tasks/migrate-member-documents\n";
            return;
        }

        $dry = (bool) $request->getVar('dry');
        $moved = $missing = $already = 0;

        foreach (MemberDocuments::TYPES as $type => [$relation, $legacyField]) {
            $customers = Customer::get()->exclude([$legacyField => [null, '']]);

            foreach ($customers as $customer) {
                if (MemberDocuments::file($customer, $type)) {
                    $already++;
                    continue;
                }

                $doc = MemberDocuments::legacyContent("{$customer->GUID}/{$customer->$legacyField}");

                if (!$doc || $doc['data'] === '') {
                    $missing++;
                    echo "missing {$type} for member {$customer->ID}\n";
                    continue;
                }

                if ($dry) {
                    $moved++;
                    continue;
                }

                $ext = strtolower(pathinfo($customer->$legacyField, PATHINFO_EXTENSION)) ?: 'pdf';
                $folder = Folder::find_or_make('member-documents/' . $customer->GUID);
                $file = File::create();
                $file->setFromString($doc['data'], bin2hex(random_bytes(16)) . '.' . $ext);
                $file->ParentID = $folder->ID;
                $file->Title = $doc['name'];
                $file->writeToStage('Stage');

                $customer->{$relation . 'ID'} = $file->ID;
                $customer->$legacyField = null;
                $customer->write();
                $moved++;
            }
        }

        echo ($dry ? 'would move' : 'moved') . " {$moved}, missing {$missing}, already moved {$already}\n";
    }
}
