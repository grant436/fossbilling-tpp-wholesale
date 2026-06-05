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

    /**
     * Constructor - receives config values from FOSSBilling admin settings
     */
    public function __construct(array $options)
    {
        if (!isset($options['account_no'], $options['user_id'], $options['password'])) {
            throw new Registrar_Exception('TPP Wholesale credentials are not configured.');
        }

        $this->accountNo = $options['account_no'];
        $this->userId    = $options['user_id'];
        $this->password  = $options['password'];
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
            ],
        ];
    }

    /**
     * Authenticate with TPP API and return a session ID
     * Session expires after 15 minutes of inactivity
     */
    private function authenticate(): string
    {
        $url = self::API_BASE . 'auth.pl?' . http_build_query([
            'AccountNo' => $this->accountNo,
            'UserId'    => $this->userId,
            'Password'  => $this->password,
        ]);

        $response = $this->httpGet($url);

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP Wholesale authentication failed: ' . $response);
        }

        // Response is "OK: SessionID" — extract the session ID
        return trim(substr($response, 3));
    }

    /**
     * Perform a GET request to the TPP API
     */
    private function httpGet(string $url): string
    {
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
        $this->getLog()->debug('TPP: Checking availability for ' . $domain->getName());

        $sessionId = $this->authenticate();

        $url = self::API_BASE . 'query.pl?' . http_build_query([
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Availability',
            'Domain'    => $domain->getName(),
        ]);

        $response = $this->httpGet($url);

        // Response format: "domain.co.nz: OK: Minimum=1&Maximum=10"
        // or "domain.co.nz: ERR: 304,Domain is not available"
        $this->getLog()->debug('TPP availability response: ' . $response);

        // Domain is available if response contains OK:
        if (str_contains($response, 'OK:')) {
            return true;
        }

        // ERR: 304 means taken, anything else is a real error
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
        $this->getLog()->debug('TPP: Checking transfer eligibility for ' . $domain->getName());

        $sessionId = $this->authenticate();

        $url = self::API_BASE . 'query.pl?' . http_build_query([
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Transfer',
            'Domain'    => $domain->getName(),
        ]);

        $response = $this->httpGet($url);

        if (str_contains($response, 'OK:')) {
            return true;
        }

        return false;
    }

    /**
     * Create a contact in TPP and return the Contact ID
     * This must be called before registering or transferring a domain
     */
    private function createContact(string $sessionId, Registrar_Domain $domain): string
    {
        $contact = $domain->getContactRegistrar();

        // Split phone number into components TPP requires
        // FOSSBilling stores phone as e.g. +6421123456
        $phone = preg_replace('/[^0-9]/', '', $contact->getTel());

        // Determine country code and local number
        // NZ = 64, AU = 61
        $phoneCountryCode = '64';
        $phoneAreaCode    = '0';
        $phoneNumber      = $phone;

        if (str_starts_with($phone, '64')) {
            $phoneCountryCode = '64';
            $phoneAreaCode    = '0';
            $phoneNumber      = substr($phone, 2);
        } elseif (str_starts_with($phone, '61')) {
            $phoneCountryCode = '61';
            $phoneAreaCode    = '0';
            $phoneNumber      = substr($phone, 2);
        }

        $params = [
            'SessionID'        => $sessionId,
            'Type'             => 'Domains',
            'Object'           => 'Contact',
            'Action'           => 'Create',
            'FirstName'        => $contact->getFirstName(),
            'LastName'         => $contact->getLastName(),
            'Address1'         => $contact->getAddress1(),
            'Address2'         => $contact->getAddress2() ?? '',
            'City'             => $contact->getCity(),
            'Region'           => $contact->getState() ?? 'N/A',
            'PostalCode'       => $contact->getZip(),
            'CountryCode'      => $contact->getCountry(),
            'PhoneCountryCode' => $phoneCountryCode,
            'PhoneAreaCode'    => $phoneAreaCode,
            'PhoneNumber'      => $phoneNumber,
            'Email'            => $contact->getEmail(),
        ];

        // Add organisation if present
        if ($contact->getCompany()) {
            $params['OrganisationName'] = $contact->getCompany();
        }

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        // Response: "OK: ContactID"
        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP contact creation failed: ' . $response);
        }

        $contactId = trim(substr($response, 3));
        $this->getLog()->debug('TPP: Created contact ID ' . $contactId);

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
        $this->getLog()->debug('TPP: Registering domain ' . $domain->getName());

        $sessionId = $this->authenticate();

        // Step 1: Create contact
        $contactId = $this->createContact($sessionId, $domain);

        // Step 2: Build registration params
        $params = [
            'SessionID'              => $sessionId,
            'Type'                   => 'Domains',
            'Object'                 => 'Domain',
            'Action'                 => 'Create',
            'Domain'                 => $domain->getName(),
            'Period'                 => $domain->getRegistrationPeriod(),
            'OwnerContactID'         => $contactId,
            'AdministrationContactID'=> $contactId,
            'TechnicalContactID'     => $contactId,
            'BillingContactID'       => $contactId,
        ];

        // Step 3: Add nameservers if provided
        $nameservers = array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]);

        // TPP uses multiple Host params — build query manually
        $query = http_build_query($params);
        foreach ($nameservers as $ns) {
            $query .= '&Host=' . urlencode($ns);
        }

        // Step 4: Add .au eligibility fields if required
        if ($this->isAuDomain($domain->getName())) {
            $auFields = $this->getAuEligibilityFields($domain);
            foreach ($auFields as $key => $value) {
                $query .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        $url      = self::API_BASE . 'order.pl?' . $query;
        $response = $this->httpGet($url);

        // Response: "OK: OrderID"
        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain registration failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->getLog()->debug('TPP: Registration order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Get .au eligibility fields from domain additional fields
     * These are collected on the order form for .au domains
     */
    private function getAuEligibilityFields(Registrar_Domain $domain): array
    {
        // These come from custom order form fields we'll add later
        // For now return sensible defaults that work for most business registrants
        return [
            'RegistrantName'   => $domain->getContactRegistrar()->getCompany()
                                  ?? $domain->getContactRegistrar()->getFirstName() . ' '
                                  . $domain->getContactRegistrar()->getLastName(),
            'RegistrantID'     => $domain->getParam('abn') ?? '',
            'RegistrantIDType' => '2', // ABN
            'EligibilityType'  => '5', // Company
            'EligibilityReason'=> '2', // Close and substantial connection
        ];
    }

    /**
     * Renew an existing domain registration
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Renewing domain ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'Renewal',
            'Domain'    => $domain->getName(),
            'Period'    => $domain->getRegistrationPeriod(),
        ];

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain renewal failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->getLog()->debug('TPP: Renewal order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Transfer a domain from another registrar to TPP Wholesale
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Transferring domain ' . $domain->getName());

        $sessionId = $this->authenticate();

        // Create contact first
        $contactId = $this->createContact($sessionId, $domain);

        $params = [
            'SessionID'               => $sessionId,
            'Type'                    => 'Domains',
            'Object'                  => 'Domain',
            'Action'                  => 'TransferRequest',
            'Domain'                  => $domain->getName(),
            'DomainPassword'          => $domain->getEpp(),
            'OwnerContactID'          => $contactId,
            'AdministrationContactID' => $contactId,
            'TechnicalContactID'      => $contactId,
            'BillingContactID'        => $contactId,
        ];

        // Add renewal period if specified
        if ($domain->getRegistrationPeriod()) {
            $params['Period'] = $domain->getRegistrationPeriod();
        }

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain transfer failed: ' . $response);
        }

        $orderId = trim(substr($response, 3));
        $this->getLog()->debug('TPP: Transfer order placed, Order ID: ' . $orderId);

        return true;
    }

    /**
     * Get full details of a registered domain from TPP
     */
    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $this->getLog()->debug('TPP: Getting details for ' . $domain->getName());

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

        if (!str_starts_with($response, 'OK:')) {
            throw new Registrar_Exception('TPP domain details failed: ' . $response);
        }

        // Response is multi-line key=value pairs after "OK:"
        // e.g. ExpiryDate=2026-06-05\nNameserver=ns1.tppwholesale.com.au\n...
        $data = $this->parseKeyValueResponse($response);

        // Set expiry date if available
        if (isset($data['ExpiryDate'])) {
            $domain->setExpirationTime(strtotime($data['ExpiryDate']));
        }

        // Set nameservers if available
        if (isset($data['Nameserver'])) {
            $nameservers = is_array($data['Nameserver'])
                ? $data['Nameserver']
                : [$data['Nameserver']];

            if (isset($nameservers[0])) $domain->setNs1($nameservers[0]);
            if (isset($nameservers[1])) $domain->setNs2($nameservers[1]);
            if (isset($nameservers[2])) $domain->setNs3($nameservers[2]);
            if (isset($nameservers[3])) $domain->setNs4($nameservers[3]);
        }

        // Set lock status
        if (isset($data['LockStatus'])) {
            $domain->setLocked($data['LockStatus'] === '2');
        }

        return $domain;
    }

    /**
     * Parse TPP's key=value multi-line response format into an array
     * Handles duplicate keys (like multiple Nameserver entries) as arrays
     */
    private function parseKeyValueResponse(string $response): array
    {
        $data  = [];
        $lines = explode("\n", $response);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip the OK: header line
            if ($line === 'OK:' || $line === '') {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Handle duplicate keys — TPP returns multiple Nameserver lines
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
        $this->getLog()->debug('TPP: Updating nameservers for ' . $domain->getName());

        $sessionId = $this->authenticate();

        // First remove all existing hosts, then add new ones
        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateHosts',
            'Domain'     => $domain->getName(),
            'RemoveHost' => 'ALL',
        ];

        // Build query manually to support multiple AddHost params
        $query = http_build_query($params);

        $nameservers = array_filter([
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ]);

        foreach ($nameservers as $ns) {
            $query .= '&AddHost=' . urlencode($ns);
        }

        $url      = self::API_BASE . 'order.pl?' . $query;
        $response = $this->httpGet($url);

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
        $this->getLog()->debug('TPP: Updating contact for ' . $domain->getName());

        $sessionId = $this->authenticate();

        // Create a new contact with updated details
        $contactId = $this->createContact($sessionId, $domain);

        // Assign new contact to all roles on the domain
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

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        // Note from API docs: update of contact will not work for .nz domains
        // We log but don't throw for .nz to avoid breaking the workflow
        if (!str_starts_with($response, 'OK:')) {
            if (str_ends_with($domain->getName(), '.nz')) {
                $this->getLog()->debug('TPP: Contact update not supported for .nz domains');
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
        $this->getLog()->debug('TPP: Getting EPP code for ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID' => $sessionId,
            'Type'      => 'Domains',
            'Object'    => 'Domain',
            'Action'    => 'SyncPass',
            'Domain'    => $domain->getName(),
            'resetIfVirgin' => 'True',
        ];

        $url      = self::API_BASE . 'query.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        // Response: "domain.co.nz: OK: User=xxx&Pass=yyy"
        if (!str_contains($response, 'OK:')) {
            throw new Registrar_Exception('TPP EPP retrieval failed: ' . $response);
        }

        // Extract the Pass value
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
        $this->getLog()->debug('TPP: Locking domain ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateDomainLock',
            'Domain'     => $domain->getName(),
            'DomainLock' => 'Lock',
        ];

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        if (!str_starts_with($response, 'OK:')) {
            // Some TLDs don't support locking — log but don't fail
            $this->getLog()->debug('TPP: Lock not supported or failed: ' . $response);
        }

        return true;
    }

    /**
     * Unlock a domain to allow transfer
     */
    public function unlock(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Unlocking domain ' . $domain->getName());

        $sessionId = $this->authenticate();

        $params = [
            'SessionID'  => $sessionId,
            'Type'       => 'Domains',
            'Object'     => 'Domain',
            'Action'     => 'UpdateDomainLock',
            'Domain'     => $domain->getName(),
            'DomainLock' => 'Unlock',
        ];

        $url      = self::API_BASE . 'order.pl?' . http_build_query($params);
        $response = $this->httpGet($url);

        if (!str_starts_with($response, 'OK:')) {
            $this->getLog()->debug('TPP: Unlock not supported or failed: ' . $response);
        }

        return true;
    }

    /**
     * Delete a domain — TPP doesn't support direct deletion via API
     * Log the request and return true to avoid breaking FOSSBilling workflow
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Delete requested for ' . $domain->getName()
            . ' — must be completed manually in TPP console');

        return true;
    }

    /**
     * Privacy protection — not supported by TPP Wholesale
     * Return true to avoid breaking FOSSBilling workflow
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Privacy protection not supported for ' . $domain->getName());

        return true;
    }

    /**
     * Privacy protection — not supported by TPP Wholesale
     */
    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('TPP: Privacy protection not supported for ' . $domain->getName());

        return true;
    }

} // End of class
