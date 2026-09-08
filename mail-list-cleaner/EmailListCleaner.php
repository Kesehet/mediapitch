<?php

declare(strict_types=1);

final class EmailListCleaner
{
    private const ROLE_PREFIXES = [
        'abuse', 'accounts', 'admin', 'billing', 'careers', 'compliance', 'contact',
        'enquiries', 'feedback', 'hello', 'hr', 'info', 'jobs', 'legal', 'marketing',
        'media', 'news', 'noreply', 'no-reply', 'office', 'operations', 'postmaster',
        'press', 'privacy', 'reception', 'recruitment', 'sales', 'security', 'subscriptions',
        'support', 'team', 'webmaster'
    ];

    private const DISPOSABLE_DOMAINS = [
        '10minutemail.com', 'emailondeck.com', 'fakeinbox.com', 'getnada.com',
        'grr.la', 'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de',
        'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org',
        'guerrillamailblock.com', 'mailinator.com', 'minuteinbox.com',
        'sharklasers.com', 'temp-mail.org', 'throwawaymail.com', 'trashmail.com',
        'yopmail.com', 'yopmail.fr', 'yopmail.net'
    ];

    private const COMMON_DOMAIN_TYPOS = [
        'gamil.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'hotmai.com' => 'hotmail.com',
        'hotmail.co' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'icloud.co' => 'icloud.com',
        'outllook.com' => 'outlook.com',
        'outlok.com' => 'outlook.com',
        'protonmail.co' => 'protonmail.com',
        'protonmil.com' => 'protonmail.com',
        'yahho.com' => 'yahoo.com',
        'yaho.com' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',
    ];

    /** @var array<string,array<string,mixed>> */
    private array $dnsCache = [];

    private ?bool $dnsResolverHealthy = null;

    /**
     * Clean and validate a raw email list.
     *
     * @return array{summary: array<string,int>, rows: array<int,array<string,mixed>>, cleaned: array<int,string>}
     */
    public function clean(string $raw): array
    {
        $items = preg_split('/[\r\n,;]+/', $raw) ?: [];
        return $this->cleanItems($items);
    }

    /**
     * Clean a list that has already been parsed (useful for JSON APIs and CSV imports).
     *
     * @param array<int,mixed> $items
     * @return array{summary: array<string,int>, rows: array<int,array<string,mixed>>, cleaned: array<int,string>}
     */
    public function cleanItems(array $items): array
    {
        $seen = [];
        $rows = [];
        $cleaned = [];

        $summary = [
            'input' => 0,
            'unique' => 0,
            'clean' => 0,
            'risky' => 0,
            'unknown' => 0,
            'invalid' => 0,
            'duplicates' => 0,
        ];

        foreach ($items as $item) {
            if (!is_scalar($item) && $item !== null) {
                continue;
            }

            $email = $this->normalize((string)$item);
            if ($email === '') {
                continue;
            }

            $summary['input']++;
            $key = strtolower($email);

            if (isset($seen[$key])) {
                $summary['duplicates']++;
                continue;
            }

            $seen[$key] = true;
            $summary['unique']++;

            $row = $this->inspect($email);
            $rows[] = $row;

            $status = (string)$row['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['unknown']++;
            }

            if ($status === 'clean') {
                $cleaned[] = (string)$row['email'];
            }
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
            'cleaned' => $cleaned,
        ];
    }

    /** @return array<string,mixed> */
    public function inspectAddress(string $value): array
    {
        return $this->inspect($this->normalize($value));
    }

    /**
     * Extract a usable address from common copied forms such as
     * "Jane Doe <jane@example.com>" or "mailto:jane@example.com".
     */
    public function extractAddress(string $value): string
    {
        return $this->normalize($value);
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/^mailto:\s*/i', '', $value) ?? $value;

        if (preg_match('/<\s*([^<>]+@[^<>]+)\s*>/', $value, $match) === 1) {
            $value = trim($match[1]);
        } elseif (preg_match('/([A-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Z0-9._-]+\.[A-Z0-9-]{2,})/iu', $value, $match) === 1) {
            $value = trim($match[1]);
        }

        return trim($value, " \t\n\r\0\x0B");
    }

    /** @return array<string,mixed> */
    private function inspect(string $email): array
    {
        $row = [
            'email' => $email,
            'status' => 'invalid',
            'reason' => '',
            'syntax' => false,
            'domain' => '',
            'mx' => false,
            'mail_routing' => 'None',
            'role' => false,
            'disposable' => false,
            'suggestion' => null,
            'flags' => [],
        ];

        if ($email === '' || !str_contains($email, '@')) {
            $row['reason'] = 'Invalid email syntax';
            return $row;
        }

        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            $row['reason'] = 'Invalid email syntax';
            return $row;
        }

        $local = substr($email, 0, $at);
        $domain = strtolower(rtrim(substr($email, $at + 1), '.'));

        if ($domain === '') {
            $row['reason'] = 'Missing domain';
            return $row;
        }

        $asciiDomain = $this->asciiDomain($domain);
        if ($asciiDomain === null) {
            $row['status'] = 'unknown';
            $row['domain'] = $domain;
            $row['mail_routing'] = 'Unknown';
            $row['reason'] = 'Internationalized domain could not be converted on this server';
            return $row;
        }

        $domain = $asciiDomain;
        $email = $local . '@' . $domain;
        $row['email'] = $email;
        $row['domain'] = $domain;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $row['reason'] = 'Invalid email syntax';
            return $row;
        }

        $row['syntax'] = true;

        $typoSuggestion = self::COMMON_DOMAIN_TYPOS[$domain] ?? null;
        if ($typoSuggestion !== null) {
            $row['suggestion'] = $typoSuggestion;
        }

        $dns = $this->resolveDomain($domain);
        $row['mx'] = (bool)$dns['mx'];
        $row['mail_routing'] = (string)$dns['routing'];

        if ($dns['status'] === 'unknown') {
            $row['status'] = 'unknown';
            $row['reason'] = (string)$dns['reason'];
            if ($typoSuggestion !== null) {
                $row['reason'] .= '; possible domain typo — did you mean ' . $typoSuggestion . '?';
            }
            return $row;
        }

        if ($dns['status'] === 'invalid') {
            $row['reason'] = (string)$dns['reason'];
            if ($typoSuggestion !== null) {
                $row['reason'] .= '; possible domain typo — did you mean ' . $typoSuggestion . '?';
                $row['flags'] = ['Possible domain typo'];
            }
            return $row;
        }

        $flags = [];
        $localLower = strtolower($local);
        $rolePrefix = explode('+', $localLower, 2)[0];
        $row['role'] = in_array($rolePrefix, self::ROLE_PREFIXES, true);
        if ($row['role']) {
            $flags[] = 'Role-based address';
        }

        $row['disposable'] = $this->isDisposableDomain($domain);
        if ($row['disposable']) {
            $flags[] = 'Disposable or temporary email domain';
        }

        if ($typoSuggestion !== null) {
            $flags[] = 'Possible domain typo — did you mean ' . $typoSuggestion . '?';
        }

        $row['flags'] = $flags;

        if ($flags !== []) {
            $row['status'] = 'risky';
            $row['reason'] = implode('; ', $flags);
            return $row;
        }

        $row['status'] = 'clean';
        $row['reason'] = $dns['routing'] === 'MX'
            ? 'Syntax and mail-routing checks passed'
            : 'Syntax passed; domain uses A/AAAA mail fallback';

        return $row;
    }

    private function asciiDomain(string $domain): ?string
    {
        if (preg_match('/[^\x20-\x7E]/', $domain) !== 1) {
            return strtolower($domain);
        }

        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        if (defined('INTL_IDNA_VARIANT_UTS46')) {
            $converted = @idn_to_ascii($domain, $flags, INTL_IDNA_VARIANT_UTS46);
        } else {
            $converted = @idn_to_ascii($domain, $flags);
        }

        if (!is_string($converted) || $converted === '') {
            return null;
        }

        return strtolower($converted);
    }

    private function isDisposableDomain(string $domain): bool
    {
        foreach (self::DISPOSABLE_DOMAINS as $disposable) {
            if ($domain === $disposable || str_ends_with($domain, '.' . $disposable)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{status:string,reason:string,mx:bool,routing:string} */
    private function resolveDomain(string $domain): array
    {
        if (isset($this->dnsCache[$domain])) {
            /** @var array{status:string,reason:string,mx:bool,routing:string} */
            return $this->dnsCache[$domain];
        }

        if (!function_exists('checkdnsrr')) {
            return $this->dnsCache[$domain] = [
                'status' => 'unknown',
                'reason' => 'DNS validation is unavailable on this server',
                'mx' => false,
                'routing' => 'Unknown',
            ];
        }

        $mxHosts = [];
        $mxWeights = [];
        $mxLookupWorked = false;

        if (function_exists('getmxrr')) {
            $mxLookupWorked = @getmxrr($domain, $mxHosts, $mxWeights);
        } elseif (function_exists('dns_get_record') && defined('DNS_MX')) {
            $records = @dns_get_record($domain, DNS_MX);
            if (is_array($records)) {
                $mxLookupWorked = $records !== [];
                foreach ($records as $record) {
                    if (isset($record['target'])) {
                        $mxHosts[] = (string)$record['target'];
                    }
                }
            }
        }

        $hasUsableMx = false;
        $hasNullMx = false;

        if ($mxLookupWorked || $mxHosts !== []) {
            foreach ($mxHosts as $mxHost) {
                // RFC 7505: a single "." MX target explicitly says the domain accepts no mail.
                $normalizedMxHost = rtrim(trim((string)$mxHost), '.');
                if ($normalizedMxHost === '') {
                    $hasNullMx = true;
                    continue;
                }
                $hasUsableMx = true;
            }
        }

        if ($hasNullMx) {
            return $this->dnsCache[$domain] = [
                'status' => 'invalid',
                'reason' => 'Domain explicitly does not accept email (Null MX)',
                'mx' => false,
                'routing' => 'Null MX',
            ];
        }

        if ($hasUsableMx) {
            return $this->dnsCache[$domain] = [
                'status' => 'ok',
                'reason' => 'Usable MX record found',
                'mx' => true,
                'routing' => 'MX',
            ];
        }

        // RFC 5321 implicit-MX fallback applies only when the domain has no MX record.
        $hasA = @checkdnsrr($domain, 'A');
        $hasAAAA = @checkdnsrr($domain, 'AAAA');
        if ($hasA || $hasAAAA) {
            return $this->dnsCache[$domain] = [
                'status' => 'ok',
                'reason' => 'Domain uses A/AAAA mail fallback',
                'mx' => false,
                'routing' => 'A/AAAA fallback',
            ];
        }

        // If the domain itself exists but has no address/mail route, report that precisely.
        $domainExists = @checkdnsrr($domain, 'NS') || @checkdnsrr($domain, 'SOA');
        if ($domainExists) {
            return $this->dnsCache[$domain] = [
                'status' => 'invalid',
                'reason' => 'Domain exists but has no usable MX, A, or AAAA mail route',
                'mx' => false,
                'routing' => 'None',
            ];
        }

        // Distinguish a genuinely nonexistent domain from a temporary/system DNS outage.
        // The sentinel lookup is cached for the lifetime of this cleaner instance.
        if (!$this->dnsResolverIsHealthy()) {
            return $this->dnsCache[$domain] = [
                'status' => 'unknown',
                'reason' => 'DNS resolver is temporarily unavailable',
                'mx' => false,
                'routing' => 'Unknown',
            ];
        }

        return $this->dnsCache[$domain] = [
            'status' => 'invalid',
            'reason' => 'Domain does not resolve to a usable mail route',
            'mx' => false,
            'routing' => 'None',
        ];
    }

    private function dnsResolverIsHealthy(): bool
    {
        if ($this->dnsResolverHealthy !== null) {
            return $this->dnsResolverHealthy;
        }

        if (!function_exists('checkdnsrr')) {
            return $this->dnsResolverHealthy = false;
        }

        // example.com is a stable, reserved domain with DNS records. Its Null MX is irrelevant
        // here because this health check only asks whether normal DNS resolution is working.
        return $this->dnsResolverHealthy = (
            @checkdnsrr('example.com', 'A')
            || @checkdnsrr('example.com', 'AAAA')
            || @checkdnsrr('example.com', 'NS')
        );
    }
}
