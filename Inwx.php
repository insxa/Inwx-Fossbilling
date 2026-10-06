<?php

declare(strict_types=1);

/**
 * FOSSBilling INWX registrar adapter.
 *
 * Compatible with the current FOSSBilling Registrar_AdapterAbstract
 * interface and INWX DomRobot JSON-RPC API.
 *
 * Production API:
 *   https://api.domrobot.com/jsonrpc/
 *
 * INWX OT&E API:
 *   https://api.ote.domrobot.com/jsonrpc/
 *
 * SPDX-License-Identifier: Apache-2.0
 */

class Registrar_Adapter_Inwx extends Registrar_AdapterAbstract
{
    private const PRODUCTION_URL = 'https://api.domrobot.com/jsonrpc/';
    private const OTE_URL = 'https://api.ote.domrobot.com/jsonrpc/';
    private const SUCCESS_CODE = 1000;

    private array $config = [
        'username' => null,
        'password' => null,
        'environment' => 'production',
    ];

    private ?Symfony\Contracts\HttpClient\HttpClientInterface $client = null;
    private ?string $cookie = null;
    private bool $loggedIn = false;

    public function __construct($options)
    {
        $username = trim((string) ($options['username'] ?? ''));
        $password = (string) ($options['password'] ?? '');
        $environment = (string) ($options['environment'] ?? 'production');

        if ($username === '') {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [
                    ':domain_registrar' => 'INWX',
                    ':missing' => 'INWX username',
                ],
                3001
            );
        }

        if ($password === '') {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [
                    ':domain_registrar' => 'INWX',
                    ':missing' => 'INWX password',
                ],
                3001
            );
        }

        if (!in_array($environment, ['production', 'ote'], true)) {
            $environment = 'production';
        }

        $this->config['username'] = $username;
        $this->config['password'] = $password;
        $this->config['environment'] = $environment;
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains through the INWX DomRobot JSON-RPC API.',

            'form' => [
                'username' => [
                    'text',
                    [
                        'label' => 'INWX Username',
                        'description' => 'Your INWX account username.',
                        'required' => true,
                    ],
                ],

                'password' => [
                    'password',
                    [
                        'label' => 'INWX Password',
                        'description' => 'Your INWX account password.',
                        'required' => true,
                        'secret' => true,
                    ],
                ],

                'environment' => [
                    'select',
                    [
                        'label' => 'INWX API environment',
                        'description' => 'Choose whether this registrar uses INWX production or OT&E.',
                        'required' => true,
                        'multiOptions' => [
                            'production' => 'Production',
                            'ote' => 'OT&E (Test)',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function isOte(): bool
    {
        return $this->config['environment'] === 'ote';
    }

    private function getApiUrl(): string
    {
        return $this->isOte()
            ? self::OTE_URL
            : self::PRODUCTION_URL;
    }

    private function getClient(): Symfony\Contracts\HttpClient\HttpClientInterface
    {
        if ($this->client === null) {
            $this->client = $this->getHttpClient()->withOptions([
                'timeout' => 60,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }

    private function rememberCookies(array $headers): void
    {
        $setCookies = $headers['set-cookie'] ?? [];

        if (!is_array($setCookies)) {
            $setCookies = [$setCookies];
        }

        $cookies = [];

        if ($this->cookie !== null && $this->cookie !== '') {
            foreach (explode('; ', $this->cookie) as $existing) {
                if (str_contains($existing, '=')) {
                    $cookies[] = $existing;
                }
            }
        }

        foreach ($setCookies as $setCookie) {
            $pair = trim(explode(';', (string) $setCookie, 2)[0]);

            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }

            $name = strstr($pair, '=', true);

            $cookies = array_values(array_filter(
                $cookies,
                static fn (string $cookie): bool =>
                    !str_starts_with($cookie, $name . '=')
            ));

            $cookies[] = $pair;
        }

        $this->cookie = implode('; ', $cookies);
    }

    private function login(): void
    {
        if ($this->loggedIn) {
            return;
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('fossbilling-login-', true),
            'method' => 'account.login',
            'params' => [
                'user' => $this->config['username'],
                'pass' => $this->config['password'],
                'case-insensitive' => true,
            ],
        ];

        try {
            $response = $this->getClient()->request(
                'POST',
                $this->getApiUrl(),
                ['json' => $payload]
            );

            $data = $response->toArray(false);
            $this->rememberCookies($response->getHeaders(false));
        } catch (\Throwable $e) {
            $this->getLog()->error(
                'INWX authentication transport error: ' . $e->getMessage()
            );

            throw new Registrar_Exception(
                'Unable to authenticate with INWX: ' . $e->getMessage()
            );
        }

        $this->assertSuccess($data, 'INWX authentication failed');

        $this->loggedIn = true;
    }

    private function call(
        string $method,
        array $params = [],
        bool $retryLogin = true
    ): array {
        $this->login();

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('fossbilling-', true),
            'method' => $method,
            'params' => $params,
        ];

        $options = [
            'json' => $payload,
        ];

        if ($this->cookie !== null && $this->cookie !== '') {
            $options['headers']['Cookie'] = $this->cookie;
        }

        try {
            $response = $this->getClient()->request(
                'POST',
                $this->getApiUrl(),
                $options
            );

            $data = $response->toArray(false);
            $this->rememberCookies($response->getHeaders(false));
        } catch (\Throwable $e) {
            $this->getLog()->error(
                sprintf(
                    'INWX %s transport error: %s',
                    $method,
                    $e->getMessage()
                )
            );

            throw new Registrar_Exception(
                'Unable to communicate with INWX: ' . $e->getMessage()
            );
        }

        $code = (int) ($data['code'] ?? 0);

        if (
            $code !== self::SUCCESS_CODE
            && $retryLogin
            && $method !== 'account.login'
        ) {
            $reason = strtolower(
                (string) ($data['reason'] ?? $data['msg'] ?? '')
            );

            if (
                str_contains($reason, 'session')
                || str_contains($reason, 'login')
                || str_contains($reason, 'not logged')
                || $code === 2200
            ) {
                $this->loggedIn = false;

                return $this->call(
                    $method,
                    $params,
                    false
                );
            }
        }

        $this->assertSuccess(
            $data,
            'INWX API request failed: ' . $method
        );

        return (array) ($data['resData'] ?? []);
    }

    private function assertSuccess(
        array $data,
        string $prefix
    ): void {
        $code = (int) ($data['code'] ?? 0);

        if ($code === self::SUCCESS_CODE) {
            return;
        }

        $message = (string) (
            $data['reason']
            ?? $data['msg']
            ?? 'Unknown INWX API error'
        );

        $reasonCode = isset($data['reasonCode'])
            ? ' [' . $data['reasonCode'] . ']'
            : '';

        $trid = isset($data['svTRID'])
            ? ' (svTRID: ' . $data['svTRID'] . ')'
            : '';

        $this->getLog()->error(
            sprintf(
                '%s: %d%s %s%s',
                $prefix,
                $code,
                $reasonCode,
                $message,
                $trid
            )
        );

        throw new Registrar_Exception(
            sprintf(
                '%s: %d%s %s',
                $prefix,
                $code,
                $reasonCode,
                $message
            )
        );
    }

    private function nameservers(Registrar_Domain $domain): array
    {
        return array_values(array_filter([
            trim((string) $domain->getNs1()),
            trim((string) $domain->getNs2()),
            trim((string) $domain->getNs3()),
            trim((string) $domain->getNs4()),
        ], static fn (string $ns): bool => $ns !== ''));
    }

    private function contactName(
        Registrar_Domain_Contact $contact
    ): string {
        $name = trim((string) $contact->getName());

        if ($name !== '') {
            return $name;
        }

        return trim(
            (string) $contact->getFirstName()
            . ' '
            . (string) $contact->getLastName()
        );
    }

    private function contactParams(
        Registrar_Domain_Contact $contact
    ): array {
        $params = [
            'type' => 'Contact',
            'name' => $this->contactName($contact),
            'street' => (string) $contact->getAddress1(),
            'city' => (string) $contact->getCity(),
            'pc' => (string) $contact->getZip(),
            'cc' => strtoupper((string) $contact->getCountry()),
            'voice' => (string) $contact->getTel(),
            'email' => (string) $contact->getEmail(),
            'forceNew' => false,
        ];

        $company = trim((string) $contact->getCompany());
        $state = trim((string) $contact->getState());
        $fax = trim((string) $contact->getFax());

        if ($company !== '') {
            $params['org'] = $company;
        }

        if ($state !== '') {
            $params['sp'] = $state;
        }

        if ($fax !== '') {
            $params['fax'] = $fax;
        }

        return $params;
    }

    private function getContactHandle(
        Registrar_Domain_Contact $contact
    ): int {
        $email = trim((string) $contact->getEmail());

        if ($email !== '') {
            try {
                $result = $this->call('contact.list', [
                    'search' => $email,
                    'page' => 1,
                    'pagelimit' => 50,
                ]);

                $items = $result['contact'] ?? [];

                if (isset($items['roId'])) {
                    $items = [$items];
                }

                foreach ((array) $items as $item) {
                    if (
                        isset($item['roId'])
                        && isset($item['email'])
                        && strcasecmp(
                            (string) $item['email'],
                            $email
                        ) === 0
                    ) {
                        return (int) $item['roId'];
                    }
                }
            } catch (Registrar_Exception $e) {
                $this->getLog()->warning(
                    'INWX contact lookup failed: '
                    . $e->getMessage()
                );
            }
        }

        $result = $this->call(
            'contact.create',
            $this->contactParams($contact)
        );

        if (!isset($result['id'])) {
            throw new Registrar_Exception(
                'INWX did not return a contact handle ID.'
            );
        }

        return (int) $result['id'];
    }

    private function contactHandles(
        Registrar_Domain $domain
    ): array {
        $contact = $domain->getContactRegistrar();

        if (!$contact instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception(
                'A registrar contact is required for INWX domain operations.'
            );
        }

        $handle = $this->getContactHandle($contact);

        return [
            'registrant' => $handle,
            'admin' => $handle,
            'tech' => $handle,
            'billing' => $handle,
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function setContactFromInwx(
        Registrar_Domain $domain,
        mixed $data
    ): void {
        if (!is_array($data) || $data === []) {
            return;
        }

        $contact = new Registrar_Domain_Contact();

        $contact->setName((string) ($data['name'] ?? ''));
        $contact->setEmail((string) ($data['email'] ?? ''));
        $contact->setCompany((string) ($data['org'] ?? ''));
        $contact->setTel((string) ($data['voice'] ?? ''));
        $contact->setFax((string) ($data['fax'] ?? ''));
        $contact->setAddress1((string) ($data['street'] ?? ''));
        $contact->setCity((string) ($data['city'] ?? ''));
        $contact->setState((string) ($data['sp'] ?? ''));
        $contact->setZip((string) ($data['pc'] ?? ''));
        $contact->setCountry((string) ($data['cc'] ?? ''));

        if (isset($data['roId'])) {
            $contact->setId((int) $data['roId']);
        }

        $domain->setContactRegistrar($contact);
    }

    public function isDomainAvailable(
        Registrar_Domain $domain
    ): bool {
        $result = $this->call('domain.check', [
            'domain' => [$domain->getName()],
            'wide' => 1,
        ]);

        $items = $result['domain'] ?? [];

        if (isset($items['domain'])) {
            $items = [$items];
        }

        foreach ((array) $items as $item) {
            if (
                strcasecmp(
                    (string) ($item['domain'] ?? ''),
                    $domain->getName()
                ) === 0
            ) {
                return (int) ($item['avail'] ?? 0) === 1;
            }
        }

        throw new Registrar_Exception(
            'INWX returned no availability result for '
            . $domain->getName()
        );
    }

    public function isDomaincanBeTransferred(
        Registrar_Domain $domain
    ): bool {
        $result = $this->call('domain.check', [
            'domain' => [$domain->getName()],
            'wide' => 1,
        ]);

        $items = $result['domain'] ?? [];

        if (isset($items['domain'])) {
            $items = [$items];
        }

        foreach ((array) $items as $item) {
            if (
                strcasecmp(
                    (string) ($item['domain'] ?? ''),
                    $domain->getName()
                ) === 0
            ) {
                return (int) ($item['avail'] ?? 0) !== 1;
            }
        }

        return true;
    }

    public function modifyNs(
        Registrar_Domain $domain
    ): bool {
        $this->call('domain.update', [
            'domain' => $domain->getName(),
            'ns' => $this->nameservers($domain),
        ]);

        return true;
    }

    public function modifyContact(
        Registrar_Domain $domain
    ): bool {
        $handles = $this->contactHandles($domain);

        $this->call('domain.update', [
            'domain' => $domain->getName(),
            'registrant' => $handles['registrant'],
            'admin' => $handles['admin'],
            'tech' => $handles['tech'],
            'billing' => $handles['billing'],
        ]);

        return true;
    }

    public function transferDomain(
        Registrar_Domain $domain
    ): bool {
        $handles = $this->contactHandles($domain);

        $params = [
            'domain' => $domain->getName(),
            'registrant' => $handles['registrant'],
            'admin' => $handles['admin'],
            'tech' => $handles['tech'],
            'billing' => $handles['billing'],
            'ns' => $this->nameservers($domain),
            'nsTakeover' => false,
            'contactTakeover' => false,
            'transferLock' => true,
        ];

        $epp = trim((string) $domain->getEpp());

        if ($epp !== '') {
            $params['authCode'] = $epp;
        }

        $this->call('domain.transfer', $params);

        return true;
    }

    public function getDomainDetails(
        Registrar_Domain $domain
    ) {
        $data = $this->call('domain.info', [
            'domain' => $domain->getName(),
            'wide' => 1,
        ]);

        if (isset($data['crDate'])) {
            $domain->setRegistrationTime(
                $this->parseDate($data['crDate'])
            );
        }

        if (isset($data['exDate'])) {
            $domain->setExpirationTime(
                $this->parseDate($data['exDate'])
            );
        }

        if (isset($data['authCode'])) {
            $domain->setEpp((string) $data['authCode']);
        }

        if (isset($data['transferLock'])) {
            $domain->setLocked((bool) $data['transferLock']);
        }

        $ns = $data['ns'] ?? [];

        if (is_array($ns)) {
            $ns = array_values(array_filter(
                array_map(
                    static fn ($value): string =>
                        trim((string) $value),
                    $ns
                )
            ));

            $domain->setNs1($ns[0] ?? null);
            $domain->setNs2($ns[1] ?? null);
            $domain->setNs3($ns[2] ?? null);
            $domain->setNs4($ns[3] ?? null);
        }

        if (isset($data['contact']['registrant'])) {
            $this->setContactFromInwx(
                $domain,
                $data['contact']['registrant']
            );
        }

        return $domain;
    }

    public function getEpp(
        Registrar_Domain $domain
    ) {
        $data = $this->call('domain.info', [
            'domain' => $domain->getName(),
            'wide' => 1,
        ]);

        if (!isset($data['authCode'])) {
            throw new Registrar_Exception(
                'INWX did not return an authorization code for '
                . $domain->getName()
            );
        }

        return (string) $data['authCode'];
    }

    public function registerDomain(
        Registrar_Domain $domain
    ): bool {
        $handles = $this->contactHandles($domain);

        $params = [
            'domain' => $domain->getName(),
            'period' => max(
                1,
                (int) $domain->getRegistrationPeriod()
            ),
            'registrant' => $handles['registrant'],
            'admin' => $handles['admin'],
            'tech' => $handles['tech'],
            'billing' => $handles['billing'],
            'ns' => $this->nameservers($domain),
            'transferLock' => true,
            'asynchron' => false,
        ];

        $this->call('domain.create', $params);

        return true;
    }

    public function renewDomain(
        Registrar_Domain $domain
    ): bool {
        if (empty($domain->getExpirationTime())) {
            $this->getDomainDetails($domain);
        }

        $expiration = $domain->getExpirationTime();

        if (empty($expiration)) {
            throw new Registrar_Exception(
                'INWX requires the current expiration date for domain renewal.'
            );
        }

        $expirationDate = (new \DateTimeImmutable(
            (string) $expiration
        ))->format('Y-m-d');

        $this->call('domain.renew', [
            'domain' => $domain->getName(),
            'period' => max(
                1,
                (int) $domain->getRegistrationPeriod()
            ),
            'expiration' => $expirationDate,
            'asynchron' => false,
        ]);

        return true;
    }

    public function deleteDomain(
        Registrar_Domain $domain
    ): bool {
        $this->call('domain.delete', [
            'domain' => $domain->getName(),
        ]);

        return true;
    }

public function enablePrivacyProtection(
    Registrar_Domain $domain
): bool {
    /*
     * INWX does not expose a generic registrar-level privacy switch.
     * Privacy/WHOIS publication is controlled by the individual registry.
     *
     * Do not fail the FOSSBilling operation here. The actual privacy
     * behavior is determined by the TLD and the contact information
     * submitted to INWX.
     */
    $this->getLog()->info(
        'INWX privacy protection is registry/TLD controlled for '
        . $domain->getName()
    );

    return true;
}

public function disablePrivacyProtection(
    Registrar_Domain $domain
): bool {
    /*
     * INWX does not expose a generic registrar-level privacy switch.
     * There is therefore nothing to toggle at the registrar level.
     */
    $this->getLog()->info(
        'INWX privacy protection disable requested for '
        . $domain->getName()
        . '; privacy is controlled by the registry/TLD.'
    );

    return true;
}

    public function lock(
        Registrar_Domain $domain
    ): bool {
        $this->call('domain.update', [
            'domain' => $domain->getName(),
            'transferLock' => true,
        ]);

        return true;
    }

    public function unlock(
        Registrar_Domain $domain
    ): bool {
        $this->call('domain.update', [
            'domain' => $domain->getName(),
            'transferLock' => false,
        ]);

        return true;
    }
}
