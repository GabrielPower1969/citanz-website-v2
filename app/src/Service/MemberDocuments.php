<?php

namespace App\Web\Service;

use Aws\S3\S3Client;
use Cita\eCommerce\Model\Customer;
use Exception;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Environment;
use SilverStripe\Versioned\Versioned;

/**
 * Members' CVs and cover letters.
 *
 * Files are kept on the site's own storage as protected (never published)
 * files under member-documents/<member GUID>/, so they are covered by the
 * nightly backup and are never reachable through a public URL. Nobody gets
 * storage keys: the owner sees their file through a signed link that
 * expires after an hour (MemberDocumentController).
 *
 * Older profiles may still point at the previous S3 bucket (the CV and
 * CoverLetter fields). Those are read with the server's AWS_* settings when
 * they are set, and can be copied in with the migrate-member-documents task.
 */
class MemberDocuments
{
    /** type => [has_one relation, old S3 key field] */
    const TYPES = [
        'cv' => ['CVFile', 'CV'],
        'cl' => ['CoverLetterFile', 'CoverLetter'],
    ];

    const MAX_BYTES = 10485760; // 10 MB, same as the PHP upload limit

    const LINK_SECONDS = 3600;

    public static function isType($type)
    {
        return is_string($type) && isset(self::TYPES[$type]);
    }

    /**
     * Checks an uploaded file ($_FILES entry). Returns an error message, or
     * null when the file is fine.
     */
    public static function check($upload)
    {
        if (!is_array($upload)) {
            return 'Please choose a file.';
        }

        if (!empty($upload['error'])) {
            return $upload['error'] == UPLOAD_ERR_NO_FILE
                ? 'Please choose a file.'
                : 'The upload did not work. Files can be up to 10 MB.';
        }

        if (empty($upload['tmp_name'])) {
            return 'Please choose a file.';
        }

        $ext = self::extension($upload['name'] ?? '');

        if (!$ext) {
            return 'Please upload a PDF or Word file (.pdf, .doc, .docx).';
        }

        $size = filesize($upload['tmp_name']);

        if (!$size) {
            return 'The file is empty.';
        }

        if ($size > self::MAX_BYTES) {
            return 'Files can be up to 10 MB.';
        }

        // The content must match the extension, not just the name.
        $head = (string) file_get_contents($upload['tmp_name'], false, null, 0, 8);
        $magic = [
            'pdf' => '%PDF',
            'docx' => "PK\x03\x04",
            'doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1",
        ];

        if (strpos($head, $magic[$ext]) !== 0) {
            return 'This file does not look like a PDF or Word document.';
        }

        return null;
    }

    /**
     * Saves an uploaded file as the member's CV or cover letter and removes
     * the one it replaces.
     */
    public static function store(Customer $customer, $type, array $upload)
    {
        $ext = self::extension($upload['name']);
        $folder = Folder::find_or_make('member-documents/' . $customer->GUID);

        $file = File::create();
        $file->setFromLocalFile($upload['tmp_name'], bin2hex(random_bytes(16)) . '.' . $ext);
        $file->ParentID = $folder->ID;
        $file->Title = self::cleanName($upload['name']);
        $file->writeToStage('Stage');

        self::remove($customer, $type);

        [$relation, $legacyField] = self::TYPES[$type];
        $customer->{$relation . 'ID'} = $file->ID;
        $customer->$legacyField = null;
        $customer->write();

        return $file;
    }

    /** Deletes the member's file of this type (local and the old S3 pointer). */
    public static function remove(Customer $customer, $type)
    {
        [$relation, $legacyField] = self::TYPES[$type];
        $file = self::file($customer, $type);

        if ($file) {
            $file->deleteFromStage('Live');
            $file->deleteFromStage('Stage');
        }

        $customer->{$relation . 'ID'} = 0;
        $customer->$legacyField = null;
        $customer->write();
    }

    /** Link for the owner to view their file, or null if there is none. */
    public static function link(Customer $customer, $type)
    {
        [$relation, $legacyField] = self::TYPES[$type];
        $file = self::file($customer, $type);

        if ($file) {
            $expires = time() + self::LINK_SECONDS;
            $query = http_build_query([
                'u' => $customer->GUID,
                'e' => $expires,
                's' => self::sign($customer->GUID, $type, $file->ID, $expires),
            ]);

            return "/member-document/{$type}?{$query}";
        }

        // Not moved yet: the old bucket address, as before.
        if (!empty($customer->$legacyField)) {
            $base = Environment::getEnv('AWS_S3_ASSETS_BASE_URL') ?: '';

            return "{$base}{$customer->GUID}/{$customer->$legacyField}";
        }

        return null;
    }

    /** The file behind a signed link, or null if the link is not valid. */
    public static function fileForLink($type, $guid, $expires, $signature)
    {
        if (!self::isType($type) || !$guid || !$signature || (int) $expires < time()) {
            return null;
        }

        $customer = Customer::get()->filter('GUID', $guid)->first();

        if (!$customer) {
            return null;
        }

        $file = self::file($customer, $type);

        if (!$file) {
            return null;
        }

        $expected = self::sign($guid, $type, $file->ID, (int) $expires);

        return hash_equals($expected, (string) $signature) ? $file : null;
    }

    /**
     * File content and name for an email attachment, or null if the member
     * has no file of this type or it cannot be read.
     */
    public static function content(Customer $customer, $type)
    {
        [$relation, $legacyField] = self::TYPES[$type];
        $file = self::file($customer, $type);

        if ($file) {
            return ['data' => $file->getString(), 'name' => $file->Title ?: $file->Name];
        }

        if (!empty($customer->$legacyField)) {
            return self::legacyContent("{$customer->GUID}/{$customer->$legacyField}");
        }

        return null;
    }

    /** Reads a file from the old S3 bucket with the server's AWS_* settings. */
    public static function legacyContent($key)
    {
        $bucket = Environment::getEnv('AWS_BUCKET_NAME');
        $id = Environment::getEnv('AWS_ACCESS_KEY_ID');
        $secret = Environment::getEnv('AWS_SECRET_ACCESS_KEY');

        if (!$bucket || !$id || !$secret) {
            return null;
        }

        $config = [
            'version' => 'latest',
            'region' => Environment::getEnv('AWS_REGION') ?: 'ap-southeast-2',
            'credentials' => ['key' => $id, 'secret' => $secret],
        ];

        // Only for testing against a local S3 stand-in.
        if ($endpoint = Environment::getEnv('AWS_S3_ENDPOINT')) {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        try {
            $result = (new S3Client($config))->getObject(['Bucket' => $bucket, 'Key' => $key]);
            $meta = $result->get('Metadata');

            return [
                'data' => (string) $result->get('Body'),
                'name' => $meta['original-filename'] ?? basename($key),
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The member's file of this type. The files are never published, and
     * front-end requests read the published (Live) stage, so read the draft
     * stage explicitly.
     */
    public static function file(Customer $customer, $type)
    {
        [$relation] = self::TYPES[$type];
        $id = (int) $customer->{$relation . 'ID'};

        if (!$id) {
            return null;
        }

        $file = Versioned::get_by_stage(File::class, Versioned::DRAFT)->byID($id);

        return $file && $file->exists() ? $file : null;
    }

    private static function sign($guid, $type, $fileId, $expires)
    {
        $secret = Environment::getEnv('OAUTH_ENCRYPTION_KEY');

        if (!$secret) {
            throw new Exception('OAUTH_ENCRYPTION_KEY is not set');
        }

        $key = hash_hmac('sha256', 'member-documents', $secret);

        return hash_hmac('sha256', "{$guid}|{$type}|{$fileId}|{$expires}", $key);
    }

    private static function extension($name)
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return in_array($ext, ['pdf', 'doc', 'docx'], true) ? $ext : null;
    }

    private static function cleanName($name)
    {
        $name = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string) $name));

        return mb_substr(trim($name), 0, 200) ?: 'document';
    }
}
