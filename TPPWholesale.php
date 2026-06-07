<?php

declare(strict_types=1);

/**
 * TPP Wholesale Domain Registrar Adapter for FOSSBilling
 *
 * @copyright ServMe IT Limited (https://www.servmeit.co.nz)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 * @link https://github.com/grant436/fossbilling-tpp-wholesale
 */

class Registrar_Adapter_TPPWholesale extends Registrar_AdapterAbstract
{
    // TPP API base URL
    private const API_BASE = 'https://theconsole.tppwholesale.com.au/api/';

    // Credentials stored from config
    private string $accountNo;
    private string $userId;
    private string $password;
    private string $accountRef;

    /**
     * Constructor - receives config values from FOSSBilling admin settings
     */
    public function __construct(array $options)
    {
        if (!isset($options['account_no'], $options['user_id'], $options['password'])) {
            throw new Registrar_Exception('TPP Wholesale credentials are not configured.');
        }

        $this->accountNo  = $options['account_no'];
        $this->userId     = $options['user_id'];
        $this->password   = $options['password'];
        $this->accountRef = $options['account_ref'] ?? '';

        // Auto-derive account reference from User ID if not explicitly set
        // e.g. "SER-993-API" becomes "SER-993"
        if (empty($this->accountRef) && str_ends_with($this->userId, '-API')) {
            $this->accountRef = substr($this->userId, 0, -4);
        }

        // Honour FOSSBilling test mode flag
        if (isset($options['test_mode']) && $options['test_mode']) {
            $this->_testMode = true;
        }
    }

    /**
     * Defines the settings form shown in FOSSBilling admin panel
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'TPP Wholesale — ANZ domain registrar (tppwholesale.com.au)',
            'form'  => [
                'account_no' => ['text', [
                    'label'    => 'Account Number',
                    'required' => true,
                ]],
                'user_id' => ['text', [
                    'label'    => 'User ID (API Login)',
                    'required' => true,
                ]],
                'password' => ['password', [
                    'label'    => 'Password (API Password)',
                    'required' => true,
                ]],
                'account_ref' => ['text', [
                    'label'    => 'Console Account Reference (e.g. SER-993)',
                    'required' => false,
                ]],
            ],
        ];
    }

    /**
     * Log a message at INFO level, prefixed with test mode indicator if active
     */
    private function log(string $message): void
    {
        $prefix = $this->_testMode ? '[TEST MODE] ' : '';
        $this->getLog()->info($prefix . $message);
    }

    /**
     * Authenticate with TPP API and return a session ID.
     * In test mode, returns a fake session ID without calling the API.
     */
    private function authenticate(): string
    {
        if ($this->_testMode) {
            $this->log('TPP authenticate: skipping — test mode active, returning fake session ID');
            return 'TEST-SESSION-ID';
        }

        $url = self::API_BASE . 'auth.pl?' . http_build_query([
            'AccountNo' => $this->accountNo,
            'UserId'    => $this->userId,
            'Password'  => $this->password,
        ]);

        $response = $this->httpGet($url);
        $this->log('TPP authenticate response: ' . $response);

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP Wholesale authentication failed: ' . $response);
        }

        return trim(substr($response, 3));
    }

    /**
     * Perform a GET request to the TPP API.
     * In test mode, logs the URL and returns a fake OK response.
     */
    private function httpGet(string $url): string
    {
        if ($this->_testMode) {
            $this->log('TPP httpGet [TEST MODE — not sent]: ' . $url);
            return 'OK: TEST-RESPONSE';
        }

        $client   = $this->getHttpClient();
        $response = $client->request('GET', $url);

        return trim($response->getContent());
    }

    /**
     * Parse a TPP response — throws exception on error, returns value on OK
     */
    private function parseResponse(string $response): string
    {
        if (str_starts_with($response, 'ERR:')) {
            throw new Registrar_Exception('TPP Wholesale error: ' . $response);
        }

        if (str_starts_with($response, 'OK:')) {
            return trim(substr($response, 3));
        }

        // Some responses are multi-line — return as-is
        return $response;
    }

    /**
     * Check if a domain is available for registration
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $this->log('TPP isDomainAvailable: ' . $domain->getName());

        $sessionId = $this->authenticate();

        $url = self::API_BASE . 'query.pl?' . http_build_query([
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Availability',
            'Domain'    => $domain->getName(),
        ]);

        $response = $this->httpGet($url);
        $this->log('TPP isDomainAvailable response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP isDomainAvailable: returning true in test mode');
            return true;
        }

        if (str_contains($response, 'OK:')) {
            return true;
        }

        if (str_contains($response, '304')) {
            return false;
        }

        throw new Registrar_Exception('TPP Wholesale availability check failed: ' . $response);
    }

    /**
     * Check if a domain can be transferred to TPP Wholesale
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $this->log('TPP isDomaincanBeTransferred: ' . $domain->getName());

        $sessionId = $this->authenticate();

        $url = self::API_BASE . 'query.pl?' . http_build_query([
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Transfer',
            'Domain'    => $domain->getName(),
        ]);

        $response = $this->httpGet($url);
        $this->log('TPP isDomaincanBeTransferred response: ' . $response);

        if ($this->_testMode) {
            return true;
        }

        return str_contains($response, 'OK:');
    }

    /**
     * Create a contact in TPP and return the Contact ID.
     * In test mode, logs all fields and returns a fake contact ID.
     */
    private function createContact(string $sessionId, Registrar_Domain $domain): string
    {
        $contact = $domain->getContactRegistrar();

        // Log all contact details for debugging
        $this->log('TPP createContact details: ' . json_encode([
            'firstName'  => $contact->getFirstName(),
            'lastName'   => $contact->getLastName(),
            'email'      => $contact->getEmail(),
            'company'    => $contact->getCompany(),
            'address1'   => $contact->getAddress1(),
            'city'       => $contact->getCity(),
            'state'      => $contact->getState(),
            'zip'        => $contact->getZip(),
            'country'    => $contact->getCountry(),
            'telCc'      => $contact->getTelCc(),
            'tel'        => $contact->getTel(),
        ]));

        // Use getTelCc() and getTel() separately — stored as separate fields in FOSSBilling
        $phoneCountryCode = $contact->getTelCc() ?? '64';
        $phoneNumber      = $contact->getTel() ?? '000000000';

        $phoneCountryCode = preg_replace('/[^0-9]/', '', $phoneCountryCode);
        $phoneNumber      = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (empty($phoneCountryCode)) $phoneCountryCode = '64';
        if (empty($phoneNumber))      $phoneNumber      = '000000000';

        $params = [
            'SessionID'        => $sessionId,
            'Type'             => 'Domains',
            'Object'           => 'Contact',
            'Action'           => 'Create',
            'FirstName'        => $contact->getFirstName() ?? 'Unknown',
            'LastName'         => $contact->getLastName() ?? 'Unknown',
            'Address1'         => $contact->getAddress1() ?? 'Unknown',
            'Address2'         => $contact->getAddress2() ?? '',
            'City'             => $contact->getCity() ?? 'Unknown',
            'Region'           => $contact->getState() ?? 'N/A',
            'PostalCode'       => $contact->getZip() ?? '0000',
            'CountryCode'      => $contact->getCountry() ?? 'NZ',
            'PhoneCountryCode' => $phoneCountryCode,
            'PhoneAreaCode'    => '0',
            'PhoneNumber'      => $phoneNumber,
            'Email'            => $contact->getEmail() ?? 'unknown@unknown.com',
        ];

        if ($contact->getCompany()) {
            $params['OrganisationName'] = $contact->getCompany();
        }

        $this->log('TPP createContact params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP createContact response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP createContact: returning fake contact ID in test mode');
            return 'TEST-CONTACT-ID';
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP contact creation failed: ' . $response);
        }

        $contactId = trim(substr($response, 3));
        $this->log('TPP createContact: created contact ID ' . $contactId);

        return $contactId;
    }

    /**
     * Determine if a TLD requires .au eligibility fields
     */
    private function isAuDomain(string $domainName): bool
    {
        return str_ends_with($domainName, '.com.au')
            || str_ends_with($domainName, '.net.au')
            || str_ends_with($domainName, '.org.au')
            || str_ends_with($domainName, '.au');
    }

    /**
     * Register a new domain with TPP Wholesale
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->log('TPP registerDomain: ' . $domain->getName() . ' for ' . $domain->getRegistrationPeriod() . ' year(s)');

        $sessionId = $this->authenticate();
        $contactId = $this->createContact($sessionId, $domain);

        $params = [
            'SessionID'               => $sessionId,
            'Type'                    => 'Domains',
            'Object'                  => 'Domain',
            'Action'                  => 'Create',
            'Domain'                  => $domain->getName(),
            'Period'                  => $domain->getRegistrationPeriod(),
            'AccountOption'           => 'CONSOLE',
            'AccountID'               => $this->accountRef,
            'OwnerContactID'          => $contactId,
            'AdministrationContactID' => $contactId,
            'TechnicalContactID'      => $contactId,
            'BillingContactID'        => $contactId,
        ];

        $nameservers = array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]);

        $this->log('TPP registerDomain nameservers: ' . json_encode($nameservers));

        $query = http_build_query($params);
        foreach ($nameservers as $ns) {
            $query .= '&Host=' . urlencode($ns);
        }

        if ($this->isAuDomain($domain->getName())) {
            $auFields = $this->getAuEligibilityFields($domain);
            $this->log('TPP registerDomain .au eligibility fields: ' . json_encode($auFields));
            foreach ($auFields as $key => $value) {
                $query .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        $this->log('TPP registerDomain full query: ' . $query);

        $url      = self::API_BASE . 'order.pl?' . $query;
        $response = $this->httpGet($url);

        $this->log('TPP registerDomain response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP registerDomain: test mode — domain NOT registered with TPP');
            return true;
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain registration failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->log('TPP registerDomain: order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Get .au eligibility fields from domain additional fields
     */
    private function getAuEligibilityFields(Registrar_Domain $domain): array
    {
        return [
            'RegistrantName'    => $domain->getContactRegistrar()->getCompany()
                                   ?? $domain->getContactRegistrar()->getFirstName() . ' '
                                   . $domain->getContactRegistrar()->getLastName(),
            'RegistrantID'      => $domain->getParam('abn') ?? '',
            'RegistrantIDType'  => '2', // ABN
            'EligibilityType'   => '5', // Company
            'EligibilityReason' => '2', // Close and substantial connection
        ];
    }

    /**
     * Renew an existing domain registration
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->log('TPP renewDomain: ' . $domain->getName() . ' for ' . $domain->getRegistrationPeriod() . ' year(s)');

        $sessionId = $this->authenticate();

        $params = [
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Renewal',
            'Domain'    => $domain->getName(),
            'Period'    => $domain->getRegistrationPeriod(),
        ];

        $this->log('TPP renewDomain params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP renewDomain response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP renewDomain: test mode — domain NOT renewed with TPP');
            return true;
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain renewal failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->log('TPP renewDomain: order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Transfer a domain from another registrar to TPP Wholesale
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->log('TPP transferDomain: ' . $domain->getName());

        $sessionId = $this->authenticate();
        $contactId = $this->createContact($sessionId, $domain);

        $params = [
            'SessionID'               => $sessionId,
            'Type'                    => 'Domains',
            'Object'                  => 'Domain',
            'Action'                  => 'TransferRequest',
            'AccountOption'           => 'CONSOLE',
            'AccountID'               => $this->accountRef,
            'Domain'                  => $domain->getName(),
            'DomainPassword'          => $domain->getEpp(),
            'OwnerContactID'          => $contactId,
            'AdministrationContactID' => $contactId,
            'TechnicalContactID'      => $contactId,
            'BillingContactID'        => $contactId,
        ];

        if ($domain->getRegistrationPeriod()) {
            $params['Period'] = $domain->getRegistrationPeriod();
        }

        $this->log('TPP transferDomain params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP transferDomain response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP transferDomain: test mode — domain NOT transferred with TPP');
            return true;
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain transfer failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->log('TPP transferDomain: order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Get full details of a registered domain from TPP
     */
    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $this->log('TPP getDomainDetails: ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Details',
            'Domain'    => $domain->getName(),
        ];

        $url      = self::API_BASE . 'query.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP getDomainDetails response: ' . $response);

        if ($this->_testMode) {
            $this->log('TPP getDomainDetails: test mode — returning domain object unchanged');
            if (!$domain->getRegistrationTime()) {
                $domain->setRegistrationTime(time());
            }
            if (!$domain->getExpirationTime()) {
                $years = $domain->getRegistrationPeriod();
                $domain->setExpirationTime(strtotime("+$years year"));
            }
            return $domain;
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain details failed: ' . $response);
        }

        $data = $this->parseKeyValueResponse($response);

        if (isset($data['ExpiryDate'])) {
            $domain->setExpirationTime(strtotime($data['ExpiryDate']));
        }

        if (isset($data['Nameserver'])) {
            $nameservers = is_array($data['Nameserver'])
                ? $data['Nameserver']
                : [$data['Nameserver']];

            if (isset($nameservers[0])) $domain->setNs1($nameservers[0]);
            if (isset($nameservers[1])) $domain->setNs2($nameservers[1]);
            if (isset($nameservers[2])) $domain->setNs3($nameservers[2]);
            if (isset($nameservers[3])) $domain->setNs4($nameservers[3]);
        }

        if (isset($data['LockStatus'])) {
            $domain->setLocked($data['LockStatus'] === '2');
        }

        return $domain;
    }

    /**
     * Parse TPP's key=value multi-line response format into an array.
     * Handles duplicate keys (like multiple Nameserver entries) as arrays.
     */
    private function parseKeyValueResponse(string $response): array
    {
        $data  = [];
        $lines = explode("\n", $response);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === 'OK:' || $line === '') {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if (isset($data[$key])) {
                if (!is_array($data[$key])) {
                    $data[$key] = [$data[$key]];
                }
                $data[$key][] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Update nameservers for a domain
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->log('TPP modifyNs: ' . $domain->getName());

        $nameservers = array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]);

        $this->log('TPP modifyNs nameservers: ' . json_encode($nameservers));

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateHosts',
            'Domain'     => $domain->getName(),
            'RemoveHost' => 'ALL',
        ];

        $query = http_build_query($params);
        foreach ($nameservers as $ns) {
            $query .= '&AddHost=' . urlencode($ns);
        }

        $this->log('TPP modifyNs query: ' . $query);

        $url      = self::API_BASE . 'order.pl?' . $query;
        $response = $this->httpGet($url);

        $this->log('TPP modifyNs response: ' . $response);

        if ($this->_testMode) {
            return true;
        }

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP nameserver update failed: ' . $response);
        }

        return true;
    }

    /**
     * Update contact details for a domain
     */
    public function modifyContact(Registrar_Domain $domain): bool
    {
        $this->log('TPP modifyContact: ' . $domain->getName());

        $sessionId = $this->authenticate();
        $contactId = $this->createContact($sessionId, $domain);

        $params = [
            'SessionID'               => $sessionId,
            'Type'                    => 'Domains',
            'Object'                  => 'Domain',
            'Action'                  => 'UpdateContacts',
            'Domain'                  => $domain->getName(),
            'OwnerContactID'          => $contactId,
            'AdministrationContactID' => $contactId,
            'TechnicalContactID'      => $contactId,
            'BillingContactID'        => $contactId,
        ];

        $this->log('TPP modifyContact params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP modifyContact response: ' . $response);

        if ($this->_testMode) {
            return true;
        }

        if (!str_starts_with($response, 'OK:')) {
            // .nz domains do not support contact updates via API
            if (str_ends_with($domain->getName(), '.nz')) {
                $this->log('TPP modifyContact: contact update not supported for .nz domains');
                return true;
            }
            throw new Registrar_Exception('TPP contact update failed: ' . $response);
        }

        return true;
    }

    /**
     * Get the EPP/auth code for a domain (used for outgoing transfers)
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        $this->log('TPP getEpp: ' . $domain->getName());

        if ($this->_testMode) {
            $this->log('TPP getEpp: returning fake EPP code in test mode');
            return 'TEST-EPP-CODE';
        }

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'     => $sessionId,
            'Type'          => 'Domains',
            'Object'        => 'Domain',
            'Action'        => 'SyncPass',
            'Domain'        => $domain->getName(),
            'resetIfVirgin' => 'True',
        ];

        $url      = self::API_BASE . 'query.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP getEpp response: ' . $response);

        if (!str_contains($response, 'OK:')) {
            throw new Registrar_Exception('TPP EPP retrieval failed: ' . $response);
        }

        if (preg_match('/Pass=([^&\s]+)/', $response, $matches)) {
            return $matches[1];
        }

        throw new Registrar_Exception('TPP: Could not parse EPP code from response: ' . $response);
    }

    /**
     * Lock a domain to prevent transfer
     */
    public function lock(Registrar_Domain $domain): bool
    {
        $this->log('TPP lock: ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateDomainLock',
            'Domain'     => $domain->getName(),
            'DomainLock' => 'Lock',
        ];

        $this->log('TPP lock params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP lock response: ' . $response);

        if (!$this->_testMode && !str_starts_with($response, 'OK:')) {
            $this->log('TPP lock: not supported or failed: ' . $response);
        }

        return true;
    }

    /**
     * Unlock a domain to allow transfer
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        $this->log('TPP unlock: ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateDomainLock',
            'Domain'     => $domain->getName(),
            'DomainLock' => 'Unlock',
        ];

        $this->log('TPP unlock params: ' . json_encode($params));

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        $this->log('TPP unlock response: ' . $response);

        if (!$this->_testMode && !str_starts_with($response, 'OK:')) {
            $this->log('TPP unlock: not supported or failed: ' . $response);
        }

        return true;
    }

    /**
     * Delete a domain — TPP does not support direct deletion via API.
     * Logs the request and returns true to avoid breaking FOSSBilling workflow.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        $this->log('TPP deleteDomain: ' . $domain->getName() . ' — must be completed manually in TPP console');
        return true;
    }

    /**
     * Privacy protection — not supported by TPP Wholesale
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->log('TPP enablePrivacyProtection: not supported for ' . $domain->getName());
        return true;
    }

    /**
     * Privacy protection — not supported by TPP Wholesale
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->log('TPP disablePrivacyProtection: not supported for ' . $domain->getName());
        return true;
    }

} // End of class