<?php

namespace App\Web\Layout;

use Page;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\HTMLEditor\HtmlEditorField;

/**
 * Public "support us" page.
 *
 * Card donations go through a Stripe Payment Link (a Stripe-hosted checkout
 * page created in the Stripe Dashboard), so the site never handles card data.
 * The same link is rendered as a QR code for people at meetups paying on their
 * phone. Bank-transfer details are optional and only shown when filled in.
 */
class DonatePage extends Page
{
    private static $table_name = 'DonatePage';

    private static $singular_name = 'Donate Page';

    private static $description = 'Donation page with a Stripe Payment Link, its QR code and optional bank details';

    private static $db = [
        'StripePaymentLink' => 'Varchar(255)',
        'BankAccountName' => 'Varchar(255)',
        'BankAccountNumber' => 'Varchar(64)',
        'BankTransferNote' => 'HTMLText',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->addFieldsToTab('Root.Donation', [
            TextField::create('StripePaymentLink', 'Stripe Payment Link')
                ->setDescription('A https://buy.stripe.com/... link created in Stripe Dashboard > Payment Links. Shown as a button and as a QR code.'),
            TextField::create('BankAccountName', 'Bank account name')
                ->setDescription('Optional. Bank details are only shown when both name and number are filled in.'),
            TextField::create('BankAccountNumber', 'Bank account number'),
            HtmlEditorField::create('BankTransferNote', 'Bank transfer instructions')
                ->setRows(6)
                ->setDescription('Optional, e.g. what to put in the reference field.'),
        ]);

        return $fields;
    }

    public function getData()
    {
        $showBank = !empty($this->BankAccountName) && !empty($this->BankAccountNumber);

        return array_merge(parent::getData(), [
            'stripe_payment_link' => $this->StripePaymentLink ?: null,
            'bank' => $showBank ? [
                'account_name' => $this->BankAccountName,
                'account_number' => $this->BankAccountNumber,
                'note' => $this->BankTransferNote,
            ] : null,
        ]);
    }
}
