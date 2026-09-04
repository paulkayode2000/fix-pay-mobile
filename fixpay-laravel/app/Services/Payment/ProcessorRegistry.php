<?php

namespace App\Services\Payment;

/**
 * Registry of payment processors available to the payment-rail system.
 *
 * The Payfixy Gateway is the default/aggregator entry point: it transparently
 * routes each transaction to the configured processor (vtpass, paystack,
 * ninepsb, ...) based on the wallet_provider / rail config supplied by this
 * application. The admin UI renders these schemas dynamically so switching a
 * payment method to a different processor is a configuration change, not a
 * code change.
 */
class ProcessorRegistry
{
    public const PROCESSORS = [
        'payfixy_gateway' => [
            'processorId'    => 'payfixy_gateway',
            'displayName'    => 'Payfixy Gateway',
            'category'       => 'Aggregator',
            'description'    => 'Unified gateway that transparently routes each transaction to the configured processor.',
            'documentationUrl' => null,
            'fields' => [
                ['key' => 'baseUrl',    'label' => 'Base URL',    'type' => 'url',    'required' => true,  'placeholder' => 'http://localhost:8999'],
                ['key' => 'apiKey',     'label' => 'API Key',     'type' => 'secret', 'required' => true],
                ['key' => 'secretKey',  'label' => 'Secret Key',  'type' => 'secret', 'required' => true],
                ['key' => 'businessId', 'label' => 'Business ID', 'type' => 'text',   'required' => false],
            ],
        ],
        'vtpass' => [
            'processorId'    => 'vtpass',
            'displayName'    => 'VTPass',
            'category'       => 'Bill Payments',
            'description'    => 'Airtime, data, electricity, TV and other bill payments.',
            'documentationUrl' => 'https://vtpass.com/documentation',
            'fields' => [
                ['key' => 'apiKey',     'label' => 'API Key',     'type' => 'secret', 'required' => true],
                ['key' => 'secretKey',  'label' => 'Secret Key',  'type' => 'secret', 'required' => true],
                ['key' => 'publicKey',  'label' => 'Public Key',  'type' => 'secret', 'required' => false],
                ['key' => 'baseUrl',    'label' => 'Base URL',    'type' => 'url',    'required' => true,  'placeholder' => 'https://sandbox.vtpass.com/api'],
            ],
        ],
        'paystack' => [
            'processorId'    => 'paystack',
            'displayName'    => 'Paystack',
            'category'       => 'Transfers & Collections',
            'description'    => 'Bank transfers, payouts and payment collections.',
            'documentationUrl' => 'https://paystack.com/docs',
            'fields' => [
                ['key' => 'secretKey',  'label' => 'Secret Key',  'type' => 'secret', 'required' => true],
                ['key' => 'publicKey',  'label' => 'Public Key',  'type' => 'text',   'required' => false],
                ['key' => 'baseUrl',    'label' => 'Base URL',    'type' => 'url',    'required' => true,  'placeholder' => 'https://api.paystack.co'],
            ],
        ],
        'ninepsb' => [
            'processorId'    => 'ninepsb',
            'displayName'    => '9PSB Wallet-as-a-Service',
            'category'       => 'Wallet / Bank Accounts',
            'description'    => 'Wallet opening, debit/credit and KYC via 9 Payment Service Bank.',
            'documentationUrl' => null,
            'fields' => [
                ['key' => 'baseUrl',      'label' => 'Base URL',      'type' => 'url',    'required' => true,  'placeholder' => 'http://102.216.128.75:9090/waas'],
                ['key' => 'username',     'label' => 'Username',      'type' => 'text',   'required' => true],
                ['key' => 'password',     'label' => 'Password',      'type' => 'secret', 'required' => true],
                ['key' => 'clientId',     'label' => 'Client ID',     'type' => 'text',   'required' => true],
                ['key' => 'clientSecret', 'label' => 'Client Secret', 'type' => 'secret', 'required' => true],
            ],
        ],
    ];

    /** All registered processor ids. */
    public function ids(): array
    {
        return array_keys(self::PROCESSORS);
    }

    /** True when the processor id is registered. */
    public function has(string $processorId): bool
    {
        return array_key_exists($processorId, self::PROCESSORS);
    }

    /** The configuration schema for a processor, or null when unknown. */
    public function schema(string $processorId): ?array
    {
        return self::PROCESSORS[$processorId] ?? null;
    }
}